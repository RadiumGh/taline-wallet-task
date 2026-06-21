<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use App\Exceptions\Contracts\HasHttpStatus;
use RuntimeException;

final class WalletNotFoundException extends RuntimeException implements HasHttpStatus
{
    public static function forOwnerCurrency(int $userId, string $currency): self
    {
        return new self("No {$currency} wallet found for user [{$userId}].");
    }

    public function httpStatus(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'wallet_not_found';
    }
}
