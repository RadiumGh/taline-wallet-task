<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

enum DepositStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Failed = 'failed';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => $target === self::Confirmed || $target === self::Failed,
            self::Confirmed, self::Failed => false,
        };
    }
}
