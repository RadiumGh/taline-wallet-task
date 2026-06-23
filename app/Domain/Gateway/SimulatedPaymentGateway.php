<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Domain\Gateway\Contracts\PaymentGateway;
use App\Domain\Gateway\Data\GatewayDepositStatus;
use App\Domain\Gateway\Enums\GatewayOutcome;

final class SimulatedPaymentGateway implements PaymentGateway
{
    public function verifySignature(string $payload, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $secret = (string) config('wallet.gateway.secret');
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    public function fetchStatus(string $reference, ?string $gatewayReference): GatewayDepositStatus
    {
        return new GatewayDepositStatus(GatewayOutcome::Pending, '', '', '', $gatewayReference, []);
    }
}
