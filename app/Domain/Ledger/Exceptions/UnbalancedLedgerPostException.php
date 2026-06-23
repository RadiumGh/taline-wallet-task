<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exceptions;

use App\Domain\Money\ValueObjects\Money;
use RuntimeException;

final class UnbalancedLedgerPostException extends RuntimeException
{
    public static function empty(): self
    {
        return new self('A ledger post must contain at least one leg.');
    }

    public static function notZero(Money $total): self
    {
        return new self("Ledger legs must sum to zero; got {$total->amount} {$total->currency->code}.");
    }
}
