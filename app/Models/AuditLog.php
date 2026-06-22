<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Observability\Exceptions\ImmutableAuditLogException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'action',
        'context',
        'request_id',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw ImmutableAuditLogException::cannotModify();
        });

        static::deleting(function (): void {
            throw ImmutableAuditLogException::cannotModify();
        });
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
