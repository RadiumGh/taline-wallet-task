<?php

declare(strict_types=1);

namespace App\Domain\Withdrawal\Exceptions;

use App\Domain\Withdrawal\Enums\WithdrawalStatus;
use App\Exceptions\Contracts\HasHttpStatus;
use RuntimeException;

final class InvalidWithdrawalTransitionException extends RuntimeException implements HasHttpStatus
{
    public static function from(WithdrawalStatus $current, WithdrawalStatus $target): self
    {
        return new self("A withdrawal cannot move from {$current->value} to {$target->value}.");
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'invalid_withdrawal_transition';
    }
}
