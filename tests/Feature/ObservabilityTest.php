<?php

declare(strict_types=1);

use App\Domain\Money\ValueObjects\Money;
use App\Domain\Observability\AuditLogger;
use App\Domain\Observability\Contracts\MetricsRecorder;
use App\Domain\Observability\Exceptions\ImmutableAuditLogException;
use App\Domain\Observability\InMemoryMetricsRecorder;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function fakeMetrics(): InMemoryMetricsRecorder
{
    $metrics = new InMemoryMetricsRecorder;
    app()->instance(MetricsRecorder::class, $metrics);

    return $metrics;
}

function completeTransfer(): TestResponse
{
    $sender = User::factory()->create();
    fundedWallet($sender, 1000);
    $to = Wallet::factory()->create(['currency' => 'IRR']);

    return test()
        ->withHeader('X-User-Id', (string) $sender->getKey())
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/transfers', ['to_wallet_id' => $to->getKey(), 'amount' => 400, 'currency' => 'IRR']);
}

function confirmDeposit(): array
{
    config()->set('wallet.gateway.secret', 'secret');
    $deposit = Deposit::factory()->create();
    $payload = ['event_id' => 'evt-1'];
    $raw = json_encode($payload);
    $response = test()
        ->withHeader('X-Gateway-Signature', hash_hmac('sha256', $raw, 'secret'))
        ->postJson("/api/deposits/{$deposit->reference}/callbacks/confirm", $payload);

    return [$deposit, $response];
}

test('a completed transfer writes an audit row carrying the request id', function () {
    $response = completeTransfer()->assertCreated();
    $transfer = Transfer::query()->firstOrFail();

    $audit = AuditLog::query()
        ->where('action', 'transfer.completed')
        ->where('subject_type', $transfer->getMorphClass())
        ->where('subject_id', $transfer->getKey())
        ->firstOrFail();

    $senderId = Wallet::query()->find($transfer->from_wallet_id)->user_id;

    expect($audit->request_id)->toBe($response->headers->get('X-Request-Id'))
        ->and($audit->request_id)->not->toBeNull()
        ->and($audit->context['amount'])->toBe(400)
        ->and($audit->actor_type)->toBe((new User)->getMorphClass())
        ->and($audit->actor_id)->toBe($senderId);
});

test('a confirmed deposit writes an audit row with the request id', function () {
    $this->seed(SystemAccountsSeeder::class);
    [$deposit, $response] = confirmDeposit();
    $response->assertOk();

    $audit = AuditLog::query()
        ->where('action', 'deposit.confirmed')
        ->where('subject_id', $deposit->getKey())
        ->firstOrFail();

    expect($audit->request_id)->toBe($response->headers->get('X-Request-Id'))
        ->and($audit->request_id)->not->toBeNull();
});

test('a transfer records counter and business volume metrics', function () {
    $metrics = fakeMetrics();

    completeTransfer()->assertCreated();

    expect($metrics->countOf('transfer.completed'))->toBe(1)
        ->and($metrics->lastHistogram('transfer.volume'))->toBe(400.0);
});

test('a confirmed deposit records counter and volume metrics', function () {
    $this->seed(SystemAccountsSeeder::class);
    $metrics = fakeMetrics();

    [, $response] = confirmDeposit();
    $response->assertOk();

    expect($metrics->countOf('deposit.confirmed'))->toBe(1)
        ->and($metrics->lastHistogram('deposit.volume'))->toBe(5000.0);
});

test('money operation logs carry the request id and no secrets', function () {
    Log::spy();

    completeTransfer()->assertCreated();

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'transfer.completed'
                && isset($context['request_id'])
                && ! array_key_exists('signature', $context)
                && ! array_key_exists('secret', $context);
        })
        ->atLeast()->once();
});

test('audit logs are append-only and reject modification', function () {
    $transfer = Transfer::create([
        'reference' => (string) Str::uuid(),
        'from_wallet_id' => Wallet::factory()->create(['currency' => 'IRR'])->getKey(),
        'to_wallet_id' => Wallet::factory()->create(['currency' => 'IRR'])->getKey(),
        'amount' => Money::of(1, 'IRR'),
        'currency' => 'IRR',
        'status' => 'completed',
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $audit = app(AuditLogger::class)->record('transfer.completed', $transfer);

    $audit->action = 'tampered';
    $audit->save();
})->throws(ImmutableAuditLogException::class);
