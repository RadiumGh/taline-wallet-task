<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use App\Domain\Observability\MetricsRecorder;
use App\Models\OutboxEvent;
use Illuminate\Support\Carbon;
use Throwable;

final class OutboxRelay
{
    public function __construct(
        private readonly OutboxPublisher $publisher,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function relay(int $limit): int
    {
        $this->recordLag();

        $published = 0;

        foreach ($this->claimable($limit) as $event) {
            if ($this->publish($event)) {
                $published++;
            }
        }

        return $published;
    }

    private function recordLag(): void
    {
        $oldest = OutboxEvent::query()
            ->where('status', OutboxStatus::Pending->value)
            ->min('available_at');

        $lag = $oldest === null ? 0 : now()->getTimestamp() - Carbon::parse($oldest)->getTimestamp();

        $this->metrics->gauge('outbox.pending_lag_seconds', (float) max(0, $lag));
    }

    /**
     * @return iterable<OutboxEvent>
     */
    private function claimable(int $limit): iterable
    {
        return OutboxEvent::query()
            ->where('status', OutboxStatus::Pending->value)
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function publish(OutboxEvent $event): bool
    {
        try {
            $this->publisher->publish($event);
        } catch (Throwable $e) {
            $this->scheduleRetry($event, $e);

            return false;
        }

        $event->update([
            'status' => OutboxStatus::Published,
            'published_at' => now(),
            'last_error' => null,
        ]);

        return true;
    }

    private function scheduleRetry(OutboxEvent $event, Throwable $e): void
    {
        $attempts = $event->attempts + 1;

        if ($attempts >= (int) config('wallet.outbox.max_attempts')) {
            $event->update([
                'status' => OutboxStatus::Failed,
                'attempts' => $attempts,
                'last_error' => $e->getMessage(),
            ]);

            return;
        }

        $event->update([
            'attempts' => $attempts,
            'available_at' => now()->addSeconds($this->backoffSeconds($attempts)),
            'last_error' => $e->getMessage(),
        ]);
    }

    private function backoffSeconds(int $attempts): int
    {
        $base = (int) config('wallet.outbox.backoff_base_seconds');

        return $base * (2 ** ($attempts - 1)) + random_int(0, $base);
    }
}
