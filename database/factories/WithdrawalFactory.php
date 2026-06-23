<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Money\ValueObjects\Money;
use App\Domain\Withdrawal\Enums\WithdrawalStatus;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Withdrawal>
 */
class WithdrawalFactory extends Factory
{
    protected $model = Withdrawal::class;

    public function definition(): array
    {
        return [
            'reference' => (string) Str::uuid(),
            'wallet_id' => Wallet::factory()->for(User::factory()),
            'amount' => Money::of(5000, 'IRR'),
            'currency' => 'IRR',
            'status' => WithdrawalStatus::Requested,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function forWallet(Wallet $wallet, int $amount = 5000): static
    {
        return $this->state(fn (): array => [
            'wallet_id' => $wallet->getKey(),
            'amount' => Money::of($amount, $wallet->currency),
            'currency' => $wallet->currency,
        ]);
    }
}
