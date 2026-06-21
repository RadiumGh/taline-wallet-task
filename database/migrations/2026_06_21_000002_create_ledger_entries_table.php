<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('transaction_group');
            $table->foreignId('wallet_id')->constrained();
            $table->string('currency');
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->morphs('reference');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'created_at', 'id'], 'ledger_entries_wallet_history_index');
            $table->index('transaction_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
