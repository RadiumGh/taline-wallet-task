<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LedgerEntry
 */
class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_group' => $this->transaction_group,
            'amount' => $this->amount->amount,
            'currency' => $this->amount->currency->code,
            'balance_after' => $this->balance_after->amount,
            'direction' => $this->direction()->value,
            'reference_type' => strtolower(class_basename($this->reference_type)),
            'reference' => $this->reference?->reference,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
