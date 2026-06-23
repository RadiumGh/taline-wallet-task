<?php

declare(strict_types=1);

namespace App\Domain\Outbox\Contracts;

use App\Models\OutboxEvent;

interface OutboxPublisher
{
    public function publish(OutboxEvent $event): void;
}
