<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Gateway\Contracts\PaymentGateway;
use App\Domain\Gateway\Data\GatewayDepositStatus;
use App\Domain\Gateway\Enums\GatewayOutcome;

final class FakePaymentGateway implements PaymentGateway
{
    /**
     * @var array<string, array{outcome: GatewayOutcome, gateway_reference: ?string}>
     */
    private array $staged = [];

    public function stageStatus(string $reference, GatewayOutcome $outcome, ?string $gatewayReference = null): void
    {
        $this->staged[$reference] = ['outcome' => $outcome, 'gateway_reference' => $gatewayReference];
    }

    public function verifySignature(string $payload, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        return hash_equals($this->sign($payload), $signature);
    }

    public function fetchStatus(string $reference, ?string $gatewayReference): GatewayDepositStatus
    {
        $staged = $this->staged[$reference] ?? ['outcome' => GatewayOutcome::Pending, 'gateway_reference' => null];
        $outcome = $staged['outcome'];
        $resolvedReference = $staged['gateway_reference'] ?? $gatewayReference;

        if (! $outcome->isResolved()) {
            return new GatewayDepositStatus($outcome, '', '', '', $resolvedReference, []);
        }

        $eventId = "reconcile:{$reference}";
        $payload = ['event_id' => $eventId, 'gateway_reference' => $resolvedReference];
        $raw = (string) json_encode($payload);

        return new GatewayDepositStatus($outcome, $eventId, $raw, $this->sign($raw), $resolvedReference, $payload);
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('wallet.gateway.secret'));
    }
}
