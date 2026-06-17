<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $price = fake()->randomFloat(2, 1, 500);
        $qty = fake()->numberBetween(1, 5);

        return [
            'order_id' => Order::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'product_name' => fake()->words(3, true),
            'sku' => 'SKU-'.fake()->unique()->numberBetween(1, 1_000_000),
            'unit_price' => $price,
            'quantity' => $qty,
            'line_total' => round($price * $qty, 2),
        ];
    }
}
