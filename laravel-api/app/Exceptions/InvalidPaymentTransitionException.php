<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Transition payment không hợp lệ theo state machine → 422.
 */
class InvalidPaymentTransitionException extends UnprocessableEntityHttpException
{
    public function __construct(string $message = 'Invalid payment status transition.')
    {
        parent::__construct($message);
    }
}
