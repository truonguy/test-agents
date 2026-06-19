<?php

namespace App\Repositories\Eloquent;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Repositories\Contracts\PaymentRepositoryInterface;

class PaymentRepository implements PaymentRepositoryInterface
{
    public function create(array $data): Payment
    {
        return Payment::create($data);
    }

    public function findByProviderRef(string $ref): ?Payment
    {
        return PaymentAttempt::where('provider_txn_ref', $ref)->first()?->payment;
    }
}
