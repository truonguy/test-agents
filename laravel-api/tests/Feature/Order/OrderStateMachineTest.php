<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Services\Order\OrderStateMachine;
use Tests\TestCase;

class OrderStateMachineTest extends TestCase
{
    private OrderStateMachine $sm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sm = new OrderStateMachine;
    }

    public static function validTransitions(): array
    {
        return [
            ['confirm', OrderStatus::PENDING, OrderStatus::CONFIRMED],
            ['pack', OrderStatus::CONFIRMED, OrderStatus::PACKING],
            ['ship', OrderStatus::PACKING, OrderStatus::SHIPPING],
            ['complete', OrderStatus::SHIPPING, OrderStatus::DELIVERED],
            ['cancel', OrderStatus::PENDING, OrderStatus::CANCELLED],
            ['cancel', OrderStatus::CONFIRMED, OrderStatus::CANCELLED],
            ['cancel', OrderStatus::PACKING, OrderStatus::CANCELLED],
        ];
    }

    /**
     * @dataProvider validTransitions
     */
    public function test_valid_transitions(string $action, OrderStatus $from, OrderStatus $to): void
    {
        $this->assertTrue($this->sm->canApply($from, $action));
        $this->assertSame($to, $this->sm->target($from, $action));
    }

    public static function invalidTransitions(): array
    {
        return [
            ['complete', OrderStatus::PENDING],
            ['ship', OrderStatus::PENDING],
            ['cancel', OrderStatus::SHIPPING],
            ['cancel', OrderStatus::DELIVERED],
            ['confirm', OrderStatus::CONFIRMED],
            ['pack', OrderStatus::DELIVERED],
            ['confirm', OrderStatus::CANCELLED],
        ];
    }

    /**
     * @dataProvider invalidTransitions
     */
    public function test_invalid_transitions_throw(string $action, OrderStatus $from): void
    {
        $this->assertFalse($this->sm->canApply($from, $action));

        $this->expectException(InvalidOrderTransitionException::class);
        $this->sm->target($from, $action);
    }
}
