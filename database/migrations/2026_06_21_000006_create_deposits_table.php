<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('wallet_id')->constrained('wallets');
            $table->bigInteger('amount');
            $table->string('currency');
            $table->string('status')->default('pending');
            $table->string('gateway');
            $table->string('gateway_reference')->nullable();
            $table->string('idempotency_key');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['wallet_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
