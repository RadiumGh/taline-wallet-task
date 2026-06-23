<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Idempotency\Enums\IdempotencyStatus;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $fillable = [
        'scope',
        'key',
        'request_hash',
        'method',
        'path',
        'status',
        'response_status',
        'response_body',
        'locked_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => IdempotencyStatus::class,
            'response_status' => 'integer',
            'response_body' => 'array',
            'locked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
