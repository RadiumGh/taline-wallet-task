<?php

declare(strict_types=1);

namespace App\Domain\Gateway\Enums;

enum GatewayOutcome: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Failed = 'failed';

    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }
}
