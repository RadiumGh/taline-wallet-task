<?php

declare(strict_types=1);

namespace App\Domain\Observability\Exceptions;

use RuntimeException;

final class ImmutableAuditLogException extends RuntimeException
{
    public static function cannotModify(): self
    {
        return new self('Audit logs are append-only and cannot be modified or deleted.');
    }
}
