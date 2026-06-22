<?php

declare(strict_types=1);

namespace App\Domain\Observability;

final class InMemoryMetricsRecorder implements MetricsRecorder
{
    /** @var list<array{metric: string, value: float, tags: array<string, string>}> */
    public array $counters = [];

    /** @var list<array{metric: string, value: float, tags: array<string, string>}> */
    public array $gauges = [];

    /** @var list<array{metric: string, value: float, tags: array<string, string>}> */
    public array $histograms = [];

    public function increment(string $metric, array $tags = []): void
    {
        $this->counters[] = ['metric' => $metric, 'value' => 1.0, 'tags' => $tags];
    }

    public function gauge(string $metric, float $value, array $tags = []): void
    {
        $this->gauges[] = ['metric' => $metric, 'value' => $value, 'tags' => $tags];
    }

    public function histogram(string $metric, float $value, array $tags = []): void
    {
        $this->histograms[] = ['metric' => $metric, 'value' => $value, 'tags' => $tags];
    }

    public function countOf(string $metric): int
    {
        return count(array_filter($this->counters, fn (array $entry): bool => $entry['metric'] === $metric));
    }

    public function lastHistogram(string $metric): ?float
    {
        $matches = array_filter($this->histograms, fn (array $entry): bool => $entry['metric'] === $metric);

        return $matches === [] ? null : end($matches)['value'];
    }
}
