<?php

declare(strict_types=1);

namespace App\Domain\Withdrawal;

use App\Domain\Ledger\LedgerService;
use App\Domain\Ledger\ValueObjects\LedgerLeg;
use App\Domain\Observability\OperationRecorder;
use App\Domain\Outbox\OutboxRecorder;
use App\Domain\Wallet\SystemAccountResolver;
use App\Domain\Withdrawal\Enums\WithdrawalOutcome;
use App\Domain\Withdrawal\Enums\WithdrawalStatus;
use App\Domain\Withdrawal\Events\WithdrawalEvent;
use App\Domain\Withdrawal\Exceptions\InvalidWithdrawalTransitionException;
use App\Models\User;
use App\Models\Withdrawal;
use Closure;
use Illuminate\Support\Facades\DB;

final class WithdrawalReviewService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly SystemAccountResolver $systemAccounts,
        private readonly OutboxRecorder $outbox,
        private readonly OperationRecorder $recorder,
    ) {}

    public function approve(Withdrawal $withdrawal, User $reviewer): WithdrawalOutcome
    {
        return $this->transition($withdrawal, WithdrawalStatus::Approved, $reviewer, function (Withdrawal $locked) use ($reviewer): void {
            $locked->approved_at = now();

            $this->outbox->record($locked, 'withdrawal.approved', "withdrawal.approved:{$locked->getKey()}", $this->payload($locked));
            $this->recorder->record(WithdrawalEvent::approved($locked, $reviewer));
        });
    }

    public function settle(Withdrawal $withdrawal, User $reviewer): WithdrawalOutcome
    {
        return $this->transition($withdrawal, WithdrawalStatus::Settled, $reviewer, function (Withdrawal $locked) use ($reviewer): void {
            $clearing = $this->systemAccounts->resolve(config('wallet.system_accounts.withdrawal_clearing'), $locked->currency);
            $payout = $this->systemAccounts->resolve(config('wallet.system_accounts.withdrawal_payout'), $locked->currency);

            $this->ledger->post($locked, [
                LedgerLeg::debit($clearing, $locked->amount),
                LedgerLeg::credit($payout, $locked->amount),
            ], 'settlement');

            $locked->settled_at = now();

            $this->outbox->record($locked, 'withdrawal.settled', "withdrawal.settled:{$locked->getKey()}", $this->payload($locked));
            $this->recorder->record(WithdrawalEvent::settled($locked, $reviewer));
        });
    }

    public function reject(Withdrawal $withdrawal, User $reviewer, ?string $reason): WithdrawalOutcome
    {
        return $this->transition($withdrawal, WithdrawalStatus::Rejected, $reviewer, function (Withdrawal $locked) use ($reviewer, $reason): void {
            $clearing = $this->systemAccounts->resolve(config('wallet.system_accounts.withdrawal_clearing'), $locked->currency);

            $this->ledger->post($locked, [
                LedgerLeg::debit($clearing, $locked->amount),
                LedgerLeg::credit($locked->wallet, $locked->amount),
            ], 'reversal');

            $locked->rejected_at = now();
            $locked->reason = $reason;

            $this->outbox->record($locked, 'withdrawal.rejected', "withdrawal.rejected:{$locked->getKey()}", $this->payload($locked));
            $this->recorder->record(WithdrawalEvent::rejected($locked, $reviewer));
        });
    }

    private function transition(Withdrawal $withdrawal, WithdrawalStatus $target, User $reviewer, Closure $applyEffect): WithdrawalOutcome
    {
        return DB::transaction(function () use ($withdrawal, $target, $reviewer, $applyEffect): WithdrawalOutcome {
            $locked = Withdrawal::query()->lockForUpdate()->findOrFail($withdrawal->getKey());

            if ($locked->status === $target) {
                return WithdrawalOutcome::AlreadyProcessed;
            }

            if (! $locked->status->canTransitionTo($target)) {
                throw InvalidWithdrawalTransitionException::from($locked->status, $target);
            }

            $applyEffect($locked);
            $locked->status = $target;
            $locked->reviewed_by = $reviewer->getKey();
            $locked->save();

            return WithdrawalOutcome::Processed;
        }, attempts: 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Withdrawal $withdrawal): array
    {
        return [
            'reference' => $withdrawal->reference,
            'wallet_id' => $withdrawal->wallet_id,
            'amount' => $withdrawal->amount->amount,
            'currency' => $withdrawal->amount->currency->code,
        ];
    }
}
