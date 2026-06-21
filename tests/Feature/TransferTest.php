<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function fundedWalletFor(User $user, int $balance, string $currency = 'IRR'): Wallet
{
    $wallet = Wallet::factory()->for($user)->create(['currency' => $currency]);
    $wallet->balance = Money::of($balance, $currency);
    $wallet->save();

    return $wallet->refresh();
}

function transferRequest(User $sender, array $payload): TestResponse
{
    return test()->withHeader('X-User-Id', (string) $sender->getKey())
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/transfers', $payload);
}

test('a successful transfer moves funds and writes two balanced ledger entries', function () {
    $sender = User::factory()->create();
    $from = fundedWalletFor($sender, 1000);
    $to = Wallet::factory()->create(['currency' => 'IRR']);

    $response = transferRequest($sender, [
        'to_wallet_id' => $to->getKey(),
        'amount' => 400,
        'currency' => 'IRR',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.amount', 400)
        ->assertJsonPath('data.currency', 'IRR')
        ->assertJsonPath('data.from_wallet_id', $from->getKey())
        ->assertJsonPath('data.to_wallet_id', $to->getKey());

    expect($from->refresh()->balance->amount)->toBe(600)
        ->and($to->refresh()->balance->amount)->toBe(400);

    $transfer = Transfer::query()->firstOrFail();
    $entries = LedgerEntry::query()
        ->where('reference_type', $transfer->getMorphClass())
        ->where('reference_id', $transfer->getKey())
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->sum(fn (LedgerEntry $e): int => $e->amount->amount))->toBe(0);
});

test('insufficient funds returns 422 and persists nothing', function () {
    $sender = User::factory()->create();
    $from = fundedWalletFor($sender, 100);
    $to = Wallet::factory()->create(['currency' => 'IRR']);

    $response = transferRequest($sender, [
        'to_wallet_id' => $to->getKey(),
        'amount' => 400,
        'currency' => 'IRR',
    ]);

    $response->assertStatus(422)->assertJsonPath('error', 'insufficient_funds');

    expect($from->refresh()->balance->amount)->toBe(100)
        ->and($to->refresh()->balance->amount)->toBe(0)
        ->and(Transfer::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

test('a self transfer is rejected with 422', function () {
    $sender = User::factory()->create();
    $from = fundedWalletFor($sender, 1000);

    $response = transferRequest($sender, [
        'to_wallet_id' => $from->getKey(),
        'amount' => 100,
        'currency' => 'IRR',
    ]);

    $response->assertStatus(422)->assertJsonPath('error', 'self_transfer');
});

test('a currency mismatch with the destination wallet is rejected with 422', function () {
    $sender = User::factory()->create();
    fundedWalletFor($sender, 1000, 'IRR');
    $to = Wallet::factory()->create(['currency' => 'USD']);

    $response = transferRequest($sender, [
        'to_wallet_id' => $to->getKey(),
        'amount' => 400,
        'currency' => 'IRR',
    ]);

    $response->assertStatus(422)->assertJsonPath('error', 'currency_mismatch');
});

test('a sender with no wallet in the currency returns 404', function () {
    $sender = User::factory()->create();
    $to = Wallet::factory()->create(['currency' => 'IRR']);

    $response = transferRequest($sender, [
        'to_wallet_id' => $to->getKey(),
        'amount' => 400,
        'currency' => 'IRR',
    ]);

    $response->assertStatus(404)->assertJsonPath('error', 'wallet_not_found');
});

test('an unknown destination wallet is rejected by validation', function () {
    $sender = User::factory()->create();
    fundedWalletFor($sender, 1000);

    $response = transferRequest($sender, [
        'to_wallet_id' => 999999,
        'amount' => 400,
        'currency' => 'IRR',
    ]);

    $response->assertStatus(422);
});

test('the transfer endpoint requires authentication', function () {
    $to = Wallet::factory()->create(['currency' => 'IRR']);

    $response = test()->postJson('/api/transfers', [
        'to_wallet_id' => $to->getKey(),
        'amount' => 400,
        'currency' => 'IRR',
    ]);

    $response->assertUnauthorized();
});
