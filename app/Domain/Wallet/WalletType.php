<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

enum WalletType: string
{
    case User = 'user';
    case System = 'system';
}
