<?php

declare(strict_types=1);

namespace App\Domain\Money\Exceptions;

use App\Domain\Money\Currency;
use RuntimeException;

final class CurrencyMismatchException extends RuntimeException
{
    public static function between(Currency $left, Currency $right): self
    {
        return new self("Cannot operate on money of different currencies: {$left->code} and {$right->code}.");
    }
}
