<?php

namespace App\Services\Payment;

use App\Enums\PaymentStatus;
use App\Models\Payment;

/**
 * Đối soát payment treo (eventual consistency): payment PENDING/PROCESSING quá timeout
 * → query gateway / EXPIRED. Idempotent, chạy lại an toàn (spec §8 / OQ §0.6-0.7).
 */
class PaymentReconciler
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * @return int số payment đã xử lý
     */
    public function reconcile(): int
    {
        $cutoff = now()->subMinutes((int) config('payment.timeout_minutes'));

        $due = Payment::query()
            ->whereIn('status', [PaymentStatus::PENDING->value, PaymentStatus::PROCESSING->value])
            ->where('updated_at', '<=', $cutoff)
            ->get();

        foreach ($due as $payment) {
            $this->payments->reconcilePayment($payment);
        }

        return $due->count();
    }
}
