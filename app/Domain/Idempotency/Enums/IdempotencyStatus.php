<?php

declare(strict_types=1);

namespace App\Domain\Idempotency\Enums;

enum IdempotencyStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
}
