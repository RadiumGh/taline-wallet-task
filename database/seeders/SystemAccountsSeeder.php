<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Wallet\Enums\WalletType;
use App\Models\Wallet;
use Illuminate\Database\Seeder;

class SystemAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $codes = array_values(config('wallet.system_accounts'));
        $currencies = array_keys(config('wallet.currencies'));

        foreach ($codes as $code) {
            foreach ($currencies as $currency) {
                Wallet::query()->updateOrCreate([
                    'type' => WalletType::System->value,
                    'code' => $code,
                    'currency' => $currency,
                ], [
                    'user_id' => null,
                ]);
            }
        }
    }
}
