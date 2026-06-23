<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Money\Currency;
use App\Domain\Money\Exceptions\CurrencyMismatchException;
use App\Domain\Money\Money;
use App\Domain\Observability\OperationRecorder;
use App\Domain\Wallet\Exceptions\WalletNotFoundException;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final class DepositService
{
    public function __construct(private readonly OperationRecorder $recorder) {}

    public function create(User $user, int $walletId, int $amount, string $currency, ?string $gateway, string $idempotencyKey): Deposit
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
            $deposit = Deposit::create([
                'reference' => (string) Str::uuid(),
                'wallet_id' => $wallet->getKey(),
                'amount' => $money,
                'currency' => $currency,
                'status' => DepositStatus::Pending,
                'gateway' => $gateway ?? config('wallet.gateway.default'),
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            return $this->findByKey($wallet, $idempotencyKey) ?? throw $e;
        }

        $this->recorder->record(DepositEvent::created($deposit, $user));

        return $deposit;
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

    private function findByKey(Wallet $wallet, string $idempotencyKey): ?Deposit
    {
        return Deposit::query()
            ->where('wallet_id', $wallet->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }
}
