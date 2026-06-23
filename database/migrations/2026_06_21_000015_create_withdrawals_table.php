<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('wallet_id')->constrained('wallets');
            $table->bigInteger('amount');
            $table->string('currency');
            $table->string('status')->default('requested');
            $table->string('idempotency_key');
            $table->string('reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->unique(['wallet_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
