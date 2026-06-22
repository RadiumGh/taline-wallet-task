<?php

declare(strict_types=1);

use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\Exceptions\ImmutableLedgerEntryException;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Support\Str;

function ledgerEntryFor(object $reference, int $amount = 1000): LedgerEntry
{
    $wallet = Wallet::factory()->create(['currency' => 'IRR']);

    $entry = new LedgerEntry([
        'transaction_group' => (string) Str::uuid(),
        'wallet_id' => $wallet->getKey(),
        'amount' => new Money($amount, Currency::of('IRR')),
        'balance_after' => new Money($amount, Currency::of('IRR')),
    ]);
    $entry->reference()->associate($reference);
    $entry->save();

    return $entry;
}

test('amount and balance_after are exposed as Money', function () {
    $entry = ledgerEntryFor(Deposit::factory()->create(), 2500);

    expect($entry->amount)->toBeInstanceOf(Money::class)
        ->and($entry->amount->amount)->toBe(2500)
        ->and($entry->amount->currency->code)->toBe('IRR')
        ->and($entry->balance_after)->toBeInstanceOf(Money::class)
        ->and($entry->balance_after->amount)->toBe(2500);
});

test('the morph reference resolves back to its source model', function () {
    $deposit = Deposit::factory()->create();

    $entry = ledgerEntryFor($deposit);

    expect($entry->fresh()->reference)->toBeInstanceOf(Deposit::class)
        ->and($entry->fresh()->reference->is($deposit))->toBeTrue();
});

test('a persisted ledger entry cannot be updated', function () {
    $entry = ledgerEntryFor(Deposit::factory()->create());

    $entry->update(['transaction_group' => (string) Str::uuid()]);
})->throws(ImmutableLedgerEntryException::class);

test('a persisted ledger entry cannot be deleted', function () {
    $entry = ledgerEntryFor(Deposit::factory()->create());

    $entry->delete();
})->throws(ImmutableLedgerEntryException::class);

test('the entry has no updated_at column', function () {
    $entry = ledgerEntryFor(Deposit::factory()->create());

    expect($entry->getAttributes())->not->toHaveKey('updated_at');
});

test('direction is derived from the signed amount', function () {
    $credit = ledgerEntryFor(Deposit::factory()->create(), 1000);
    $debit = ledgerEntryFor(Deposit::factory()->create(), -1000);

    expect($credit->direction())->toBe(EntryDirection::Credit)
        ->and($debit->direction())->toBe(EntryDirection::Debit);
});
