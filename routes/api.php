<?php

declare(strict_types=1);

use App\Http\Controllers\DepositController;
use App\Http\Controllers\PingController;
use App\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.header')->group(function (): void {
    Route::get('/ping', PingController::class)->name('ping');
    Route::post('/transfers', [TransferController::class, 'store'])
        ->middleware('idempotency')
        ->name('transfers.store');

    Route::post('/deposits', [DepositController::class, 'store'])
        ->middleware('idempotency')
        ->name('deposits.store');
});
