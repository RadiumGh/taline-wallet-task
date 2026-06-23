<?php

declare(strict_types=1);

use App\Domain\Ledger\Exceptions\LedgerAlreadyPostedException;
use App\Domain\Ledger\LedgerService;
use App\Domain\Ledger\ValueObjects\LedgerLeg;
use App\Domain\Money\ValueObjects\Money;
use App\Domain\Transfer\Enums\TransferStatus;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

function transferReference(Wallet $from, Wallet $to): Transfer
{
    return Transfer::create([
        'reference' => (string) Str::uuid(),
        'from_wallet_id' => $from->getKey(),
        'to_wallet_id' => $to->getKey(),
        'amount' => Money::of(100, 'IRR'),
        'currency' => 'IRR',
        'status' => TransferStatus::Completed,
        'idempotency_key' => (string) Str::uuid(),
    ]);
}

test('re-posting the same source operation is rejected and credits the wallet only once', function () {
    $from = fundedWallet(User::factory()->create(), 1000);
    $to = Wallet::factory()->create(['currency' => 'IRR']);
    $transfer = transferReference($from, $to);
    $ledger = app(LedgerService::class);

    $legs = fn (): array => [
        LedgerLeg::debit($from, Money::of(100, 'IRR')),
        LedgerLeg::credit($to, Money::of(100, 'IRR')),
    ];

    $ledger->post($transfer, $legs());

    expect($from->refresh()->balance->amount)->toBe(900)
        ->and($to->refresh()->balance->amount)->toBe(100)
        ->and(LedgerEntry::query()->count())->toBe(2);

    expect(fn () => $ledger->post($transfer, $legs()))
        ->toThrow(LedgerAlreadyPostedException::class);

    expect($from->refresh()->balance->amount)->toBe(900)
        ->and($to->refresh()->balance->amount)->toBe(100)
        ->and(LedgerEntry::query()->count())->toBe(2);
});

test('duplicate entries for the same reference and wallet violate the unique key', function () {
    $wallet = Wallet::factory()->create(['currency' => 'IRR']);
    $transfer = transferReference($wallet, Wallet::factory()->create(['currency' => 'IRR']));

    $entry = fn (): LedgerEntry => LedgerEntry::create([
        'transaction_group' => (string) Str::uuid(),
        'wallet_id' => $wallet->getKey(),
        'amount' => Money::of(100, 'IRR'),
        'balance_after' => Money::of(100, 'IRR'),
        'reference_type' => $transfer->getMorphClass(),
        'reference_id' => $transfer->getKey(),
    ]);

    $entry();
    $entry();
})->throws(QueryException::class);
