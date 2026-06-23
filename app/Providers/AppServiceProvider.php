<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Gateway\Contracts\PaymentGateway;
use App\Domain\Gateway\SimulatedPaymentGateway;
use App\Domain\Observability\Contracts\MetricsRecorder;
use App\Domain\Observability\LogMetricsRecorder;
use App\Domain\Outbox\Contracts\OutboxPublisher;
use App\Domain\Outbox\QueueOutboxPublisher;
use App\Http\Middleware\AuthenticateWithUserHeader;
use App\Models\Deposit;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, SimulatedPaymentGateway::class);
        $this->app->bind(OutboxPublisher::class, QueueOutboxPublisher::class);
        $this->app->bind(MetricsRecorder::class, LogMetricsRecorder::class);
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'deposit' => Deposit::class,
            'transfer' => Transfer::class,
            'withdrawal' => Withdrawal::class,
            'user' => User::class,
        ]);

        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        $perCaller = fn (string $limit): callable => fn (Request $request): Limit => Limit::perMinute((int) config("wallet.rate_limits.{$limit}"))
            ->by($this->callerKey($request));

        RateLimiter::for('wallet-writes', $perCaller('writes'));
        RateLimiter::for('wallet-reads', $perCaller('reads'));
        RateLimiter::for('gateway-callbacks', fn (Request $request): Limit => Limit::perMinute((int) config('wallet.rate_limits.callbacks'))
            ->by((string) $request->ip()));
    }

    private function callerKey(Request $request): string
    {
        return (string) ($request->header(AuthenticateWithUserHeader::HEADER) ?: $request->ip());
    }
}
