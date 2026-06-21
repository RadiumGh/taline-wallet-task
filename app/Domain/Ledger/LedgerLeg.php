<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Money\Money;
use App\Models\Wallet;

final readonly class LedgerLeg
{
    public function __construct(
        public Wallet $wallet,
        public Money $amount,
    ) {}

    public static function credit(Wallet $wallet, Money $amount): self
    {
        return new self($wallet, $amount);
    }

    public static function debit(Wallet $wallet, Money $amount): self
    {
        return new self($wallet, $amount->negate());
    }
}
