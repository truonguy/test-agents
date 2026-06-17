<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function token(?string $role = 'employee'): string
    {
        $employee = Employee::factory()->create();
        if ($role !== null) {
            $employee->assignRole($role);
        }

        return $employee->createToken('crm')->plainTextToken;
    }

    private function orderWithReservedStock(OrderStatus $status, int $qty = 3): array
    {
        $order = Order::factory()->status($status)->create();
        $variant = ProductVariant::factory()->create();
        Inventory::factory()->create(['product_variant_id' => $variant->id, 'available_stock' => 7, 'reserved_stock' => $qty]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_variant_id' => $variant->id, 'quantity' => $qty]);

        return [$order, $variant];
    }

    public function test_employee_can_list_orders(): void
    {
        Order::factory()->count(3)->create();

        $this->withToken($this->token())->getJson('/api/crm/orders')
            ->assertOk()->assertJsonStructure(['data', 'meta']);
    }

    public function test_filter_by_status(): void
    {
        Order::factory()->status(OrderStatus::PENDING)->create();
        Order::factory()->status(OrderStatus::PENDING)->create();
        Order::factory()->status(OrderStatus::DELIVERED)->create();

        $res = $this->withToken($this->token())->getJson('/api/crm/orders?status=PENDING')->assertOk();
        $this->assertCount(2, $res->json('data'));
    }

    public function test_confirm_pending_order(): void
    {
        $order = Order::factory()->status(OrderStatus::PENDING)->create();

        $this->withToken($this->token())->postJson("/api/crm/orders/{$order->id}/confirm")
            ->assertOk()->assertJson(['status' => OrderStatus::CONFIRMED->value]);
    }

    public function test_full_lifecycle_to_delivered_consumes_stock(): void
    {
        [$order, $variant] = $this->orderWithReservedStock(OrderStatus::SHIPPING, 3);

        $this->withToken($this->token())->postJson("/api/crm/orders/{$order->id}/complete")
            ->assertOk()->assertJson(['status' => OrderStatus::DELIVERED->value]);

        // consume: reserved -= 3, available giữ nguyên
        $this->assertDatabaseHas('inventories', [
            'product_variant_id' => $variant->id, 'available_stock' => 7, 'reserved_stock' => 0,
        ]);
    }

    public function test_pack_then_ship_transitions(): void
    {
        $order = Order::factory()->status(OrderStatus::CONFIRMED)->create();
        $token = $this->token();

        $this->withToken($token)->postJson("/api/crm/orders/{$order->id}/pack")->assertJson(['status' => 'PACKING']);
        $this->withToken($token)->postJson("/api/crm/orders/{$order->id}/ship")->assertJson(['status' => 'SHIPPING']);
    }

    public function test_invalid_transition_returns_422(): void
    {
        $order = Order::factory()->status(OrderStatus::PENDING)->create();

        $this->withToken($this->token())->postJson("/api/crm/orders/{$order->id}/complete")
            ->assertStatus(422);
        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
    }

    public function test_cancel_releases_stock(): void
    {
        [$order, $variant] = $this->orderWithReservedStock(OrderStatus::CONFIRMED, 3);

        $this->withToken($this->token())->postJson("/api/crm/orders/{$order->id}/cancel")
            ->assertOk()->assertJson(['status' => OrderStatus::CANCELLED->value]);

        $this->assertDatabaseHas('inventories', [
            'product_variant_id' => $variant->id, 'available_stock' => 10, 'reserved_stock' => 0,
        ]);
    }

    public function test_customer_token_cannot_access(): void
    {
        $order = Order::factory()->create();
        $token = Customer::factory()->create()->createToken('shop')->plainTextToken;

        $this->withToken($token)->getJson('/api/crm/orders')->assertUnauthorized();
        $this->withToken($token)->postJson("/api/crm/orders/{$order->id}/confirm")->assertUnauthorized();
    }

    public function test_employee_without_manage_order_forbidden(): void
    {
        $order = Order::factory()->create();

        $this->withToken($this->token(null))->postJson("/api/crm/orders/{$order->id}/confirm")
            ->assertForbidden();
    }
}
