<?php

declare(strict_types=1);

use App\Domain\Idempotency\IdempotencyStatus;
use App\Models\IdempotencyKey;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Concurrency tests require a real MySQL connection.');
    }

    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required to fork parallel workers.');
    }
});

test('concurrent duplicate transfers sharing one key move money exactly once', function () {
    $sender = User::factory()->create();
    $senderWallet = fundedWallet($sender, 1000);
    $receiver = Wallet::factory()->create(['currency' => 'IRR']);

    runInParallel(6, function () use ($sender, $receiver): void {
        test()
            ->withHeader('X-User-Id', (string) $sender->getKey())
            ->withHeader('Idempotency-Key', 'shared-retry-key')
            ->postJson('/api/transfers', [
                'to_wallet_id' => $receiver->getKey(),
                'amount' => 400,
                'currency' => 'IRR',
            ]);
    });

    expect($senderWallet->refresh()->balance->amount)->toBe(600)
        ->and($receiver->refresh()->balance->amount)->toBe(400)
        ->and(Transfer::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->where('status', IdempotencyStatus::Completed->value)->count())->toBe(1);
});
