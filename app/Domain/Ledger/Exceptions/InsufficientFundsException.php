<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exceptions;

use App\Exceptions\Contracts\HasHttpStatus;
use App\Models\Wallet;
use RuntimeException;

final class InsufficientFundsException extends RuntimeException implements HasHttpStatus
{
    public static function forWallet(Wallet $wallet): self
    {
        return new self("Wallet [{$wallet->getKey()}] has insufficient funds for this debit.");
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'insufficient_funds';
    }
}
