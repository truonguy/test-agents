<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransitionException;

/**
 * State machine cho order lifecycle (spec §0):
 * PENDING →(confirm) CONFIRMED →(pack) PACKING →(ship) SHIPPING →(complete) DELIVERED.
 * cancel: PENDING/CONFIRMED/PACKING → CANCELLED. DELIVERED & CANCELLED là terminal.
 */
class OrderStateMachine
{
    /**
     * action => [ from-status-value => target OrderStatus ].
     *
     * @var array<string, array<string, OrderStatus>>
     */
    private const TRANSITIONS = [
        'confirm' => [
            'PENDING' => OrderStatus::CONFIRMED,
        ],
        'pack' => [
            'CONFIRMED' => OrderStatus::PACKING,
        ],
        'ship' => [
            'PACKING' => OrderStatus::SHIPPING,
        ],
        'complete' => [
            'SHIPPING' => OrderStatus::DELIVERED,
        ],
        'cancel' => [
            'PENDING' => OrderStatus::CANCELLED,
            'CONFIRMED' => OrderStatus::CANCELLED,
            'PACKING' => OrderStatus::CANCELLED,
        ],
    ];

    public function canApply(OrderStatus $from, string $action): bool
    {
        return isset(self::TRANSITIONS[$action][$from->value]);
    }

    public function target(OrderStatus $from, string $action): OrderStatus
    {
        if (! $this->canApply($from, $action)) {
            throw new InvalidOrderTransitionException(
                "Cannot '{$action}' an order in status '{$from->value}'."
            );
        }

        return self::TRANSITIONS[$action][$from->value];
    }
}
