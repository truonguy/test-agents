<?php

namespace App\Console\Commands;

use App\Services\Payment\PaymentReconciler;
use Illuminate\Console\Command;

class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Đối soát payment treo: query gateway / EXPIRED quá timeout';

    public function handle(PaymentReconciler $reconciler): int
    {
        $count = $reconciler->reconcile();

        $this->info("Reconciled {$count} payment(s).");

        return self::SUCCESS;
    }
}
