<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Deposit\Enums\DepositStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Deposit extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'wallet_id',
        'amount',
        'currency',
        'status',
        'gateway',
        'gateway_reference',
        'idempotency_key',
        'confirmed_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'status' => DepositStatus::class,
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'reference');
    }
}
