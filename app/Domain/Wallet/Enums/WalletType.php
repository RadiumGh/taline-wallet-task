<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

enum WalletType: string
{
    case User = 'user';
    case System = 'system';
}
