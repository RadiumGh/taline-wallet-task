<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Deposit\Data\GatewayCallbackData;
use App\Domain\Deposit\Data\ReconciliationReport;
use App\Domain\Deposit\Enums\DepositStatus;
use App\Domain\Deposit\Exceptions\InvalidDepositTransitionException;
use App\Domain\Gateway\Contracts\PaymentGateway;
use App\Domain\Gateway\Data\GatewayDepositStatus;
use App\Domain\Gateway\Enums\GatewayOutcome;
use App\Models\Deposit;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

final class DepositReconciliationService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly DepositCallbackService $callbacks,
    ) {}

    public function reconcile(int $olderThanMinutes, int $limit): ReconciliationReport
    {
        $deposits = Deposit::query()
            ->where('status', DepositStatus::Pending)
            ->where('created_at', '<=', now()->subMinutes($olderThanMinutes))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $confirmed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($deposits as $deposit) {
            $status = $this->gateway->fetchStatus($deposit->reference, $deposit->gateway_reference);

            if (! $status->outcome->isResolved()) {
                $skipped++;

                continue;
            }

            try {
                $this->drive($deposit, $status);
            } catch (InvalidDepositTransitionException $e) {
                $skipped++;
                $this->logConflict($deposit, $status, $e);

                continue;
            }

            $status->outcome === GatewayOutcome::Confirmed ? $confirmed++ : $failed++;
        }

        return new ReconciliationReport($confirmed, $failed, $skipped);
    }

    private function drive(Deposit $deposit, GatewayDepositStatus $status): void
    {
        $data = new GatewayCallbackData(
            rawPayload: $status->rawPayload,
            signature: $status->signature,
            gateway: $deposit->gateway,
            eventId: $status->eventId,
            gatewayReference: $status->gatewayReference,
            payload: $status->payload,
        );

        $status->outcome === GatewayOutcome::Confirmed
            ? $this->callbacks->confirm($deposit, $data)
            : $this->callbacks->fail($deposit, $data);
    }

    private function logConflict(Deposit $deposit, GatewayDepositStatus $status, InvalidDepositTransitionException $e): void
    {
        Log::warning('deposit.reconciliation.conflict', [
            'request_id' => Context::get('request_id'),
            'reference' => $deposit->reference,
            'gateway_outcome' => $status->outcome->value,
            'reason' => $e->getMessage(),
        ]);
    }
}
