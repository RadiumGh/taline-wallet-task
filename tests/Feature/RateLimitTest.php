<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function deposit(User $user, Wallet $wallet): TestResponse
{
    return test()
        ->withHeader('X-User-Id', (string) $user->getKey())
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/deposits', [
            'wallet_id' => $wallet->getKey(),
            'amount' => 1000,
            'currency' => 'IRR',
        ]);
}

test('a money write endpoint starts throttling once the per-user limit is exceeded', function () {
    config()->set('wallet.rate_limits.writes', 2);
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->create(['currency' => 'IRR']);

    $statuses = [];
    for ($i = 0; $i < 10; $i++) {
        $statuses[] = deposit($user, $wallet)->getStatusCode();
    }

    expect($statuses[0])->toBe(201)
        ->and($statuses)->toContain(429);
});

test('the throttle is keyed per user, so one caller cannot exhaust another', function () {
    config()->set('wallet.rate_limits.writes', 2);
    $alice = User::factory()->create();
    $aliceWallet = Wallet::factory()->for($alice)->create(['currency' => 'IRR']);
    $bob = User::factory()->create();
    $bobWallet = Wallet::factory()->for($bob)->create(['currency' => 'IRR']);

    for ($i = 0; $i < 10; $i++) {
        deposit($alice, $aliceWallet);
    }

    expect(deposit($alice, $aliceWallet)->getStatusCode())->toBe(429)
        ->and(deposit($bob, $bobWallet)->getStatusCode())->toBe(201);
});
