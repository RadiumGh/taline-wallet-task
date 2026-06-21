<?php

declare(strict_types=1);

use App\Domain\Ledger\Exceptions\InsufficientFundsException;
use App\Domain\Money\Currency;
use App\Domain\Money\Exceptions\CurrencyMismatchException;
use App\Domain\Money\Exceptions\UnknownCurrencyException;
use App\Exceptions\Contracts\HasHttpStatus;
use App\Models\Wallet;

test('insufficient funds maps to a 422 with a stable error code', function () {
    $exception = InsufficientFundsException::forWallet(new Wallet);

    expect($exception)->toBeInstanceOf(HasHttpStatus::class)
        ->and($exception->httpStatus())->toBe(422)
        ->and($exception->errorCode())->toBe('insufficient_funds');
});

test('currency mismatch maps to a 422 with a stable error code', function () {
    $exception = CurrencyMismatchException::between(
        Currency::of('IRR'),
        Currency::of('USD'),
    );

    expect($exception)->toBeInstanceOf(HasHttpStatus::class)
        ->and($exception->httpStatus())->toBe(422)
        ->and($exception->errorCode())->toBe('currency_mismatch');
});

test('unknown currency maps to a 422 with a stable error code', function () {
    $exception = UnknownCurrencyException::forCode('XYZ');

    expect($exception)->toBeInstanceOf(HasHttpStatus::class)
        ->and($exception->httpStatus())->toBe(422)
        ->and($exception->errorCode())->toBe('unknown_currency');
});
