<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Deposit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Deposit
 */
class DepositResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'wallet_id' => $this->wallet_id,
            'amount' => $this->amount->amount,
            'currency' => $this->amount->currency->code,
            'status' => $this->status->value,
            'gateway' => $this->gateway,
            'gateway_reference' => $this->gateway_reference,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
