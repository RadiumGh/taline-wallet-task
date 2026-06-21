<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Exceptions;

use App\Exceptions\Contracts\HasHttpStatus;
use RuntimeException;

final class SelfTransferException extends RuntimeException implements HasHttpStatus
{
    public static function make(): self
    {
        return new self('A transfer must move funds between two different wallets.');
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'self_transfer';
    }
}
