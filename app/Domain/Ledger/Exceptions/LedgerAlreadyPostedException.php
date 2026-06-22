<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exceptions;

use App\Exceptions\Contracts\HasHttpStatus;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class LedgerAlreadyPostedException extends RuntimeException implements HasHttpStatus
{
    public static function forReference(Model $reference): self
    {
        return new self("A ledger movement for [{$reference->getMorphClass()}#{$reference->getKey()}] has already been posted.");
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'ledger_already_posted';
    }
}
