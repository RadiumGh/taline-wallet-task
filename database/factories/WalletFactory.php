<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Wallet\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => WalletType::User->value,
            'currency' => 'IRR',
        ];
    }

    public function system(string $code = 'gateway_clearing'): static
    {
        return $this->state(fn (): array => [
            'user_id' => null,
            'type' => WalletType::System->value,
            'code' => $code,
        ]);
    }
}
