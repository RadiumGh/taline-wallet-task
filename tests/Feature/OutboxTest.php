<?php

declare(strict_types=1);

use App\Domain\Deposit\DepositCallbackService;
use App\Domain\Deposit\DepositStatus;
use App\Domain\Deposit\GatewayCallbackData;
use App\Domain\Ledger\Exceptions\InsufficientFundsException;
use App\Domain\Money\Money;
use App\Domain\Outbox\OutboxPublisher;
use App\Domain\Outbox\OutboxRecorder;
use App\Domain\Outbox\OutboxRelay;
use App\Domain\Outbox\OutboxStatus;
use App\Domain\Transfer\TransferService;
use App\Domain\Transfer\TransferStatus;
use App\Jobs\SendOutboxNotification;
use App\Models\Deposit;
use App\Models\OutboxEvent;
use App\Models\OutboxNotification;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function makeTransfer(int $amount = 400): Transfer
{
    $sender = User::factory()->create();
    fundedWallet($sender, 1000);
    $to = Wallet::factory()->create(['currency' => 'IRR']);

    return app(TransferService::class)->transfer($sender, $to->getKey(), $amount, 'IRR');
}

function relay(): int
{
    return app(OutboxRelay::class)->relay((int) config('wallet.outbox.batch_size'));
}

test('a completed transfer records exactly one pending outbox event', function () {
    makeTransfer();

    $events = OutboxEvent::query()->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->event_type)->toBe('transfer.completed')
        ->and($events->first()->status)->toBe(OutboxStatus::Pending)
        ->and($events->first()->aggregate)->toBeInstanceOf(Transfer::class);
});

test('a confirmed deposit records a deposit.confirmed outbox event', function () {
    $this->seed(SystemAccountsSeeder::class);
    config()->set('wallet.gateway.secret', 'secret');
    $deposit = Deposit::create([
        'reference' => (string) Str::uuid(),
        'wallet_id' => Wallet::factory()->for(User::factory())->create(['currency' => 'IRR'])->getKey(),
        'amount' => Money::of(5000, 'IRR'),
        'currency' => 'IRR',
        'status' => DepositStatus::Pending,
        'gateway' => 'simulated',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $payload = ['event_id' => 'evt-1'];
    $raw = json_encode($payload);
    app(DepositCallbackService::class)->confirm($deposit, new GatewayCallbackData(
        rawPayload: $raw,
        signature: hash_hmac('sha256', $raw, 'secret'),
        gateway: 'simulated',
        eventId: 'evt-1',
        gatewayReference: null,
        payload: $payload,
    ));

    expect(OutboxEvent::query()->where('event_type', 'deposit.confirmed')->count())->toBe(1);
});

test('a rolled-back money write leaves no outbox event', function () {
    $sender = User::factory()->create();
    fundedWallet($sender, 100);
    $to = Wallet::factory()->create(['currency' => 'IRR']);

    expect(fn () => app(TransferService::class)->transfer($sender, $to->getKey(), 400, 'IRR'))
        ->toThrow(InsufficientFundsException::class);

    expect(OutboxEvent::query()->count())->toBe(0)
        ->and(Transfer::query()->count())->toBe(0);
});

test('the relay publishes a pending event once and is idempotent on re-run', function () {
    Queue::fake();
    makeTransfer();

    expect(relay())->toBe(1);

    $event = OutboxEvent::query()->firstOrFail();
    expect($event->status)->toBe(OutboxStatus::Published)
        ->and($event->published_at)->not->toBeNull();
    Queue::assertPushed(SendOutboxNotification::class, 1);

    expect(relay())->toBe(0);
    Queue::assertPushed(SendOutboxNotification::class, 1);
});

test('a failing publisher backs off and keeps the event pending', function () {
    config()->set('wallet.outbox.max_attempts', 5);
    $this->app->bind(OutboxPublisher::class, fn (): OutboxPublisher => new class implements OutboxPublisher
    {
        public function publish(OutboxEvent $event): void
        {
            throw new RuntimeException('broker unavailable');
        }
    });
    makeTransfer();

    expect(relay())->toBe(0);

    $event = OutboxEvent::query()->firstOrFail();
    expect($event->status)->toBe(OutboxStatus::Pending)
        ->and($event->attempts)->toBe(1)
        ->and($event->last_error)->toBe('broker unavailable')
        ->and($event->available_at->isFuture())->toBeTrue();
});

test('a publisher that keeps failing eventually marks the event failed', function () {
    config()->set('wallet.outbox.max_attempts', 1);
    $this->app->bind(OutboxPublisher::class, fn (): OutboxPublisher => new class implements OutboxPublisher
    {
        public function publish(OutboxEvent $event): void
        {
            throw new RuntimeException('broker unavailable');
        }
    });
    makeTransfer();

    relay();

    $event = OutboxEvent::query()->firstOrFail();
    expect($event->status)->toBe(OutboxStatus::Failed)
        ->and($event->attempts)->toBe(1)
        ->and($event->last_error)->toBe('broker unavailable');
});

test('the dedupe key prevents duplicate outbox rows', function () {
    $transfer = Transfer::create([
        'reference' => (string) Str::uuid(),
        'from_wallet_id' => Wallet::factory()->create(['currency' => 'IRR'])->getKey(),
        'to_wallet_id' => Wallet::factory()->create(['currency' => 'IRR'])->getKey(),
        'amount' => Money::of(100, 'IRR'),
        'currency' => 'IRR',
        'status' => TransferStatus::Completed,
    ]);
    $recorder = app(OutboxRecorder::class);

    $first = $recorder->record($transfer, 'transfer.completed', 'dupe-key', []);
    $second = $recorder->record($transfer, 'transfer.completed', 'dupe-key', []);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(OutboxEvent::query()->count())->toBe(1);
});

test('the notification consumer is idempotent when handled twice', function () {
    $job = new SendOutboxNotification('event-key', 'transfer.completed', ['amount' => 100]);

    $job->handle();
    $job->handle();

    expect(OutboxNotification::query()->count())->toBe(1)
        ->and(OutboxNotification::query()->first()->dedupe_key)->toBe('event-key');
});
