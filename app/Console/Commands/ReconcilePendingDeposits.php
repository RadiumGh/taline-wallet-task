<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Deposit\DepositReconciliationService;
use Illuminate\Console\Command;

class ReconcilePendingDeposits extends Command
{
    protected $signature = 'deposits:reconcile';

    protected $description = 'Resolve deposits stuck pending beyond the threshold against the payment gateway.';

    public function handle(DepositReconciliationService $service): int
    {
        $report = $service->reconcile(
            (int) config('wallet.deposit.reconcile_after_minutes'),
            (int) config('wallet.deposit.reconcile_batch_size'),
        );

        $this->info("Reconciled deposits: {$report->confirmed} confirmed, {$report->failed} failed, {$report->skipped} still pending.");

        return self::SUCCESS;
    }
}
