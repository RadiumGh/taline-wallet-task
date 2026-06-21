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
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('currency');
            $table->bigInteger('balance')->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'currency']);
        });

        DB::statement('ALTER TABLE `wallets` ADD CONSTRAINT `wallets_balance_non_negative` CHECK (`balance` >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
