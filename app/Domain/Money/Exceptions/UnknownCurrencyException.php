<?php

declare(strict_types=1);

namespace App\Domain\Money\Exceptions;

use RuntimeException;

final class UnknownCurrencyException extends RuntimeException
{
    public static function forCode(string $code): self
    {
        return new self("Unknown currency code: {$code}.");
    }
}
