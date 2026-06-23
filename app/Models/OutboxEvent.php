<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Outbox\Enums\OutboxStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OutboxEvent extends Model
{
    protected $fillable = [
        'dedupe_key',
        'event_type',
        'payload',
        'status',
        'available_at',
        'published_at',
        'attempts',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxStatus::class,
            'available_at' => 'datetime',
            'published_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function aggregate(): MorphTo
    {
        return $this->morphTo();
    }
}
