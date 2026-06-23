<?php

declare(strict_types=1);

namespace App\Domain\Withdrawal;

use App\Domain\Observability\Measurement;
use App\Domain\Observability\OperationEvent;
use App\Models\User;
use App\Models\Withdrawal;

final class WithdrawalEvent
{
    public static function requested(Withdrawal $withdrawal, ?User $actor = null): OperationEvent
    {
        return new OperationEvent('withdrawal.requested', $withdrawal, self::context($withdrawal), $actor, self::volume($withdrawal));
    }

    public static function approved(Withdrawal $withdrawal, ?User $actor = null): OperationEvent
    {
        return new OperationEvent('withdrawal.approved', $withdrawal, self::context($withdrawal), $actor);
    }

    public static function settled(Withdrawal $withdrawal, ?User $actor = null): OperationEvent
    {
        return new OperationEvent('withdrawal.settled', $withdrawal, self::context($withdrawal), $actor);
    }

    public static function rejected(Withdrawal $withdrawal, ?User $actor = null): OperationEvent
    {
        return new OperationEvent('withdrawal.rejected', $withdrawal, self::context($withdrawal), $actor);
    }

    /**
     * @return array<string, mixed>
     */
    private static function context(Withdrawal $withdrawal): array
    {
        return [
            'reference' => $withdrawal->reference,
            'wallet_id' => $withdrawal->wallet_id,
            'amount' => $withdrawal->amount->amount,
            'currency' => $withdrawal->amount->currency->code,
            'status' => $withdrawal->status->value,
        ];
    }

    private static function volume(Withdrawal $withdrawal): Measurement
    {
        return new Measurement('withdrawal.volume', (float) $withdrawal->amount->amount, ['currency' => $withdrawal->amount->currency->code]);
    }
}
