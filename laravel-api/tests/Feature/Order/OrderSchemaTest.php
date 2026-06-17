<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('orders', [
            'id', 'customer_id', 'status', 'total', 'idempotency_key',
            'recipient_name', 'recipient_phone', 'shipping_address',
        ]));
        $this->assertTrue(Schema::hasColumns('order_items', [
            'id', 'order_id', 'product_variant_id', 'product_name', 'sku', 'unit_price', 'quantity', 'line_total',
        ]));
    }

    public function test_status_is_cast_to_enum(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::PENDING]);
        $this->assertInstanceOf(OrderStatus::class, $order->status);
        $this->assertSame(OrderStatus::PENDING, $order->status);
    }

    public function test_relationships(): void
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $item = OrderItem::factory()->create(['order_id' => $order->id]);

        $this->assertTrue($order->customer->is($customer));
        $this->assertTrue($order->items->first()->is($item));
        $this->assertTrue($item->order->is($order));
    }

    public function test_idempotency_key_is_unique(): void
    {
        Order::factory()->create(['idempotency_key' => 'key-1']);

        $this->expectException(QueryException::class);
        Order::factory()->create(['idempotency_key' => 'key-1']);
    }
}
