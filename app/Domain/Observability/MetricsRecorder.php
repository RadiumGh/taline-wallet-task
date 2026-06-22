<?php

declare(strict_types=1);

namespace App\Domain\Observability;

interface MetricsRecorder
{
    /**
     * @param  array<string, string>  $tags
     */
    public function increment(string $metric, array $tags = []): void;

    /**
     * @param  array<string, string>  $tags
     */
    public function gauge(string $metric, float $value, array $tags = []): void;

    /**
     * @param  array<string, string>  $tags
     */
    public function histogram(string $metric, float $value, array $tags = []): void;
}
