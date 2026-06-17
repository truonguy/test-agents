<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Transition order không hợp lệ theo state machine → 422.
 */
class InvalidOrderTransitionException extends UnprocessableEntityHttpException
{
    public function __construct(string $message = 'Invalid order status transition.')
    {
        parent::__construct($message);
    }
}
