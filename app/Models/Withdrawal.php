<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Withdrawal\WithdrawalStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Withdrawal extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'wallet_id',
        'amount',
        'currency',
        'status',
        'idempotency_key',
        'reason',
        'reviewed_by',
        'approved_at',
        'settled_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'status' => WithdrawalStatus::class,
            'approved_at' => 'datetime',
            'settled_at' => 'datetime',
            'rejected_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'reference');
    }
}
