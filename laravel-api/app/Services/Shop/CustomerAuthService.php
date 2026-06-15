<?php

namespace App\Services\Shop;

use App\Enums\UserStatus;
use App\Exceptions\AccountNotActiveException;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;

class CustomerAuthService
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
    ) {}

    /**
     * Đăng nhập customer (phân hệ Shop).
     *
     * @return array{access_token: string, type: string}
     *
     * @throws AuthenticationException sai email/mật khẩu (generic, không lộ tồn tại)
     * @throws AccountNotActiveException status != ACTIVE
     */
    public function login(string $email, string $password): array
    {
        $customer = $this->customers->findByEmail($email);

        if (! $customer || ! Hash::check($password, $customer->password)) {
            throw new AuthenticationException('Invalid credentials.');
        }

        if ($customer->status !== UserStatus::ACTIVE) {
            throw new AccountNotActiveException;
        }

        return [
            'access_token' => $customer->createToken('shop')->plainTextToken,
            'type' => 'customer',
        ];
    }
}
