<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

final readonly class GatewayCallbackData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $rawPayload,
        public string $signature,
        public string $gateway,
        public string $eventId,
        public ?string $gatewayReference,
        public array $payload,
    ) {}
}
