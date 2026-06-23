<?php

declare(strict_types=1);

use App\Domain\Money\ValueObjects\Currency;
use App\Domain\Money\ValueObjects\Money;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;

test('balance is exposed as a Money instance in the wallet currency', function () {
    $wallet = Wallet::factory()->create(['currency' => 'IRR']);

    expect($wallet->balance)->toBeInstanceOf(Money::class)
        ->and($wallet->balance->amount)->toBe(0)
        ->and($wallet->balance->currency->code)->toBe('IRR');
});

test('the balance column round-trips through the Money cast', function () {
    $wallet = Wallet::factory()->create(['currency' => 'IRR']);

    $wallet->balance = new Money(1500, Currency::of('IRR'));
    $wallet->save();

    expect($wallet->fresh()->balance->amount)->toBe(1500);
});

test('a second wallet for the same user and currency violates the unique key', function () {
    $user = User::factory()->create();
    Wallet::factory()->for($user)->create(['currency' => 'IRR']);

    Wallet::factory()->for($user)->create(['currency' => 'IRR']);
})->throws(QueryException::class);

test('the same user may hold wallets in different currencies', function () {
    $user = User::factory()->create();

    Wallet::factory()->for($user)->create(['currency' => 'IRR']);
    Wallet::factory()->for($user)->create(['currency' => 'USD']);

    expect(Wallet::query()->where('user_id', $user->getKey())->count())->toBe(2);
});

test('a negative balance is rejected by the database check constraint', function () {
    $wallet = Wallet::factory()->create(['currency' => 'IRR']);

    $wallet->balance = new Money(-1, Currency::of('IRR'));
    $wallet->save();
})->throws(QueryException::class);
