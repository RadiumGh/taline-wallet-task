<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use App\Models\OutboxEvent;

interface OutboxPublisher
{
    public function publish(OutboxEvent $event): void;
}
