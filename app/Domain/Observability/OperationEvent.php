<?php

declare(strict_types=1);

namespace App\Domain\Observability;

use Illuminate\Database\Eloquent\Model;

final readonly class OperationEvent
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $name,
        public Model $subject,
        public array $context,
        public ?Model $actor = null,
        public ?Measurement $measurement = null,
    ) {}
}
