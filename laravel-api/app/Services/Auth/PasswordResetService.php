<?php

namespace App\Services\Auth;

use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Contracts\HasApiTokens;

/**
 * Quên/đặt lại mật khẩu dùng password broker theo phân hệ (customers/employees),
 * tách biệt hoàn toàn (bảng reset token riêng). Sau khi reset → revoke toàn bộ token Sanctum.
 */
class PasswordResetService
{
    /**
     * Gửi link reset. Luôn "im lặng" về kết quả để chống user-enumeration (AC-04.1/04.2).
     */
    public function sendResetLink(string $broker, string $email): void
    {
        Password::broker($broker)->sendResetLink(['email' => $email]);
    }

    /**
     * Đặt lại mật khẩu. Trả true nếu thành công, false nếu token/email không hợp lệ.
     *
     * @param  array{email: string, password: string, password_confirmation: string, token: string}  $credentials
     */
    public function reset(string $broker, array $credentials): bool
    {
        $status = Password::broker($broker)->reset(
            $credentials,
            function (CanResetPassword $user, string $password): void {
                /** @var Model&HasApiTokens $user */
                $user->forceFill(['password' => Hash::make($password)])->save();

                // Revoke toàn bộ token hiện có (buộc đăng nhập lại sau khi đổi mật khẩu).
                $user->tokens()->delete();
            }
        );

        return $status === Password::PASSWORD_RESET;
    }
}
