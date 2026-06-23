<?php

declare(strict_types=1);

namespace App\Domain\Observability\Data;

final readonly class Measurement
{
    /**
     * @param  array<string, string>  $tags
     */
    public function __construct(
        public string $name,
        public float $value,
        public array $tags = [],
    ) {}
}
