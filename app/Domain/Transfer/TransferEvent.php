<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

use App\Domain\Observability\Measurement;
use App\Domain\Observability\OperationEvent;
use App\Models\Transfer;
use App\Models\User;

final class TransferEvent
{
    public static function completed(Transfer $transfer, ?User $actor = null): OperationEvent
    {
        return new OperationEvent('transfer.completed', $transfer, self::context($transfer), $actor, self::volume($transfer));
    }

    /**
     * @return array<string, mixed>
     */
    private static function context(Transfer $transfer): array
    {
        return [
            'reference' => $transfer->reference,
            'from_wallet_id' => $transfer->from_wallet_id,
            'to_wallet_id' => $transfer->to_wallet_id,
            'amount' => $transfer->amount->amount,
            'currency' => $transfer->amount->currency->code,
        ];
    }

    private static function volume(Transfer $transfer): Measurement
    {
        return new Measurement('transfer.volume', (float) $transfer->amount->amount, ['currency' => $transfer->amount->currency->code]);
    }
}
