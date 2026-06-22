<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Exceptions;

use App\Domain\Deposit\DepositStatus;
use App\Exceptions\Contracts\HasHttpStatus;
use RuntimeException;

final class InvalidDepositTransitionException extends RuntimeException implements HasHttpStatus
{
    public static function from(DepositStatus $current, DepositStatus $target): self
    {
        return new self("A deposit cannot move from {$current->value} to {$target->value}.");
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'invalid_deposit_transition';
    }
}
