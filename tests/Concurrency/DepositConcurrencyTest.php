<?php

declare(strict_types=1);

use App\Domain\Deposit\DepositCallbackService;
use App\Domain\Deposit\DepositStatus;
use App\Domain\Deposit\GatewayCallbackData;
use App\Domain\Money\Money;
use App\Models\Deposit;
use App\Models\GatewayCallback;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Concurrency tests require a real MySQL connection.');
    }

    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required to fork parallel workers.');
    }

    config()->set('wallet.gateway.secret', 'testing-gateway-secret');
    $this->seed(SystemAccountsSeeder::class);
});

afterEach(function (): void {
    $this->truncateTablesForAllConnections();
});

function concurrentPendingDeposit(int $amount = 5000, string $currency = 'IRR'): Deposit
{
    $wallet = Wallet::factory()->for(User::factory())->create(['currency' => $currency]);

    return Deposit::create([
        'reference' => (string) Str::uuid(),
        'wallet_id' => $wallet->getKey(),
        'amount' => Money::of($amount, $currency),
        'currency' => $currency,
        'status' => DepositStatus::Pending,
        'gateway' => 'simulated',
        'idempotency_key' => (string) Str::uuid(),
    ]);
}

function confirmData(string $eventId): GatewayCallbackData
{
    $payload = ['event_id' => $eventId];
    $raw = (string) json_encode($payload);

    return new GatewayCallbackData(
        rawPayload: $raw,
        signature: hash_hmac('sha256', $raw, (string) config('wallet.gateway.secret')),
        gateway: 'simulated',
        eventId: $eventId,
        gatewayReference: null,
        payload: $payload,
    );
}

test('parallel duplicate confirm callbacks credit the wallet exactly once', function () {
    $deposit = concurrentPendingDeposit(5000);

    runInParallel(6, function () use ($deposit): void {
        app(DepositCallbackService::class)->confirm($deposit, confirmData('evt-dup'));
    });

    expect($deposit->refresh()->status)->toBe(DepositStatus::Confirmed)
        ->and($deposit->wallet->refresh()->balance->amount)->toBe(5000)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and((int) LedgerEntry::query()->sum('amount'))->toBe(0)
        ->and(GatewayCallback::query()->count())->toBe(1);

    foreach (LedgerEntry::query()->get()->groupBy('wallet_id') as $entries) {
        expect((int) Wallet::query()->find($entries->first()->wallet_id)->balance->amount)
            ->toBe((int) $entries->sum(fn (LedgerEntry $e): int => $e->amount->amount));
    }
});

test('parallel confirm callbacks under distinct event ids never double credit', function () {
    $deposit = concurrentPendingDeposit(5000);

    runInParallel(6, function (int $i) use ($deposit): void {
        app(DepositCallbackService::class)->confirm($deposit, confirmData("evt-{$i}"));
    });

    expect($deposit->refresh()->status)->toBe(DepositStatus::Confirmed)
        ->and($deposit->wallet->refresh()->balance->amount)->toBe(5000)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and((int) LedgerEntry::query()->sum('amount'))->toBe(0);
});
