<?php

declare(strict_types=1);

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Wallet\Exceptions\SystemAccountNotFoundException;
use App\Domain\Wallet\SystemAccountResolver;
use App\Domain\Wallet\WalletType;
use App\Models\Wallet;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Database\QueryException;

test('a system account may hold a negative balance', function () {
    $clearing = Wallet::factory()->system('gateway_clearing')->create(['currency' => 'IRR']);

    $clearing->balance = new Money(-500, Currency::of('IRR'));
    $clearing->save();

    expect($clearing->fresh()->balance->amount)->toBe(-500);
});

test('a user wallet still may not go negative under the type-aware check', function () {
    $wallet = Wallet::factory()->create(['currency' => 'IRR']);

    $wallet->balance = new Money(-1, Currency::of('IRR'));
    $wallet->save();
})->throws(QueryException::class);

test('two system accounts with the same code and currency violate the unique key', function () {
    Wallet::factory()->system('gateway_clearing')->create(['currency' => 'IRR']);
    Wallet::factory()->system('gateway_clearing')->create(['currency' => 'IRR']);
})->throws(QueryException::class);

test('the seeder creates a gateway clearing account resolvable by code and currency', function () {
    $this->seed(SystemAccountsSeeder::class);

    $clearing = app(SystemAccountResolver::class)->resolve('gateway_clearing', 'IRR');

    expect($clearing->type)->toBe(WalletType::System)
        ->and($clearing->code)->toBe('gateway_clearing')
        ->and($clearing->currency)->toBe('IRR')
        ->and($clearing->user_id)->toBeNull()
        ->and($clearing->balance->amount)->toBe(0);
});

test('resolving an unconfigured system account fails', function () {
    app(SystemAccountResolver::class)->resolve('missing_account', 'IRR');
})->throws(SystemAccountNotFoundException::class);
