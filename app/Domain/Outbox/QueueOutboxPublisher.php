<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use App\Jobs\SendOutboxNotification;
use App\Models\OutboxEvent;

final class QueueOutboxPublisher implements OutboxPublisher
{
    public function publish(OutboxEvent $event): void
    {
        SendOutboxNotification::dispatch($event->dedupe_key, $event->event_type, $event->payload);
    }
}
