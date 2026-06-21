<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

enum TransferStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
}
