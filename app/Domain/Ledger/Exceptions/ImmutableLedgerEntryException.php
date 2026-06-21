<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exceptions;

use RuntimeException;

final class ImmutableLedgerEntryException extends RuntimeException
{
    public static function cannotModify(): self
    {
        return new self('Ledger entries are append-only and cannot be updated or deleted.');
    }
}
