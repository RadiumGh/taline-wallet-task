<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function referenceModel(): Model
{
    return Transfer::create([
        'reference' => (string) Str::uuid(),
        'from_wallet_id' => Wallet::factory()->create(['currency' => 'IRR'])->getKey(),
        'to_wallet_id' => Wallet::factory()->create(['currency' => 'IRR'])->getKey(),
        'amount' => Money::of(1, 'IRR'),
        'currency' => 'IRR',
        'status' => 'completed',
    ]);
}

function historyEntry(Wallet $wallet, int $amount, ?Model $reference = null, ?Carbon $at = null): LedgerEntry
{
    $reference ??= referenceModel();

    $entry = new LedgerEntry([
        'transaction_group' => (string) Str::uuid(),
        'wallet_id' => $wallet->getKey(),
        'amount' => Money::of($amount, 'IRR'),
        'balance_after' => Money::of($amount, 'IRR'),
        'reference_type' => $reference->getMorphClass(),
        'reference_id' => $reference->getKey(),
    ]);

    if ($at !== null) {
        $entry->created_at = $at;
    }

    $entry->save();

    return $entry;
}

function history(User $owner, Wallet $wallet, string $query = ''): TestResponse
{
    return test()
        ->withHeader('X-User-Id', (string) $owner->getKey())
        ->getJson("/api/wallets/{$wallet->getKey()}/transactions{$query}");
}

test('history pages through every entry once via the returned cursor', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->create(['currency' => 'IRR']);

    $created = collect(range(1, 5))
        ->map(fn (int $i): LedgerEntry => historyEntry($wallet, $i * 10));

    $seen = [];
    $query = '?per_page=2';

    do {
        $response = history($user, $wallet, $query)->assertOk();
        $seen = array_merge($seen, $response->json('data.*.id'));
        $cursor = $response->json('meta.next_cursor');
        $query = '?per_page=2&cursor='.$cursor;
    } while ($cursor !== null);

    expect($seen)->toHaveCount(5)
        ->and(array_unique($seen))->toHaveCount(5)
        ->and($seen)->toBe($created->sortByDesc('id')->pluck('id')->values()->all());
});

test('a tampered cursor is handled gracefully and does not error', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->create(['currency' => 'IRR']);
    historyEntry($wallet, 100, referenceModel());

    history($user, $wallet, '?cursor=not-a-real-cursor')->assertOk();
});

test('the direction filter returns only credits or only debits', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->create(['currency' => 'IRR']);
    historyEntry($wallet, 100);
    historyEntry($wallet, -40);

    $credits = history($user, $wallet, '?direction=credit')->assertOk();
    $debits = history($user, $wallet, '?direction=debit')->assertOk();

    expect($credits->json('data'))->toHaveCount(1)
        ->and($credits->json('data.0.direction'))->toBe('credit')
        ->and($debits->json('data'))->toHaveCount(1)
        ->and($debits->json('data.0.direction'))->toBe('debit');
});

test('the reference_type filter narrows to one operation type', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->create(['currency' => 'IRR']);
    $transfer = referenceModel();
    $deposit = Deposit::create([
        'reference' => (string) Str::uuid(),
        'wallet_id' => $wallet->getKey(),
        'amount' => Money::of(500, 'IRR'),
        'currency' => 'IRR',
        'status' => 'pending',
        'gateway' => 'simulated',
        'idempotency_key' => (string) Str::uuid(),
    ]);
    historyEntry($wallet, 100, $transfer);
    historyEntry($wallet, 500, $deposit);

    $response = history($user, $wallet, '?reference_type=deposit')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.reference_type'))->toBe('deposit');
});

test('date filters narrow the history window', function () {
    $user = User::factory()->create();
    $wallet = Wallet::factory()->for($user)->create(['currency' => 'IRR']);
    historyEntry($wallet, 10, null, Carbon::parse('2026-06-01 12:00:00'));
    historyEntry($wallet, 20, null, Carbon::parse('2026-06-10 12:00:00'));
    historyEntry($wallet, 30, null, Carbon::parse('2026-06-20 12:00:00'));

    $response = history($user, $wallet, '?date_from=2026-06-05&date_to=2026-06-15')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.amount'))->toBe(20);
});

test('a wallet you do not own returns 404', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $wallet = Wallet::factory()->for($owner)->create(['currency' => 'IRR']);

    history($stranger, $wallet)
        ->assertStatus(404)
        ->assertJsonPath('error', 'wallet_not_found');
});

test('history requires authentication', function () {
    $wallet = Wallet::factory()->create(['currency' => 'IRR']);

    test()->getJson("/api/wallets/{$wallet->getKey()}/transactions")->assertUnauthorized();
});
