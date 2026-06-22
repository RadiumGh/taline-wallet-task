<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Failed = 'failed';
}
