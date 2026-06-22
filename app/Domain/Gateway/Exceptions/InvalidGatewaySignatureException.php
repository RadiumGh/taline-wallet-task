<?php

declare(strict_types=1);

namespace App\Domain\Gateway\Exceptions;

use App\Exceptions\Contracts\HasHttpStatus;
use RuntimeException;

final class InvalidGatewaySignatureException extends RuntimeException implements HasHttpStatus
{
    public static function make(): self
    {
        return new self('The gateway callback signature could not be verified.');
    }

    public function httpStatus(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return 'invalid_gateway_signature';
    }
}
