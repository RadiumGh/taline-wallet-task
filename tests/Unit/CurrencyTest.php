<?php

declare(strict_types=1);

use App\Domain\Money\Exceptions\UnknownCurrencyException;
use App\Domain\Money\ValueObjects\Currency;

test('config exposes the currency registry with IRR scale 0', function () {
    expect(config('wallet.currencies.IRR.scale'))->toBe(0)
        ->and(config('wallet.currencies.USD.scale'))->toBe(2);
});

test('currency is resolved from config and is case insensitive', function () {
    $currency = Currency::of('irr');

    expect($currency->code)->toBe('IRR')
        ->and($currency->scale)->toBe(0);
});

test('unknown currency throws', function () {
    Currency::of('XYZ');
})->throws(UnknownCurrencyException::class);

test('currencies compare by code', function () {
    expect(Currency::of('IRR')->equals(Currency::of('IRR')))->toBeTrue()
        ->and(Currency::of('IRR')->equals(Currency::of('USD')))->toBeFalse();
});
