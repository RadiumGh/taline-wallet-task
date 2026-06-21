<?php

declare(strict_types=1);

namespace App\Domain\Idempotency;

enum IdempotencyStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
}
