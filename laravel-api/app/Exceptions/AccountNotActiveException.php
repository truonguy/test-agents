<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tài khoản tồn tại & mật khẩu đúng nhưng status != ACTIVE (INACTIVE/LOCKED) → 403.
 */
class AccountNotActiveException extends AccessDeniedHttpException
{
    public function __construct(string $message = 'Account is not active.')
    {
        parent::__construct($message);
    }
}
