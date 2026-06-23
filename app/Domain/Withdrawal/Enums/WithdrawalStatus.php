<?php

declare(strict_types=1);

namespace App\Domain\Withdrawal\Enums;

enum WithdrawalStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Settled = 'settled';
    case Rejected = 'rejected';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Requested => $target === self::Approved || $target === self::Rejected,
            self::Approved => $target === self::Settled || $target === self::Rejected,
            self::Settled, self::Rejected => false,
        };
    }
}
