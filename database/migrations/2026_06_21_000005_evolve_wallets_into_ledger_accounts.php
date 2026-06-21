<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->string('type')->default('user')->after('user_id');
            $table->string('code')->nullable()->after('type');
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->unique(['code', 'currency']);
        });

        DB::statement('ALTER TABLE `wallets` DROP CHECK `wallets_balance_non_negative`');
        DB::statement("ALTER TABLE `wallets` ADD CONSTRAINT `wallets_user_balance_non_negative` CHECK (`type` <> 'user' OR `balance` >= 0)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `wallets` DROP CHECK `wallets_user_balance_non_negative`');

        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropUnique(['code', 'currency']);
            $table->dropColumn(['type', 'code']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        DB::statement('ALTER TABLE `wallets` ADD CONSTRAINT `wallets_balance_non_negative` CHECK (`balance` >= 0)');
    }
};
