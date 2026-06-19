<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\Payment\Gateways\VnpayAdapter;
use App\Services\Payment\PaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payment.timeout_minutes' => 15, 'payment.vnpay.secret' => 'sekret']);
    }

    private function processingPayment(int $ageMinutes, OrderStatus $orderStatus = OrderStatus::PENDING): Payment
    {
        $order = Order::factory()->create(['status' => $orderStatus]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'method' => PaymentMethod::VNPAY,
            'gateway' => 'vnpay',
            'status' => PaymentStatus::PROCESSING,
        ]);
        PaymentAttempt::factory()->create([
            'payment_id' => $payment->id,
            'provider_txn_ref' => 'VNP-'.$payment->id,
            'status' => PaymentStatus::PROCESSING,
        ]);
        // đặt updated_at vào quá khứ (không chạm timestamps qua query builder)
        Payment::where('id', $payment->id)->update(['updated_at' => now()->subMinutes($ageMinutes)]);

        return $payment->fresh();
    }

    private function reconciler(): PaymentReconciler
    {
        return app(PaymentReconciler::class);
    }

    public function test_stale_processing_payment_expires(): void
    {
        $payment = $this->processingPayment(20); // quá 15'

        $this->reconciler()->reconcile();

        $this->assertSame(PaymentStatus::EXPIRED, $payment->fresh()->status);
    }

    public function test_recent_processing_payment_not_expired(): void
    {
        $payment = $this->processingPayment(5); // chưa quá hạn

        $this->reconciler()->reconcile();

        $this->assertSame(PaymentStatus::PROCESSING, $payment->fresh()->status);
    }

    public function test_terminal_payment_untouched(): void
    {
        $payment = Payment::factory()->status(PaymentStatus::SUCCESS)->create();
        Payment::where('id', $payment->id)->update(['updated_at' => now()->subMinutes(60)]);

        $this->reconciler()->reconcile();

        $this->assertSame(PaymentStatus::SUCCESS, $payment->fresh()->status);
    }

    public function test_query_returns_success_confirms_order(): void
    {
        // Fake adapter: query() trả SUCCESS
        $this->app->bind(VnpayAdapter::class, fn () => new class extends VnpayAdapter
        {
            public function query(string $ref): PaymentStatus
            {
                return PaymentStatus::SUCCESS;
            }
        });

        $payment = $this->processingPayment(20);

        $this->reconciler()->reconcile();

        $this->assertSame(PaymentStatus::SUCCESS, $payment->fresh()->status);
        $this->assertSame(OrderStatus::CONFIRMED, $payment->order->fresh()->status);
    }

    public function test_artisan_command_runs(): void
    {
        $payment = $this->processingPayment(20);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(PaymentStatus::EXPIRED, $payment->fresh()->status);
    }
}
