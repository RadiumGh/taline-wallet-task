<?php

declare(strict_types=1);

namespace App\Domain\Observability;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

final class OperationRecorder
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function record(OperationEvent $event): void
    {
        $this->audit->record($event->name, $event->subject, $event->context, $event->actor);
        $this->metrics->increment($event->name);

        if ($event->measurement !== null) {
            $this->metrics->histogram($event->measurement->name, $event->measurement->value, $event->measurement->tags);
        }

        Log::info($event->name, ['request_id' => Context::get('request_id'), ...$event->context]);
    }
}
