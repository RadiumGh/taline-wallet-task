<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OutboxNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendOutboxNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $dedupeKey,
        public string $eventType,
        public array $payload,
    ) {}

    public function handle(): void
    {
        try {
            OutboxNotification::query()->create([
                'dedupe_key' => $this->dedupeKey,
                'event_type' => $this->eventType,
                'payload' => $this->payload,
            ]);
        } catch (UniqueConstraintViolationException) {
        }
    }
}
