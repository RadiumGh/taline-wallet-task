<?php

declare(strict_types=1);

use App\Domain\Money\Exceptions\CurrencyMismatchException;
use App\Domain\Money\ValueObjects\Currency;
use App\Domain\Money\ValueObjects\Money;

function irr(int $amount): Money
{
    return Money::of($amount, 'IRR');
}

test('it constructs from minor units and a currency code', function () {
    $money = irr(1500);

    expect($money->amount)->toBe(1500)
        ->and($money->currency)->toBeInstanceOf(Currency::class)
        ->and($money->currency->code)->toBe('IRR');
});

test('construction rejects a float amount under strict types', function () {
    new Money(1.5, Currency::of('IRR'));
})->throws(TypeError::class);

test('plus and minus produce new immutable instances', function () {
    $base = irr(1000);

    expect($base->plus(irr(250))->amount)->toBe(1250)
        ->and($base->minus(irr(400))->amount)->toBe(600)
        ->and($base->amount)->toBe(1000);
});

test('negate flips the sign', function () {
    expect(irr(1000)->negate()->amount)->toBe(-1000)
        ->and(irr(-1000)->negate()->amount)->toBe(1000);
});

test('arithmetic across currencies throws', function () {
    irr(1000)->plus(Money::of(1000, 'USD'));
})->throws(CurrencyMismatchException::class);

test('ordering comparison across currencies throws', function () {
    irr(1000)->greaterThan(Money::of(1000, 'USD'));
})->throws(CurrencyMismatchException::class);

test('comparisons work within a currency', function () {
    expect(irr(1000)->greaterThan(irr(999)))->toBeTrue()
        ->and(irr(1000)->lessThan(irr(1001)))->toBeTrue()
        ->and(irr(1000)->greaterThanOrEqual(irr(1000)))->toBeTrue()
        ->and(irr(1000)->lessThanOrEqual(irr(1000)))->toBeTrue();
});

test('equals compares amount and currency without throwing on mismatch', function () {
    expect(irr(1000)->equals(irr(1000)))->toBeTrue()
        ->and(irr(1000)->equals(irr(999)))->toBeFalse()
        ->and(irr(1000)->equals(Money::of(1000, 'USD')))->toBeFalse();
});

test('positivity and zero helpers', function () {
    expect(irr(1)->isPositive())->toBeTrue()
        ->and(irr(0)->isPositive())->toBeFalse()
        ->and(irr(0)->isZero())->toBeTrue()
        ->and(irr(-1)->isNegative())->toBeTrue();
});

test('it serializes to a minor-unit amount plus currency code', function () {
    expect(irr(1500)->toArray())->toBe(['amount' => 1500, 'currency' => 'IRR'])
        ->and(json_encode(irr(1500)))->toBe('{"amount":1500,"currency":"IRR"}');
});
