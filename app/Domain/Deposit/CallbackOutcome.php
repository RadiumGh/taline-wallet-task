<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

enum CallbackOutcome: string
{
    case Processed = 'processed';
    case AlreadyProcessed = 'already_processed';
}
