<?php

declare(strict_types=1);

namespace App\Domain\Withdrawal;

use App\Domain\Ledger\LedgerLeg;
use App\Domain\Ledger\LedgerService;
use App\Domain\Money\Currency;
use App\Domain\Money\Exceptions\CurrencyMismatchException;
use App\Domain\Money\Money;
use App\Domain\Observability\OperationRecorder;
use App\Domain\Outbox\OutboxRecorder;
use App\Domain\Wallet\Exceptions\WalletNotFoundException;
use App\Domain\Wallet\SystemAccountResolver;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WithdrawalService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly SystemAccountResolver $systemAccounts,
        private readonly OutboxRecorder $outbox,
        private readonly OperationRecorder $recorder,
    ) {}

    public function request(User $user, int $walletId, int $amount, string $currency, string $idempotencyKey): Withdrawal
    {
        $wallet = $this->resolveOwnedWallet($user, $walletId);

        if ($wallet->currency !== $currency) {
            throw CurrencyMismatchException::between(Currency::of($currency), Currency::of($wallet->currency));
        }

        $existing = $this->findByKey($wallet, $idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        $money = Money::of($amount, $currency);

        try {
            return DB::transaction(function () use ($user, $wallet, $money, $idempotencyKey): Withdrawal {
                $withdrawal = Withdrawal::create([
                    'reference' => (string) Str::uuid(),
                    'wallet_id' => $wallet->getKey(),
                    'amount' => $money,
                    'currency' => $money->currency->code,
                    'status' => WithdrawalStatus::Requested,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $clearing = $this->systemAccounts->resolve(
                    config('wallet.system_accounts.withdrawal_clearing'),
                    $money->currency->code,
                );

                $this->ledger->post($withdrawal, [
                    LedgerLeg::debit($wallet, $money),
                    LedgerLeg::credit($clearing, $money),
                ], 'reservation');

                $this->outbox->record($withdrawal, 'withdrawal.requested', "withdrawal.requested:{$withdrawal->getKey()}", [
                    'reference' => $withdrawal->reference,
                    'wallet_id' => $withdrawal->wallet_id,
                    'amount' => $money->amount,
                    'currency' => $money->currency->code,
                ]);

                $this->recorder->record(WithdrawalEvent::requested($withdrawal, $user));

                return $withdrawal;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            return $this->findByKey($wallet, $idempotencyKey) ?? throw $e;
        }
    }

    private function resolveOwnedWallet(User $user, int $walletId): Wallet
    {
        $wallet = Wallet::query()
            ->where('id', $walletId)
            ->where('user_id', $user->getKey())
            ->first();

        if ($wallet === null) {
            throw WalletNotFoundException::forOwnedWallet($user->getKey(), $walletId);
        }

        return $wallet;
    }

    private function findByKey(Wallet $wallet, string $idempotencyKey): ?Withdrawal
    {
        return Withdrawal::query()
            ->where('wallet_id', $wallet->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }
}
