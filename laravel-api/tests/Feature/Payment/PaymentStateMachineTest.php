<?php

namespace Tests\Feature\Payment;

use App\Enums\PaymentStatus;
use App\Exceptions\InvalidPaymentTransitionException;
use App\Services\Payment\PaymentStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentStateMachineTest extends TestCase
{
    private PaymentStateMachine $sm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sm = new PaymentStateMachine;
    }

    public static function validTransitions(): array
    {
        return [
            ['start', PaymentStatus::PENDING, PaymentStatus::PROCESSING],
            ['success', PaymentStatus::PROCESSING, PaymentStatus::SUCCESS],
            ['fail', PaymentStatus::PROCESSING, PaymentStatus::FAILED],
            ['expire', PaymentStatus::PENDING, PaymentStatus::EXPIRED],
            ['expire', PaymentStatus::PROCESSING, PaymentStatus::EXPIRED],
        ];
    }

    #[DataProvider('validTransitions')]
    public function test_valid_transitions(string $action, PaymentStatus $from, PaymentStatus $to): void
    {
        $this->assertTrue($this->sm->canApply($from, $action));
        $this->assertSame($to, $this->sm->target($from, $action));
    }

    public static function invalidTransitions(): array
    {
        return [
            ['success', PaymentStatus::PENDING],
            ['fail', PaymentStatus::PENDING],
            ['start', PaymentStatus::PROCESSING],
            ['start', PaymentStatus::SUCCESS],
            ['success', PaymentStatus::SUCCESS],
            ['expire', PaymentStatus::SUCCESS],
            ['success', PaymentStatus::FAILED],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transitions_throw(string $action, PaymentStatus $from): void
    {
        $this->assertFalse($this->sm->canApply($from, $action));

        $this->expectException(InvalidPaymentTransitionException::class);
        $this->sm->target($from, $action);
    }
}
