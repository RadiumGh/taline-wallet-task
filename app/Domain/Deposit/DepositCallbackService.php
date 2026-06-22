<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Deposit\Exceptions\InvalidDepositTransitionException;
use App\Domain\Gateway\Exceptions\InvalidGatewaySignatureException;
use App\Domain\Gateway\PaymentGateway;
use App\Domain\Ledger\LedgerLeg;
use App\Domain\Ledger\LedgerService;
use App\Domain\Wallet\SystemAccountResolver;
use App\Models\Deposit;
use App\Models\GatewayCallback;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DepositCallbackService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly LedgerService $ledger,
        private readonly SystemAccountResolver $systemAccounts,
    ) {}

    public function confirm(Deposit $deposit, GatewayCallbackData $data): CallbackOutcome
    {
        return $this->handle($deposit, DepositStatus::Confirmed, GatewayCallbackType::Confirm, $data, function (Deposit $locked): void {
            $clearing = $this->systemAccounts->resolve(
                config('wallet.system_accounts.gateway_clearing'),
                $locked->currency,
            );

            $this->ledger->post($locked, [
                LedgerLeg::credit($locked->wallet, $locked->amount),
                LedgerLeg::debit($clearing, $locked->amount),
            ]);

            $locked->confirmed_at = now();
        });
    }

    public function fail(Deposit $deposit, GatewayCallbackData $data): CallbackOutcome
    {
        return $this->handle($deposit, DepositStatus::Failed, GatewayCallbackType::Fail, $data, function (Deposit $locked): void {
            $locked->failed_at = now();
        });
    }

    private function handle(Deposit $deposit, DepositStatus $target, GatewayCallbackType $type, GatewayCallbackData $data, Closure $applyEffect): CallbackOutcome
    {
        if (! $this->gateway->verifySignature($data->rawPayload, $data->signature)) {
            throw InvalidGatewaySignatureException::make();
        }

        if ($this->alreadyRecorded($data)) {
            return CallbackOutcome::AlreadyProcessed;
        }

        return DB::transaction(function () use ($deposit, $target, $type, $data, $applyEffect): CallbackOutcome {
            $locked = Deposit::query()->lockForUpdate()->findOrFail($deposit->getKey());

            $callback = $this->record($locked, $type, $data);

            if ($callback === null) {
                return CallbackOutcome::AlreadyProcessed;
            }

            if ($locked->status === $target) {
                return CallbackOutcome::AlreadyProcessed;
            }

            if (! $locked->status->canTransitionTo($target)) {
                throw InvalidDepositTransitionException::from($locked->status, $target);
            }

            $applyEffect($locked);
            $locked->status = $target;
            $locked->gateway_reference = $data->gatewayReference ?? $locked->gateway_reference;
            $locked->save();

            $callback->processed_at = now();
            $callback->save();

            return CallbackOutcome::Processed;
        }, attempts: 3);
    }

    private function alreadyRecorded(GatewayCallbackData $data): bool
    {
        return GatewayCallback::query()
            ->where('gateway', $data->gateway)
            ->where('event_id', $data->eventId)
            ->exists();
    }

    private function record(Deposit $deposit, GatewayCallbackType $type, GatewayCallbackData $data): ?GatewayCallback
    {
        try {
            return GatewayCallback::create([
                'deposit_id' => $deposit->getKey(),
                'gateway' => $data->gateway,
                'event_id' => $data->eventId,
                'type' => $type,
                'payload' => $data->payload,
                'signature' => $data->signature,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
