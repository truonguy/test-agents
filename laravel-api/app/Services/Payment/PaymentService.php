<?php

namespace App\Services\Payment;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\Order\OrderService;
use App\Services\Payment\Gateways\GatewayManager;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly GatewayManager $gateways,
        private readonly PaymentStateMachine $stateMachine,
        private readonly OrderService $orders,
    ) {}

    /**
     * Tạo payment cho order (PENDING). COD → SUCCESS + confirm order ngay; VNPAY → PROCESSING + payment_url.
     *
     * @return array{payment: Payment, payment_url: string|null}
     */
    public function createForOrder(Order $order, PaymentMethod $method): array
    {
        return DB::transaction(function () use ($order, $method) {
            $gateway = $this->gateways->for($method);

            $payment = $this->payments->create([
                'order_id' => $order->id,
                'method' => $method->value,
                'gateway' => $gateway->name(),
                'amount' => (int) round((float) $order->total),
                'status' => PaymentStatus::PENDING->value,
            ]);

            $result = $gateway->create($payment);

            $attempt = $payment->attempts()->create([
                'provider_txn_ref' => $result['ref'],
                'status' => PaymentStatus::PENDING->value,
            ]);

            $this->applyStatus($payment, 'start'); // PENDING → PROCESSING

            if ($method === PaymentMethod::COD) {
                $this->applyStatus($payment, 'success'); // PROCESSING → SUCCESS
                $attempt->update(['status' => PaymentStatus::SUCCESS->value]);
                $this->orders->transition($order, 'confirm'); // PENDING → CONFIRMED
            } else {
                $attempt->update(['status' => PaymentStatus::PROCESSING->value]);
            }

            return [
                'payment' => $payment->load('attempts'),
                'payment_url' => $result['url'],
            ];
        });
    }

    private function applyStatus(Payment $payment, string $action): void
    {
        $target = $this->stateMachine->target($payment->status, $action);
        $payment->update(['status' => $target->value]);
    }
}
