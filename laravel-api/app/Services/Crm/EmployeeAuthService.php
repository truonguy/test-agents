<?php

namespace App\Services\Crm;

use App\Enums\UserStatus;
use App\Exceptions\AccountNotActiveException;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Services\AuditLogger;
use App\Services\Auth\AccountLockout;
use App\Services\Auth\LoginThrottle;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;

class EmployeeAuthService
{
    private const GUARD = 'employee';

    public function __construct(
        private readonly EmployeeRepositoryInterface $employees,
        private readonly AuditLogger $audit,
        private readonly LoginThrottle $throttle,
        private readonly AccountLockout $lockout,
    ) {}

    /**
     * Đăng nhập employee/admin (phân hệ CRM). Ghi audit log mọi lần (success/fail).
     *
     * @return array{access_token: string, type: string, role: string|null}
     *
     * @throws ThrottleRequestsException vượt rate limit (429)
     * @throws AuthenticationException sai email/mật khẩu (generic 401)
     * @throws AccountNotActiveException status != ACTIVE (403)
     */
    public function login(string $email, string $password, ?string $ip = null, ?string $userAgent = null): array
    {
        $this->throttle->ensureNotThrottled($email, $ip);

        $employee = $this->employees->findByEmail($email);

        // Tài khoản tồn tại nhưng không ACTIVE (kể cả LOCKED) → 403, kể cả khi pass đúng.
        if ($employee && $employee->status !== UserStatus::ACTIVE) {
            $this->log($email, AuditLogger::RESULT_FAIL, $ip, $userAgent);
            throw new AccountNotActiveException;
        }

        if (! $employee || ! Hash::check($password, $employee->password)) {
            $this->throttle->recordFailure($email, $ip);
            if ($employee) {
                $this->lockout->recordFailure($employee);
            }
            $this->log($email, AuditLogger::RESULT_FAIL, $ip, $userAgent);
            throw new AuthenticationException('Invalid credentials.');
        }

        $this->throttle->clear($email, $ip);
        $this->lockout->reset($employee);

        $token = $employee->createToken('crm')->plainTextToken;

        $this->log($email, AuditLogger::RESULT_SUCCESS, $ip, $userAgent);

        return [
            'access_token' => $token,
            'type' => 'employee',
            'role' => $employee->getRoleNames()->first(),
        ];
    }

    private function log(?string $email, string $result, ?string $ip, ?string $userAgent): void
    {
        $this->audit->record(self::GUARD, $email, AuditLogger::ACTION_LOGIN, $result, $ip, $userAgent);
    }
}
