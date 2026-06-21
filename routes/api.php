<?php

declare(strict_types=1);

use App\Http\Controllers\PingController;
use App\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.header')->group(function (): void {
    Route::get('/ping', PingController::class)->name('ping');
    Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
});
