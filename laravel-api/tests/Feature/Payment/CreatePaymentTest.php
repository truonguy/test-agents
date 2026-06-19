<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function pendingOrder(Customer $customer, float $total = 150): Order
    {
        return Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::PENDING,
            'total' => $total,
        ]);
    }

    private function token(Customer $customer): string
    {
        return $customer->createToken('shop')->plainTextToken;
    }

    public function test_cod_payment_confirms_order_immediately(): void
    {
        $customer = Customer::factory()->create();
        $order = $this->pendingOrder($customer);

        $res = $this->withToken($this->token($customer))
            ->postJson("/api/orders/{$order->id}/payment", ['method' => 'COD'])
            ->assertCreated()
            ->assertJson(['payment_url' => null]);

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'method' => 'COD', 'status' => PaymentStatus::SUCCESS->value]);
        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
    }

    public function test_vnpay_returns_payment_url_and_keeps_order_pending(): void
    {
        $customer = Customer::factory()->create();
        $order = $this->pendingOrder($customer);

        $res = $this->withToken($this->token($customer))
            ->postJson("/api/orders/{$order->id}/payment", ['method' => 'VNPAY'])
            ->assertCreated();

        $this->assertStringContainsString('vnp_SecureHash', $res->json('payment_url'));
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'method' => 'VNPAY', 'status' => PaymentStatus::PROCESSING->value]);
        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
    }

    public function test_amount_snapshots_order_total(): void
    {
        $customer = Customer::factory()->create();
        $order = $this->pendingOrder($customer, 123);

        $this->withToken($this->token($customer))
            ->postJson("/api/orders/{$order->id}/payment", ['method' => 'COD'])->assertCreated();

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'amount' => 123]);
    }

    public function test_only_owner_can_create_payment(): void
    {
        $owner = Customer::factory()->create();
        $order = $this->pendingOrder($owner);
        $otherToken = $this->token(Customer::factory()->create());

        $this->withToken($otherToken)
            ->postJson("/api/orders/{$order->id}/payment", ['method' => 'COD'])->assertNotFound();
    }

    public function test_order_must_be_pending(): void
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::CONFIRMED]);

        $this->withToken($this->token($customer))
            ->postJson("/api/orders/{$order->id}/payment", ['method' => 'COD'])->assertStatus(422);
    }

    public function test_method_must_be_valid(): void
    {
        $customer = Customer::factory()->create();
        $order = $this->pendingOrder($customer);

        $this->withToken($this->token($customer))
            ->postJson("/api/orders/{$order->id}/payment", ['method' => 'BITCOIN'])
            ->assertStatus(422)->assertJsonValidationErrors(['method']);
    }

    public function test_requires_customer(): void
    {
        $customer = Customer::factory()->create();
        $order = $this->pendingOrder($customer);
        $employeeToken = Employee::factory()->create()->createToken('crm')->plainTextToken;

        $this->withToken($employeeToken)
            ->postJson("/api/orders/{$order->id}/payment", ['method' => 'COD'])->assertUnauthorized();
    }
}
