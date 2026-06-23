<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Observability\Measurement;
use App\Domain\Observability\OperationEvent;
use App\Models\Deposit;
use App\Models\User;

final class DepositEvent
{
    public static function created(Deposit $deposit, ?User $actor = null): OperationEvent
    {
        return new OperationEvent('deposit.created', $deposit, self::context($deposit), $actor);
    }

    public static function confirmed(Deposit $deposit): OperationEvent
    {
        return new OperationEvent('deposit.confirmed', $deposit, self::context($deposit), measurement: self::volume($deposit));
    }

    public static function failed(Deposit $deposit): OperationEvent
    {
        return new OperationEvent('deposit.failed', $deposit, self::context($deposit));
    }

    /**
     * @return array<string, mixed>
     */
    private static function context(Deposit $deposit): array
    {
        return [
            'reference' => $deposit->reference,
            'wallet_id' => $deposit->wallet_id,
            'amount' => $deposit->amount->amount,
            'currency' => $deposit->amount->currency->code,
        ];
    }

    private static function volume(Deposit $deposit): Measurement
    {
        return new Measurement('deposit.volume', (float) $deposit->amount->amount, ['currency' => $deposit->amount->currency->code]);
    }
}
