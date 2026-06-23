<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Enums;

enum GatewayCallbackType: string
{
    case Confirm = 'confirm';
    case Fail = 'fail';
}
