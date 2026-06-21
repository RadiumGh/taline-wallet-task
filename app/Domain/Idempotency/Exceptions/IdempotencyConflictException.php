<?php

declare(strict_types=1);

namespace App\Domain\Idempotency\Exceptions;

use App\Exceptions\Contracts\HasHttpStatus;
use RuntimeException;

final class IdempotencyConflictException extends RuntimeException implements HasHttpStatus
{
    public static function keyReused(): self
    {
        return new self('This Idempotency-Key has already been used with a different request.');
    }

    public static function inFlight(): self
    {
        return new self('A request with this Idempotency-Key is still being processed.');
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'idempotency_conflict';
    }
}
