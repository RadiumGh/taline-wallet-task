<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Deposit\Enums\GatewayCallbackType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayCallback extends Model
{
    protected $fillable = [
        'deposit_id',
        'gateway',
        'event_id',
        'type',
        'payload',
        'signature',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => GatewayCallbackType::class,
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }
}
