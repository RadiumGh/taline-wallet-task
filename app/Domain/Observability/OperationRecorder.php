<?php

declare(strict_types=1);

namespace App\Domain\Observability;

use App\Models\Deposit;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

final class OperationRecorder
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function depositCreated(Deposit $deposit, ?User $actor = null): void
    {
        $context = $this->depositContext($deposit);
        $this->audit->record('deposit.created', $deposit, $context, $actor);
        $this->metrics->increment('deposit.created');
        $this->log('deposit.created', $context);
    }

    public function depositConfirmed(Deposit $deposit): void
    {
        $context = $this->depositContext($deposit);
        $this->audit->record('deposit.confirmed', $deposit, $context);
        $this->metrics->increment('deposit.confirmed');
        $this->metrics->histogram('deposit.volume', (float) $deposit->amount->amount, ['currency' => $deposit->amount->currency->code]);
        $this->log('deposit.confirmed', $context);
    }

    public function depositFailed(Deposit $deposit): void
    {
        $context = $this->depositContext($deposit);
        $this->audit->record('deposit.failed', $deposit, $context);
        $this->metrics->increment('deposit.failed');
        $this->log('deposit.failed', $context);
    }

    public function transferCompleted(Transfer $transfer, ?User $actor = null): void
    {
        $context = $this->transferContext($transfer);
        $this->audit->record('transfer.completed', $transfer, $context, $actor);
        $this->metrics->increment('transfer.completed');
        $this->metrics->histogram('transfer.volume', (float) $transfer->amount->amount, ['currency' => $transfer->amount->currency->code]);
        $this->log('transfer.completed', $context);
    }

    public function withdrawalRequested(Withdrawal $withdrawal, ?User $actor = null): void
    {
        $context = $this->withdrawalContext($withdrawal);
        $this->audit->record('withdrawal.requested', $withdrawal, $context, $actor);
        $this->metrics->increment('withdrawal.requested');
        $this->metrics->histogram('withdrawal.volume', (float) $withdrawal->amount->amount, ['currency' => $withdrawal->amount->currency->code]);
        $this->log('withdrawal.requested', $context);
    }

    public function withdrawalApproved(Withdrawal $withdrawal, ?User $actor = null): void
    {
        $context = $this->withdrawalContext($withdrawal);
        $this->audit->record('withdrawal.approved', $withdrawal, $context, $actor);
        $this->metrics->increment('withdrawal.approved');
        $this->log('withdrawal.approved', $context);
    }

    public function withdrawalSettled(Withdrawal $withdrawal, ?User $actor = null): void
    {
        $context = $this->withdrawalContext($withdrawal);
        $this->audit->record('withdrawal.settled', $withdrawal, $context, $actor);
        $this->metrics->increment('withdrawal.settled');
        $this->log('withdrawal.settled', $context);
    }

    public function withdrawalRejected(Withdrawal $withdrawal, ?User $actor = null): void
    {
        $context = $this->withdrawalContext($withdrawal);
        $this->audit->record('withdrawal.rejected', $withdrawal, $context, $actor);
        $this->metrics->increment('withdrawal.rejected');
        $this->log('withdrawal.rejected', $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function depositContext(Deposit $deposit): array
    {
        return [
            'reference' => $deposit->reference,
            'wallet_id' => $deposit->wallet_id,
            'amount' => $deposit->amount->amount,
            'currency' => $deposit->amount->currency->code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transferContext(Transfer $transfer): array
    {
        return [
            'reference' => $transfer->reference,
            'from_wallet_id' => $transfer->from_wallet_id,
            'to_wallet_id' => $transfer->to_wallet_id,
            'amount' => $transfer->amount->amount,
            'currency' => $transfer->amount->currency->code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function withdrawalContext(Withdrawal $withdrawal): array
    {
        return [
            'reference' => $withdrawal->reference,
            'wallet_id' => $withdrawal->wallet_id,
            'amount' => $withdrawal->amount->amount,
            'currency' => $withdrawal->amount->currency->code,
            'status' => $withdrawal->status->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $action, array $context): void
    {
        Log::info($action, ['request_id' => Context::get('request_id'), ...$context]);
    }
}
