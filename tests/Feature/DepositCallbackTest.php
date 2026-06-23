<?php

declare(strict_types=1);

use App\Domain\Deposit\Enums\DepositStatus;
use App\Domain\Wallet\SystemAccountResolver;
use App\Models\Deposit;
use App\Models\GatewayCallback;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config()->set('wallet.gateway.secret', 'testing-gateway-secret');
});

function pendingDeposit(int $amount = 5000, string $currency = 'IRR'): Deposit
{
    $wallet = Wallet::factory()->for(User::factory())->create(['currency' => $currency]);

    return Deposit::factory()->forWallet($wallet, $amount)->create();
}

function callback(Deposit $deposit, string $action, array $payload, ?string $signature = null): TestResponse
{
    $raw = json_encode($payload);
    $signature ??= hash_hmac('sha256', $raw, (string) config('wallet.gateway.secret'));

    return test()
        ->withHeader('X-Gateway-Signature', $signature)
        ->postJson("/api/deposits/{$deposit->reference}/callbacks/{$action}", $payload);
}

function gatewayClearing(string $currency = 'IRR'): Wallet
{
    return app(SystemAccountResolver::class)->resolve('gateway_clearing', $currency);
}

test('confirm credits the wallet once and writes balanced deposit and clearing entries', function () {
    $this->seed(SystemAccountsSeeder::class);
    $deposit = pendingDeposit(5000);

    callback($deposit, 'confirm', ['event_id' => 'evt-1'])
        ->assertOk()
        ->assertJsonPath('result', 'processed')
        ->assertJsonPath('data.status', 'confirmed');

    expect($deposit->wallet->refresh()->balance->amount)->toBe(5000)
        ->and(gatewayClearing()->refresh()->balance->amount)->toBe(-5000)
        ->and($deposit->refresh()->confirmed_at)->not->toBeNull();

    $entries = LedgerEntry::query()
        ->where('reference_type', $deposit->getMorphClass())
        ->where('reference_id', $deposit->getKey())
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->sum(fn (LedgerEntry $e): int => $e->amount->amount))->toBe(0);
});

test('a duplicate confirm with the same event id is a no-op', function () {
    $this->seed(SystemAccountsSeeder::class);
    $deposit = pendingDeposit(5000);

    callback($deposit, 'confirm', ['event_id' => 'evt-1'])->assertJsonPath('result', 'processed');
    callback($deposit, 'confirm', ['event_id' => 'evt-1'])
        ->assertOk()
        ->assertJsonPath('result', 'already_processed');

    expect($deposit->wallet->refresh()->balance->amount)->toBe(5000)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and(GatewayCallback::query()->count())->toBe(1);
});

test('a second confirm under a different event id does not double credit', function () {
    $this->seed(SystemAccountsSeeder::class);
    $deposit = pendingDeposit(5000);

    callback($deposit, 'confirm', ['event_id' => 'evt-1'])->assertJsonPath('result', 'processed');
    callback($deposit, 'confirm', ['event_id' => 'evt-2'])
        ->assertOk()
        ->assertJsonPath('result', 'already_processed');

    expect($deposit->wallet->refresh()->balance->amount)->toBe(5000)
        ->and(LedgerEntry::query()->count())->toBe(2);
});

test('fail marks the deposit failed with no credit and no ledger movement', function () {
    $deposit = pendingDeposit(5000);

    callback($deposit, 'fail', ['event_id' => 'evt-f'])
        ->assertOk()
        ->assertJsonPath('result', 'processed')
        ->assertJsonPath('data.status', 'failed');

    expect($deposit->wallet->refresh()->balance->amount)->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and($deposit->refresh()->failed_at)->not->toBeNull();
});

test('confirm after fail is rejected with 409 and moves no money', function () {
    $this->seed(SystemAccountsSeeder::class);
    $deposit = pendingDeposit(5000);

    callback($deposit, 'fail', ['event_id' => 'evt-f'])->assertOk();

    callback($deposit, 'confirm', ['event_id' => 'evt-c'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'invalid_deposit_transition');

    expect($deposit->wallet->refresh()->balance->amount)->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and($deposit->refresh()->status)->toBe(DepositStatus::Failed);
});

test('fail after confirm is rejected with 409 and keeps the credit', function () {
    $this->seed(SystemAccountsSeeder::class);
    $deposit = pendingDeposit(5000);

    callback($deposit, 'confirm', ['event_id' => 'evt-c'])->assertOk();

    callback($deposit, 'fail', ['event_id' => 'evt-f'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'invalid_deposit_transition');

    expect($deposit->wallet->refresh()->balance->amount)->toBe(5000)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and($deposit->refresh()->status)->toBe(DepositStatus::Confirmed);
});

test('a bad signature is rejected with 401 and changes nothing', function () {
    $this->seed(SystemAccountsSeeder::class);
    $deposit = pendingDeposit(5000);

    callback($deposit, 'confirm', ['event_id' => 'evt-1'], 'not-a-valid-signature')
        ->assertStatus(401)
        ->assertJsonPath('error', 'invalid_gateway_signature');

    expect($deposit->wallet->refresh()->balance->amount)->toBe(0)
        ->and($deposit->refresh()->status)->toBe(DepositStatus::Pending)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and(GatewayCallback::query()->count())->toBe(0);
});

test('a missing signature is rejected with 401', function () {
    $deposit = pendingDeposit(5000);

    test()
        ->postJson("/api/deposits/{$deposit->reference}/callbacks/confirm", ['event_id' => 'evt-1'])
        ->assertStatus(401)
        ->assertJsonPath('error', 'invalid_gateway_signature');

    expect($deposit->refresh()->status)->toBe(DepositStatus::Pending);
});
