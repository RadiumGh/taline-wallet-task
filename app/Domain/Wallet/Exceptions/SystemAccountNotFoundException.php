<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use App\Exceptions\Contracts\HasHttpStatus;
use RuntimeException;

final class SystemAccountNotFoundException extends RuntimeException implements HasHttpStatus
{
    public static function for(string $code, string $currency): self
    {
        return new self("System account [{$code}] for currency [{$currency}] is not configured.");
    }

    public function httpStatus(): int
    {
        return 500;
    }

    public function errorCode(): string
    {
        return 'system_account_missing';
    }
}
