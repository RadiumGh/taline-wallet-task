<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Gateway\PaymentGateway;
use App\Domain\Gateway\SimulatedPaymentGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, SimulatedPaymentGateway::class);
    }

    public function boot(): void
    {
        //
    }
}
