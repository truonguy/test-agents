<?php

namespace App\Services\Shop;

use App\Enums\UserStatus;
use App\Exceptions\AccountNotActiveException;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Services\Auth\AccountLockout;
use App\Services\Auth\LoginThrottle;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;

class CustomerAuthService
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly LoginThrottle $throttle,
        private readonly AccountLockout $lockout,
    ) {}

    /**
     * Đăng nhập customer (phân hệ Shop).
     *
     * @return array{access_token: string, type: string}
     *
     * @throws ThrottleRequestsException vượt rate limit (429)
     * @throws AuthenticationException sai email/mật khẩu (generic 401)
     * @throws AccountNotActiveException status != ACTIVE (403)
     */
    public function login(string $email, string $password, ?string $ip = null): array
    {
        $this->throttle->ensureNotThrottled($email, $ip);

        $customer = $this->customers->findByEmail($email);

        // Tài khoản tồn tại nhưng không ACTIVE (kể cả LOCKED) → 403, kể cả khi pass đúng.
        if ($customer && $customer->status !== UserStatus::ACTIVE) {
            throw new AccountNotActiveException;
        }

        if (! $customer || ! Hash::check($password, $customer->password)) {
            $this->throttle->recordFailure($email, $ip);
            if ($customer) {
                $this->lockout->recordFailure($customer);
            }
            throw new AuthenticationException('Invalid credentials.');
        }

        $this->throttle->clear($email, $ip);
        $this->lockout->reset($customer);

        return [
            'access_token' => $customer->createToken('shop')->plainTextToken,
            'type' => 'customer',
        ];
    }

    /**
     * Đăng ký customer mới (status=ACTIVE, cấp token ngay — chưa bắt buộc verify email).
     *
     * @param  array{name: string, email: string, password: string}  $data
     * @return array{access_token: string, type: string}
     */
    public function register(array $data): array
    {
        $customer = $this->customers->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        return [
            'access_token' => $customer->createToken('shop')->plainTextToken,
            'type' => 'customer',
        ];
    }
}
