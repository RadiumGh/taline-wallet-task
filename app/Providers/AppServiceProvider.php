<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Gateway\PaymentGateway;
use App\Domain\Gateway\SimulatedPaymentGateway;
use App\Domain\Observability\LogMetricsRecorder;
use App\Domain\Observability\MetricsRecorder;
use App\Domain\Outbox\OutboxPublisher;
use App\Domain\Outbox\QueueOutboxPublisher;
use App\Models\Deposit;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
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
            'user' => User::class,
        ]);
    }
}
