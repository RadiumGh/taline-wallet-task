<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use App\Domain\Outbox\Enums\OutboxStatus;
use App\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

final class OutboxRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Model $aggregate, string $eventType, string $dedupeKey, array $payload): ?OutboxEvent
    {
        $event = new OutboxEvent([
            'dedupe_key' => $dedupeKey,
            'event_type' => $eventType,
            'payload' => $payload,
            'status' => OutboxStatus::Pending,
            'available_at' => now(),
            'attempts' => 0,
        ]);
        $event->aggregate()->associate($aggregate);

        try {
            $event->save();
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $event;
    }
}
