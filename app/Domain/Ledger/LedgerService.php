<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Ledger\Exceptions\InsufficientFundsException;
use App\Domain\Ledger\Exceptions\LedgerAlreadyPostedException;
use App\Domain\Ledger\Exceptions\UnbalancedLedgerPostException;
use App\Domain\Money\Currency;
use App\Domain\Money\Exceptions\CurrencyMismatchException;
use App\Domain\Money\Money;
use App\Domain\Wallet\WalletType;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LedgerService
{
    /**
     * @param  list<LedgerLeg>  $legs
     */
    public function post(Model $reference, array $legs): string
    {
        $this->assertBalanced($legs);

        try {
            return $this->commit($reference, $legs);
        } catch (UniqueConstraintViolationException) {
            throw LedgerAlreadyPostedException::forReference($reference);
        }
    }

    /**
     * @param  list<LedgerLeg>  $legs
     */
    private function commit(Model $reference, array $legs): string
    {
        return DB::transaction(function () use ($reference, $legs): string {
            $wallets = $this->lockWallets($legs);
            $transactionGroup = (string) Str::uuid();
            $balances = $wallets->map(fn(Wallet $wallet): int => $wallet->balance->amount)->all();

            foreach ($legs as $leg) {
                $wallet = $wallets->get($leg->wallet->getKey());
                $this->assertLegMatchesWallet($leg, $wallet);

                $resultingBalance = $balances[$wallet->getKey()] + $leg->amount->amount;

                if ($resultingBalance < 0 && $wallet->type === WalletType::User) {
                    throw InsufficientFundsException::forWallet($wallet);
                }

                $balances[$wallet->getKey()] = $resultingBalance;

                $entry = new LedgerEntry([
                    'transaction_group' => $transactionGroup,
                    'wallet_id' => $wallet->getKey(),
                    'amount' => $leg->amount,
                    'balance_after' => new Money($resultingBalance, $wallet->balance->currency),
                ]);
                $entry->reference()->associate($reference);
                $entry->save();
            }

            foreach ($wallets as $walletId => $wallet) {
                $wallet->balance = new Money($balances[$walletId], $wallet->balance->currency);
                $wallet->version += 1;
                $wallet->save();
            }

            return $transactionGroup;
        }, attempts: 3);
    }

    /**
     * @param  list<LedgerLeg>  $legs
     * @return Collection<int, Wallet>
     */
    private function lockWallets(array $legs): Collection
    {
        $walletIds = collect($legs)
            ->map(fn(LedgerLeg $leg): int => $leg->wallet->getKey())
            ->unique()
            ->sort()
            ->values()
            ->all();

        return Wallet::query()
            ->whereIn('id', $walletIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  list<LedgerLeg>  $legs
     */
    private function assertBalanced(array $legs): void
    {
        if ($legs === []) {
            throw UnbalancedLedgerPostException::empty();
        }

        $total = new Money(0, $legs[0]->amount->currency);

        foreach ($legs as $leg) {
            $total = $total->plus($leg->amount);
        }

        if (!$total->isZero()) {
            throw UnbalancedLedgerPostException::notZero($total);
        }
    }

    private function assertLegMatchesWallet(LedgerLeg $leg, Wallet $wallet): void
    {
        if ($leg->amount->currency->code !== $wallet->currency) {
            throw CurrencyMismatchException::between($leg->amount->currency, Currency::of($wallet->currency));
        }
    }
}
