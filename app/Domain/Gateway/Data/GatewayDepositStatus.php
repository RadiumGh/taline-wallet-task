<?php

declare(strict_types=1);

namespace App\Domain\Gateway\Data;

use App\Domain\Gateway\Enums\GatewayOutcome;

final readonly class GatewayDepositStatus
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public GatewayOutcome $outcome,
        public string $eventId,
        public string $rawPayload,
        public string $signature,
        public ?string $gatewayReference,
        public array $payload,
    ) {}
}
