<?php

declare(strict_types=1);

use App\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.header')->group(function (): void {
    Route::get('/ping', PingController::class)->name('ping');
});
