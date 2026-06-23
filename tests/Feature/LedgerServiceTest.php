<?php

declare(strict_types=1);

use App\Domain\Ledger\Exceptions\InsufficientFundsException;
use App\Domain\Ledger\Exceptions\UnbalancedLedgerPostException;
use App\Domain\Ledger\LedgerService;
use App\Domain\Ledger\ValueObjects\LedgerLeg;
use App\Domain\Money\Exceptions\CurrencyMismatchException;
use App\Domain\Money\ValueObjects\Money;
use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Support\Str;

function ledgeredWallet(int $balance, string $currency = 'IRR'): Wallet
{
    $wallet = Wallet::factory()->create(['currency' => $currency]);

    if ($balance !== 0) {
        $deposit = Deposit::factory()->forWallet($wallet, $balance)->create();

        LedgerEntry::create([
            'transaction_group' => (string) Str::uuid(),
            'wallet_id' => $wallet->getKey(),
            'amount' => Money::of($balance, $currency),
            'balance_after' => Money::of($balance, $currency),
            'reference_type' => $deposit->getMorphClass(),
            'reference_id' => $deposit->getKey(),
        ]);

        $wallet->balance = Money::of($balance, $currency);
        $wallet->save();
    }

    return $wallet->refresh();
}

function post(array $legs, ?object $reference = null): string
{
    return app(LedgerService::class)->post($reference ?? Deposit::factory()->create(), $legs);
}

test('a balanced movement updates both balances and writes entries with running balance_after', function () {
    $from = ledgeredWallet(1000);
    $to = ledgeredWallet(0);

    $group = post([
        new LedgerLeg($from, Money::of(-400, 'IRR')),
        new LedgerLeg($to, Money::of(400, 'IRR')),
    ]);

    expect($from->refresh()->balance->amount)->toBe(600)
        ->and($to->refresh()->balance->amount)->toBe(400)
        ->and($from->version)->toBe(1)
        ->and($to->version)->toBe(1);

    $entries = LedgerEntry::query()->where('transaction_group', $group)->get()->keyBy('wallet_id');

    expect($entries->get($from->getKey())->balance_after->amount)->toBe(600)
        ->and($entries->get($to->getKey())->balance_after->amount)->toBe(400);
});

test('the legs of a movement sum to zero and balances equal the sum of their entries', function () {
    $from = ledgeredWallet(1000);
    $to = ledgeredWallet(0);

    $group = post([
        new LedgerLeg($from, Money::of(-400, 'IRR')),
        new LedgerLeg($to, Money::of(400, 'IRR')),
    ]);

    $groupSum = LedgerEntry::query()->where('transaction_group', $group)->get()
        ->sum(fn (LedgerEntry $entry): int => $entry->amount->amount);

    expect($groupSum)->toBe(0);

    foreach ([$from, $to] as $wallet) {
        $walletSum = LedgerEntry::query()->where('wallet_id', $wallet->getKey())->get()
            ->sum(fn (LedgerEntry $entry): int => $entry->amount->amount);

        expect($wallet->refresh()->balance->amount)->toBe($walletSum);
    }
});

test('a debit exceeding the balance throws and writes nothing', function () {
    $from = ledgeredWallet(100);
    $to = ledgeredWallet(0);
    $entriesBefore = LedgerEntry::query()->count();

    expect(fn () => post([
        new LedgerLeg($from, Money::of(-400, 'IRR')),
        new LedgerLeg($to, Money::of(400, 'IRR')),
    ]))->toThrow(InsufficientFundsException::class);

    expect($from->refresh()->balance->amount)->toBe(100)
        ->and($to->refresh()->balance->amount)->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe($entriesBefore);
});

test('an unbalanced draft is rejected', function () {
    $from = ledgeredWallet(1000);
    $to = ledgeredWallet(0);

    post([
        new LedgerLeg($from, Money::of(-400, 'IRR')),
        new LedgerLeg($to, Money::of(300, 'IRR')),
    ]);
})->throws(UnbalancedLedgerPostException::class);

test('a leg whose currency differs from its wallet is rejected', function () {
    $from = ledgeredWallet(1000, 'IRR');
    $to = ledgeredWallet(0, 'IRR');

    post([
        new LedgerLeg($from, Money::of(-400, 'USD')),
        new LedgerLeg($to, Money::of(400, 'USD')),
    ]);
})->throws(CurrencyMismatchException::class);
