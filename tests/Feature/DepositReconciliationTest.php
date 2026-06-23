<?php

declare(strict_types=1);

use App\Domain\Deposit\DepositReconciliationService;
use App\Domain\Deposit\Enums\DepositStatus;
use App\Domain\Gateway\Contracts\PaymentGateway;
use App\Domain\Gateway\Enums\GatewayOutcome;
use App\Models\Deposit;
use App\Models\GatewayCallback;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\SystemAccountsSeeder;
use Tests\Support\FakePaymentGateway;

beforeEach(function (): void {
    config()->set('wallet.gateway.secret', 'testing-gateway-secret');
    $this->app->instance(PaymentGateway::class, new FakePaymentGateway);
    $this->seed(SystemAccountsSeeder::class);
});

function stalePendingDeposit(int $amount = 5000, string $currency = 'IRR', int $ageMinutes = 30): Deposit
{
    $wallet = Wallet::factory()->for(User::factory())->create(['currency' => $currency]);

    return Deposit::factory()
        ->forWallet($wallet, $amount)
        ->create(['created_at' => now()->subMinutes($ageMinutes)]);
}

function gateway(): FakePaymentGateway
{
    $gateway = app(PaymentGateway::class);
    assert($gateway instanceof FakePaymentGateway);

    return $gateway;
}

function reconcile(int $olderThanMinutes = 15, int $limit = 100): void
{
    app(DepositReconciliationService::class)->reconcile($olderThanMinutes, $limit);
}

test('a stuck pending deposit that the gateway confirms is credited exactly once', function () {
    $deposit = stalePendingDeposit(5000);
    gateway()->stageStatus($deposit->reference, GatewayOutcome::Confirmed);

    reconcile();

    expect($deposit->refresh()->status)->toBe(DepositStatus::Confirmed)
        ->and($deposit->confirmed_at)->not->toBeNull()
        ->and($deposit->wallet->refresh()->balance->amount)->toBe(5000)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and((int) LedgerEntry::query()->sum('amount'))->toBe(0)
        ->and(GatewayCallback::query()->count())->toBe(1);
});

test('reconciliation is idempotent and safe to run repeatedly', function () {
    $deposit = stalePendingDeposit(5000);
    gateway()->stageStatus($deposit->reference, GatewayOutcome::Confirmed);

    reconcile();
    reconcile();
    reconcile();

    expect($deposit->wallet->refresh()->balance->amount)->toBe(5000)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and(GatewayCallback::query()->count())->toBe(1);
});

test('a stuck pending deposit that the gateway failed is marked failed with no credit', function () {
    $deposit = stalePendingDeposit(5000);
    gateway()->stageStatus($deposit->reference, GatewayOutcome::Failed);

    reconcile();

    expect($deposit->refresh()->status)->toBe(DepositStatus::Failed)
        ->and($deposit->failed_at)->not->toBeNull()
        ->and($deposit->wallet->refresh()->balance->amount)->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

test('a deposit the gateway still reports pending is left pending and never auto-failed', function () {
    $deposit = stalePendingDeposit(5000);

    reconcile();

    expect($deposit->refresh()->status)->toBe(DepositStatus::Pending)
        ->and($deposit->failed_at)->toBeNull()
        ->and($deposit->confirmed_at)->toBeNull()
        ->and(GatewayCallback::query()->count())->toBe(0);
});

test('a deposit younger than the threshold is not touched', function () {
    $deposit = stalePendingDeposit(5000, ageMinutes: 1);
    gateway()->stageStatus($deposit->reference, GatewayOutcome::Confirmed);

    reconcile(15);

    expect($deposit->refresh()->status)->toBe(DepositStatus::Pending)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

test('reconciliation resolves only up to the batch limit per run', function () {
    $first = stalePendingDeposit(1000);
    $second = stalePendingDeposit(2000);
    gateway()->stageStatus($first->reference, GatewayOutcome::Confirmed);
    gateway()->stageStatus($second->reference, GatewayOutcome::Confirmed);

    reconcile(olderThanMinutes: 15, limit: 1);

    expect(Deposit::query()->where('status', DepositStatus::Confirmed)->count())->toBe(1)
        ->and(Deposit::query()->where('status', DepositStatus::Pending)->count())->toBe(1);
});
