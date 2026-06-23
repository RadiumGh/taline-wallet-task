<?php

declare(strict_types=1);

namespace App\Domain\Gateway\Contracts;

use App\Domain\Gateway\Data\GatewayDepositStatus;

interface PaymentGateway
{
    public function verifySignature(string $payload, string $signature): bool;

    public function fetchStatus(string $reference, ?string $gatewayReference): GatewayDepositStatus;
}
