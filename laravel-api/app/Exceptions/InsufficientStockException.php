<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Không đủ tồn kho để reserve khi checkout → 422 (sẽ rollback transaction).
 */
class InsufficientStockException extends UnprocessableEntityHttpException
{
    public function __construct(string $message = 'Insufficient stock for one or more items.')
    {
        parent::__construct($message);
    }
}
