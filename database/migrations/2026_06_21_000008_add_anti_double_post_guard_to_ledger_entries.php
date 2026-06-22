<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->unique(['reference_type', 'reference_id', 'wallet_id'], 'ledger_entries_reference_wallet_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropUnique('ledger_entries_reference_wallet_unique');
        });
    }
};
