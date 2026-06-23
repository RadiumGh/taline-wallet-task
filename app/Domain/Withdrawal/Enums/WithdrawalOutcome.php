<?php

declare(strict_types=1);

namespace App\Domain\Withdrawal\Enums;

enum WithdrawalOutcome: string
{
    case Processed = 'processed';
    case AlreadyProcessed = 'already_processed';
}
