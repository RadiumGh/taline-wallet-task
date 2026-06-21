<?php

declare(strict_types=1);

namespace App\Domain\Idempotency\Exceptions;

use App\Exceptions\Contracts\HasHttpStatus;
use RuntimeException;

final class MissingIdempotencyKeyException extends RuntimeException implements HasHttpStatus
{
    public static function make(): self
    {
        return new self('The Idempotency-Key header is required for this request.');
    }

    public function httpStatus(): int
    {
        return 400;
    }

    public function errorCode(): string
    {
        return 'idempotency_key_required';
    }
}
