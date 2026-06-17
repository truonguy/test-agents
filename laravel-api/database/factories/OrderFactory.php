<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'status' => OrderStatus::PENDING,
            'total' => fake()->randomFloat(2, 10, 999),
            'idempotency_key' => null,
            'recipient_name' => fake()->name(),
            'recipient_phone' => fake()->numerify('09########'),
            'shipping_address' => fake()->address(),
        ];
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
