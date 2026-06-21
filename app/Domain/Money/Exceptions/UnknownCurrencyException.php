<?php

declare(strict_types=1);

namespace App\Domain\Money\Exceptions;

use App\Exceptions\Contracts\HasHttpStatus;
use RuntimeException;

final class UnknownCurrencyException extends RuntimeException implements HasHttpStatus
{
    public static function forCode(string $code): self
    {
        return new self("Unknown currency code: {$code}.");
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'unknown_currency';
    }
}
