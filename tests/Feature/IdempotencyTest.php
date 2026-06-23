<?php

declare(strict_types=1);

use App\Domain\Idempotency\Enums\IdempotencyStatus;
use App\Domain\Money\ValueObjects\Money;
use App\Models\IdempotencyKey;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

function fundedSenderWallet(User $user, int $balance): Wallet
{
    $wallet = Wallet::factory()->for($user)->create(['currency' => 'IRR']);
    $wallet->balance = Money::of($balance, 'IRR');
    $wallet->save();

    return $wallet->refresh();
}

function postTo(User $sender, string $path, array $payload, ?string $key): TestResponse
{
    $request = test()->withHeader('X-User-Id', (string) $sender->getKey());

    if ($key !== null) {
        $request = $request->withHeader('Idempotency-Key', $key);
    }

    return $request->postJson($path, $payload);
}

function transferPayload(Wallet $to, int $amount = 400): array
{
    return ['to_wallet_id' => $to->getKey(), 'amount' => $amount, 'currency' => 'IRR'];
}

test('a transfer without an Idempotency-Key is rejected with 400', function () {
    $sender = User::factory()->create();
    fundedSenderWallet($sender, 1000);
    $to = Wallet::factory()->create(['currency' => 'IRR']);

    postTo($sender, '/api/transfers', transferPayload($to), null)
        ->assertStatus(400)
        ->assertJsonPath('error', 'idempotency_key_required');

    expect(Transfer::query()->count())->toBe(0);
});

test('a retry with the same key and payload replays the response and moves money once', function () {
    $sender = User::factory()->create();
    $from = fundedSenderWallet($sender, 1000);
    $to = Wallet::factory()->create(['currency' => 'IRR']);
    $payload = transferPayload($to);

    $first = postTo($sender, '/api/transfers', $payload, 'key-replay')->assertCreated();
    $second = postTo($sender, '/api/transfers', $payload, 'key-replay')->assertCreated();

    expect($second->json())->toEqual($first->json())
        ->and($from->refresh()->balance->amount)->toBe(600)
        ->and($to->refresh()->balance->amount)->toBe(400)
        ->and(Transfer::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->count())->toBe(1);
});

test('the same key with a different payload is rejected with 409', function () {
    $sender = User::factory()->create();
    fundedSenderWallet($sender, 1000);
    $to = Wallet::factory()->create(['currency' => 'IRR']);

    postTo($sender, '/api/transfers', transferPayload($to, 400), 'key-conflict')->assertCreated();

    postTo($sender, '/api/transfers', transferPayload($to, 500), 'key-conflict')
        ->assertStatus(409)
        ->assertJsonPath('error', 'idempotency_conflict');

    expect(Transfer::query()->count())->toBe(1);
});

test('a key that is still processing rejects a retry with 409 and moves no money', function () {
    $sender = User::factory()->create();
    $from = fundedSenderWallet($sender, 1000);
    $to = Wallet::factory()->create(['currency' => 'IRR']);
    $payload = transferPayload($to);

    IdempotencyKey::query()->create([
        'scope' => 'user:'.$sender->getKey(),
        'key' => 'key-inflight',
        'request_hash' => hash('sha256', 'POST|api/transfers|'.json_encode($payload)),
        'method' => 'POST',
        'path' => 'api/transfers',
        'status' => IdempotencyStatus::Processing,
        'locked_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    postTo($sender, '/api/transfers', $payload, 'key-inflight')
        ->assertStatus(409)
        ->assertJsonPath('error', 'idempotency_conflict');

    expect($from->refresh()->balance->amount)->toBe(1000)
        ->and(Transfer::query()->count())->toBe(0);
});

test('a server error releases the key so a genuine retry can succeed', function () {
    $sender = User::factory()->create();
    $attempts = 0;

    Route::post('/api/idempotency-probe', function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            abort(500, 'transient failure');
        }

        return response()->json(['attempt' => $attempts]);
    })->middleware(['auth.header', 'idempotency']);

    postTo($sender, '/api/idempotency-probe', ['amount' => 1], 'key-5xx')->assertStatus(500);

    expect(IdempotencyKey::query()->count())->toBe(0);

    postTo($sender, '/api/idempotency-probe', ['amount' => 1], 'key-5xx')
        ->assertOk()
        ->assertJsonPath('attempt', 2);

    expect(IdempotencyKey::query()->where('status', IdempotencyStatus::Completed->value)->count())->toBe(1);
});
