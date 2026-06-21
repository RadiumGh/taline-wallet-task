<?php

declare(strict_types=1);

namespace App\Domain\Money\Exceptions;

use App\Domain\Money\Currency;
use App\Exceptions\Contracts\HasHttpStatus;
use RuntimeException;

final class CurrencyMismatchException extends RuntimeException implements HasHttpStatus
{
    public static function between(Currency $left, Currency $right): self
    {
        return new self("Cannot operate on money of different currencies: {$left->code} and {$right->code}.");
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'currency_mismatch';
    }
}
