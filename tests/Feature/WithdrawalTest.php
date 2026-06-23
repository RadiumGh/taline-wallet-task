<?php

declare(strict_types=1);

use App\Domain\Wallet\SystemAccountResolver;
use App\Domain\Withdrawal\WithdrawalService;
use App\Domain\Withdrawal\WithdrawalStatus;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->seed(SystemAccountsSeeder::class);
});

function withdrawalRequest(User $owner, array $payload, ?string $key = null): TestResponse
{
    return test()
        ->withHeader('X-User-Id', (string) $owner->getKey())
        ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
        ->postJson('/api/withdrawals', $payload);
}

function reviewAction(User $admin, Withdrawal $withdrawal, string $action, array $payload = []): TestResponse
{
    return test()
        ->withHeader('X-User-Id', (string) $admin->getKey())
        ->postJson("/api/admin/withdrawals/{$withdrawal->reference}/{$action}", $payload);
}

function clearingAccount(string $currency = 'IRR'): Wallet
{
    return app(SystemAccountResolver::class)->resolve('withdrawal_clearing', $currency);
}

function payoutAccount(string $currency = 'IRR'): Wallet
{
    return app(SystemAccountResolver::class)->resolve('withdrawal_payout', $currency);
}

function withdrawalEntries(Withdrawal $withdrawal): Collection
{
    return LedgerEntry::query()
        ->where('reference_type', $withdrawal->getMorphClass())
        ->where('reference_id', $withdrawal->getKey())
        ->get();
}

test('requesting a withdrawal reserves funds once and writes a balanced reservation', function () {
    $user = User::factory()->create();
    $wallet = fundedWallet($user, 5000);

    $response = withdrawalRequest($user, [
        'wallet_id' => $wallet->getKey(),
        'amount' => 2000,
        'currency' => 'IRR',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'requested')
        ->assertJsonPath('data.amount', 2000)
        ->assertJsonPath('data.wallet_id', $wallet->getKey());

    expect($wallet->refresh()->balance->amount)->toBe(3000)
        ->and(clearingAccount()->refresh()->balance->amount)->toBe(2000);

    $withdrawal = Withdrawal::query()->firstOrFail();
    $entries = withdrawalEntries($withdrawal);

    expect($entries)->toHaveCount(2)
        ->and($entries->sum(fn (LedgerEntry $e): int => $e->amount->amount))->toBe(0);
});

test('a withdrawal beyond the available balance is rejected with 422 and reserves nothing', function () {
    $user = User::factory()->create();
    $wallet = fundedWallet($user, 1000);

    withdrawalRequest($user, [
        'wallet_id' => $wallet->getKey(),
        'amount' => 5000,
        'currency' => 'IRR',
    ])->assertStatus(422)->assertJsonPath('error', 'insufficient_funds');

    expect($wallet->refresh()->balance->amount)->toBe(1000)
        ->and(clearingAccount()->refresh()->balance->amount)->toBe(0)
        ->and(Withdrawal::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

test('repeating a request with the same key reserves money only once', function () {
    $user = User::factory()->create();
    $wallet = fundedWallet($user, 5000);

    $first = app(WithdrawalService::class)->request($user, $wallet->getKey(), 2000, 'IRR', 'same-key');
    $second = app(WithdrawalService::class)->request($user, $wallet->getKey(), 2000, 'IRR', 'same-key');

    expect($second->is($first))->toBeTrue()
        ->and(Withdrawal::query()->count())->toBe(1)
        ->and($wallet->refresh()->balance->amount)->toBe(3000)
        ->and(LedgerEntry::query()->count())->toBe(2);
});

test('a withdrawal in a currency the wallet does not hold is rejected with 422', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->create(['currency' => 'USD']);

    withdrawalRequest($user, [
        'wallet_id' => $wallet->getKey(),
        'amount' => 2000,
        'currency' => 'IRR',
    ])->assertStatus(422)->assertJsonPath('error', 'currency_mismatch');
});

test('the withdrawal endpoint requires authentication', function () {
    $wallet = Wallet::factory()->create(['currency' => 'IRR']);

    test()->postJson('/api/withdrawals', [
        'wallet_id' => $wallet->getKey(),
        'amount' => 2000,
        'currency' => 'IRR',
    ])->assertUnauthorized();
});

test('approving then settling pays out the reservation exactly once', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $wallet = fundedWallet($user, 5000);

    $withdrawal = app(WithdrawalService::class)->request($user, $wallet->getKey(), 2000, 'IRR', 'k1');

    reviewAction($admin, $withdrawal, 'approve')
        ->assertOk()
        ->assertJsonPath('result', 'processed')
        ->assertJsonPath('data.status', 'approved');

    reviewAction($admin, $withdrawal, 'settle')
        ->assertOk()
        ->assertJsonPath('result', 'processed')
        ->assertJsonPath('data.status', 'settled');

    expect($wallet->refresh()->balance->amount)->toBe(3000)
        ->and(clearingAccount()->refresh()->balance->amount)->toBe(0)
        ->and(payoutAccount()->refresh()->balance->amount)->toBe(2000)
        ->and($withdrawal->refresh()->reviewed_by)->toBe($admin->getKey());

    $entries = withdrawalEntries($withdrawal->refresh());
    expect($entries)->toHaveCount(4)
        ->and($entries->sum(fn (LedgerEntry $e): int => $e->amount->amount))->toBe(0);
});

test('settling again is a no-op and does not pay out twice', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $wallet = fundedWallet($user, 5000);

    $withdrawal = app(WithdrawalService::class)->request($user, $wallet->getKey(), 2000, 'IRR', 'k1');
    reviewAction($admin, $withdrawal, 'approve')->assertOk();
    reviewAction($admin, $withdrawal, 'settle')->assertOk()->assertJsonPath('result', 'processed');

    reviewAction($admin, $withdrawal, 'settle')
        ->assertOk()
        ->assertJsonPath('result', 'already_processed');

    expect(payoutAccount()->refresh()->balance->amount)->toBe(2000)
        ->and(withdrawalEntries($withdrawal->refresh()))->toHaveCount(4);
});

test('settling a withdrawal that was never approved is rejected with 409', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $wallet = fundedWallet($user, 5000);

    $withdrawal = app(WithdrawalService::class)->request($user, $wallet->getKey(), 2000, 'IRR', 'k1');

    reviewAction($admin, $withdrawal, 'settle')
        ->assertStatus(409)
        ->assertJsonPath('error', 'invalid_withdrawal_transition');

    expect($withdrawal->refresh()->status)->toBe(WithdrawalStatus::Requested)
        ->and(payoutAccount()->refresh()->balance->amount)->toBe(0);
});

test('rejecting a requested withdrawal releases the reservation', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $wallet = fundedWallet($user, 5000);

    $withdrawal = app(WithdrawalService::class)->request($user, $wallet->getKey(), 2000, 'IRR', 'k1');

    reviewAction($admin, $withdrawal, 'reject', ['reason' => 'suspicious activity'])
        ->assertOk()
        ->assertJsonPath('result', 'processed')
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.reason', 'suspicious activity');

    expect($wallet->refresh()->balance->amount)->toBe(5000)
        ->and(clearingAccount()->refresh()->balance->amount)->toBe(0);

    $entries = withdrawalEntries($withdrawal->refresh());
    expect($entries)->toHaveCount(4)
        ->and($entries->sum(fn (LedgerEntry $e): int => $e->amount->amount))->toBe(0);
});

test('an approved withdrawal can still be rejected and refunded', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $wallet = fundedWallet($user, 5000);

    $withdrawal = app(WithdrawalService::class)->request($user, $wallet->getKey(), 2000, 'IRR', 'k1');
    reviewAction($admin, $withdrawal, 'approve')->assertOk();

    reviewAction($admin, $withdrawal, 'reject')
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    expect($wallet->refresh()->balance->amount)->toBe(5000)
        ->and(clearingAccount()->refresh()->balance->amount)->toBe(0);
});

test('a settled withdrawal cannot be rejected', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $wallet = fundedWallet($user, 5000);

    $withdrawal = app(WithdrawalService::class)->request($user, $wallet->getKey(), 2000, 'IRR', 'k1');
    reviewAction($admin, $withdrawal, 'approve')->assertOk();
    reviewAction($admin, $withdrawal, 'settle')->assertOk();

    reviewAction($admin, $withdrawal, 'reject')
        ->assertStatus(409)
        ->assertJsonPath('error', 'invalid_withdrawal_transition');

    expect($wallet->refresh()->balance->amount)->toBe(3000)
        ->and(payoutAccount()->refresh()->balance->amount)->toBe(2000);
});
