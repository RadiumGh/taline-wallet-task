<?php

declare(strict_types=1);

use App\Domain\Deposit\DepositService;
use App\Domain\Deposit\Enums\DepositStatus;
use App\Domain\Money\ValueObjects\Money;
use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function depositRequest(User $owner, array $payload, ?string $key = null): TestResponse
{
    return test()
        ->withHeader('X-User-Id', (string) $owner->getKey())
        ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
        ->postJson('/api/deposits', $payload);
}

test('creating a deposit returns pending and does not change the wallet balance', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->create(['currency' => 'IRR']);

    $response = depositRequest($user, [
        'wallet_id' => $wallet->getKey(),
        'amount' => 5000,
        'currency' => 'IRR',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount', 5000)
        ->assertJsonPath('data.currency', 'IRR')
        ->assertJsonPath('data.wallet_id', $wallet->getKey());

    expect($wallet->refresh()->balance->amount)->toBe(0)
        ->and(Deposit::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

test('a retried deposit with the same key returns the same deposit and creates no duplicate', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->create(['currency' => 'IRR']);
    $payload = ['wallet_id' => $wallet->getKey(), 'amount' => 5000, 'currency' => 'IRR'];

    $first = depositRequest($user, $payload, 'deposit-key')->assertCreated();
    $second = depositRequest($user, $payload, 'deposit-key')->assertCreated();

    expect($second->json('data.reference'))->toBe($first->json('data.reference'))
        ->and(Deposit::query()->count())->toBe(1);
});

test('the deposit create is idempotent at the service layer for the same wallet and key', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->create(['currency' => 'IRR']);
    $service = app(DepositService::class);

    $first = $service->create($user, $wallet->getKey(), 5000, 'IRR', null, 'svc-key');
    $second = $service->create($user, $wallet->getKey(), 5000, 'IRR', null, 'svc-key');

    expect($second->getKey())->toBe($first->getKey())
        ->and(Deposit::query()->count())->toBe(1);
});

test('duplicate deposit rows for the same wallet and key violate the unique key', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->create(['currency' => 'IRR']);

    $attributes = [
        'wallet_id' => $wallet->getKey(),
        'amount' => Money::of(5000, 'IRR'),
        'currency' => 'IRR',
        'status' => DepositStatus::Pending,
        'gateway' => 'simulated',
        'idempotency_key' => 'dup-key',
    ];

    Deposit::create(['reference' => (string) Str::uuid()] + $attributes);
    Deposit::create(['reference' => (string) Str::uuid()] + $attributes);
})->throws(QueryException::class);

test('an invalid amount is rejected with 422', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->create(['currency' => 'IRR']);

    depositRequest($user, [
        'wallet_id' => $wallet->getKey(),
        'amount' => 0,
        'currency' => 'IRR',
    ])->assertStatus(422);
});

test('a currency that does not match the wallet is rejected with 422', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->create(['currency' => 'IRR']);

    depositRequest($user, [
        'wallet_id' => $wallet->getKey(),
        'amount' => 5000,
        'currency' => 'USD',
    ])->assertStatus(422)->assertJsonPath('error', 'currency_mismatch');
});

test('depositing into a wallet you do not own returns 404', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $wallet = Wallet::factory()->for($owner)->create(['currency' => 'IRR']);

    depositRequest($stranger, [
        'wallet_id' => $wallet->getKey(),
        'amount' => 5000,
        'currency' => 'IRR',
    ])->assertStatus(404)->assertJsonPath('error', 'wallet_not_found');
});

test('a deposit without an Idempotency-Key is rejected with 400', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->create(['currency' => 'IRR']);

    test()
        ->withHeader('X-User-Id', (string) $user->getKey())
        ->postJson('/api/deposits', [
            'wallet_id' => $wallet->getKey(),
            'amount' => 5000,
            'currency' => 'IRR',
        ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'idempotency_key_required');
});
