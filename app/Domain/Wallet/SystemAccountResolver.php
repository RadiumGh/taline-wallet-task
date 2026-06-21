<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Wallet\Exceptions\SystemAccountNotFoundException;
use App\Models\Wallet;

final class SystemAccountResolver
{
    public function resolve(string $code, string $currency): Wallet
    {
        $wallet = Wallet::query()
            ->where('type', WalletType::System->value)
            ->where('code', $code)
            ->where('currency', $currency)
            ->first();

        if ($wallet === null) {
            throw SystemAccountNotFoundException::for($code, $currency);
        }

        return $wallet;
    }
}
