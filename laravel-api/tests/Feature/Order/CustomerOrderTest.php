<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOrderTest extends TestCase
{
    use RefreshDatabase;

    private function token(Customer $customer): string
    {
        return $customer->createToken('shop')->plainTextToken;
    }

    public function test_customer_sees_only_own_orders(): void
    {
        $me = Customer::factory()->create();
        Order::factory()->count(2)->create(['customer_id' => $me->id]);
        Order::factory()->create(); // người khác

        $res = $this->withToken($this->token($me))->getJson('/api/orders')->assertOk()
            ->assertJsonStructure(['data', 'meta']);
        $this->assertCount(2, $res->json('data'));
    }

    public function test_detail_of_own_order_with_items(): void
    {
        $me = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $me->id]);
        OrderItem::factory()->count(2)->create(['order_id' => $order->id]);

        $this->withToken($this->token($me))->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJson(['id' => $order->id])
            ->assertJsonCount(2, 'items');
    }

    public function test_cannot_view_others_order(): void
    {
        $me = Customer::factory()->create();
        $other = Order::factory()->create();

        $this->withToken($this->token($me))->getJson("/api/orders/{$other->id}")->assertNotFound();
    }

    public function test_customer_can_cancel_own_pending_order_and_release_stock(): void
    {
        $me = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $me->id, 'status' => OrderStatus::PENDING]);
        $variant = ProductVariant::factory()->create();
        Inventory::factory()->create(['product_variant_id' => $variant->id, 'available_stock' => 7, 'reserved_stock' => 3]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_variant_id' => $variant->id, 'quantity' => 3]);

        $this->withToken($this->token($me))->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk()->assertJson(['status' => OrderStatus::CANCELLED->value]);

        // release: available += 3, reserved -= 3
        $this->assertDatabaseHas('inventories', [
            'product_variant_id' => $variant->id, 'available_stock' => 10, 'reserved_stock' => 0,
        ]);
    }

    public function test_cannot_cancel_non_pending_order(): void
    {
        $me = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $me->id, 'status' => OrderStatus::CONFIRMED]);

        $this->withToken($this->token($me))->postJson("/api/orders/{$order->id}/cancel")->assertStatus(422);
        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
    }

    public function test_cannot_cancel_others_order(): void
    {
        $me = Customer::factory()->create();
        $other = Order::factory()->create(['status' => OrderStatus::PENDING]);

        $this->withToken($this->token($me))->postJson("/api/orders/{$other->id}/cancel")->assertNotFound();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/orders')->assertUnauthorized();
    }
}
