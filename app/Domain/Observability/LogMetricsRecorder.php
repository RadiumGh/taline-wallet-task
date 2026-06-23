<?php

declare(strict_types=1);

namespace App\Domain\Observability;

use App\Domain\Observability\Contracts\MetricsRecorder;
use Illuminate\Support\Facades\Log;

final class LogMetricsRecorder implements MetricsRecorder
{
    public function increment(string $metric, array $tags = []): void
    {
        $this->write('counter', $metric, 1.0, $tags);
    }

    public function gauge(string $metric, float $value, array $tags = []): void
    {
        $this->write('gauge', $metric, $value, $tags);
    }

    public function histogram(string $metric, float $value, array $tags = []): void
    {
        $this->write('histogram', $metric, $value, $tags);
    }

    /**
     * @param  array<string, string>  $tags
     */
    private function write(string $type, string $metric, float $value, array $tags): void
    {
        Log::info('metric', [
            'type' => $type,
            'metric' => $metric,
            'value' => $value,
            'tags' => $tags,
        ]);
    }
}
