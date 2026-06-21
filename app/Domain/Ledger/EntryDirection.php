<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

enum EntryDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    public static function fromSignedAmount(int $amount): self
    {
        return $amount < 0 ? self::Debit : self::Credit;
    }
}
