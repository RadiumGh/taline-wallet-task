<?php

declare(strict_types=1);

use App\Domain\Transfer\TransferService;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function ledgerDelta(Wallet $wallet): int
{
    return (int) LedgerEntry::query()->where('wallet_id', $wallet->getKey())->sum('amount');
}

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Concurrency tests require a real MySQL connection.');
    }

    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required to fork parallel workers.');
    }
});

test('parallel transfers from one wallet never overspend', function () {
    $sender = User::factory()->create();
    $senderWallet = fundedWallet($sender, 300);
    $receivers = Wallet::factory()->count(5)->create(['currency' => 'IRR']);

    runInParallel(5, function (int $i) use ($sender, $receivers): void {
        app(TransferService::class)->transfer($sender, $receivers[$i]->getKey(), 100, 'IRR', (string) Str::uuid());
    });

    expect($senderWallet->refresh()->balance->amount)->toBe(0)
        ->and(ledgerDelta($senderWallet))->toBe(-300)
        ->and(Transfer::query()->count())->toBe(3)
        ->and(LedgerEntry::query()->count())->toBe(6)
        ->and((int) LedgerEntry::query()->sum('amount'))->toBe(0);

    foreach ($receivers as $receiver) {
        expect($receiver->refresh()->balance->amount)->toBe(ledgerDelta($receiver));
    }
});

test('concurrent opposing transfers keep the ledger balanced without deadlocking', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $aliceWallet = fundedWallet($alice, 1000);
    $bobWallet = fundedWallet($bob, 1000);
    $iterations = 25;

    runInParallel(2, function (int $i) use ($alice, $bob, $aliceWallet, $bobWallet, $iterations): void {
        $service = app(TransferService::class);

        for ($n = 0; $n < $iterations; $n++) {
            $i === 0
                ? $service->transfer($alice, $bobWallet->getKey(), 10, 'IRR', (string) Str::uuid())
                : $service->transfer($bob, $aliceWallet->getKey(), 10, 'IRR', (string) Str::uuid());
        }
    });

    expect($aliceWallet->refresh()->balance->amount + $bobWallet->refresh()->balance->amount)->toBe(2000)
        ->and($aliceWallet->balance->amount)->toBe(1000)
        ->and($bobWallet->balance->amount)->toBe(1000)
        ->and(ledgerDelta($aliceWallet))->toBe(0)
        ->and(ledgerDelta($bobWallet))->toBe(0)
        ->and(Transfer::query()->count())->toBe(2 * $iterations)
        ->and(LedgerEntry::query()->count())->toBe(4 * $iterations)
        ->and((int) LedgerEntry::query()->sum('amount'))->toBe(0);
});
