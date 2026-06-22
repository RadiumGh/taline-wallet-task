<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->string('idempotency_key')->nullable(false)->change();
            $table->unique(['from_wallet_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropUnique(['from_wallet_id', 'idempotency_key']);
            $table->string('idempotency_key')->nullable()->change();
        });
    }
};
