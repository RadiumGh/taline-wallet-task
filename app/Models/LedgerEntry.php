<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\Exceptions\ImmutableLedgerEntryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'transaction_group',
        'wallet_id',
        'currency',
        'amount',
        'balance_after',
        'posting_key',
        'reference_type',
        'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'balance_after' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw ImmutableLedgerEntryException::cannotModify();
        });

        static::deleting(function (): void {
            throw ImmutableLedgerEntryException::cannotModify();
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function direction(): EntryDirection
    {
        return EntryDirection::fromSignedAmount($this->amount->amount);
    }
}
