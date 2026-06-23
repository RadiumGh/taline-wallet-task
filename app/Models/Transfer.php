<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Transfer\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transfer extends Model
{
    protected $fillable = [
        'reference',
        'from_wallet_id',
        'to_wallet_id',
        'amount',
        'currency',
        'status',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'status' => TransferStatus::class,
        ];
    }

    public function fromWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'from_wallet_id');
    }

    public function toWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'to_wallet_id');
    }
}
