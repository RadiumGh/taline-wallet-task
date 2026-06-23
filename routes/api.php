<?php

declare(strict_types=1);

use App\Http\Controllers\DepositCallbackController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\PingController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\WalletTransactionController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Controllers\WithdrawalReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.header')->group(function (): void {
    Route::get('/ping', PingController::class)->name('ping');
    Route::post('/transfers', [TransferController::class, 'store'])
        ->middleware(['throttle:wallet-writes', 'idempotency'])
        ->name('transfers.store');

    Route::post('/deposits', [DepositController::class, 'store'])
        ->middleware(['throttle:wallet-writes', 'idempotency'])
        ->name('deposits.store');

    Route::get('/wallets/{wallet}/transactions', [WalletTransactionController::class, 'index'])
        ->middleware('throttle:wallet-reads')
        ->name('wallets.transactions.index');

    Route::post('/withdrawals', [WithdrawalController::class, 'store'])
        ->middleware(['throttle:wallet-writes', 'idempotency'])
        ->name('withdrawals.store');

    Route::middleware('throttle:wallet-writes')->prefix('admin/withdrawals')->name('admin.withdrawals.')->group(function (): void {
        Route::post('/{withdrawal}/approve', [WithdrawalReviewController::class, 'approve'])->name('approve');
        Route::post('/{withdrawal}/settle', [WithdrawalReviewController::class, 'settle'])->name('settle');
        Route::post('/{withdrawal}/reject', [WithdrawalReviewController::class, 'reject'])->name('reject');
    });
});

Route::middleware('throttle:gateway-callbacks')->group(function (): void {
    Route::post('/deposits/{deposit}/callbacks/confirm', [DepositCallbackController::class, 'confirm'])
        ->name('deposits.callbacks.confirm');

    Route::post('/deposits/{deposit}/callbacks/fail', [DepositCallbackController::class, 'fail'])
        ->name('deposits.callbacks.fail');
});
