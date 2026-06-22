<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_callbacks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deposit_id')->constrained('deposits');
            $table->string('gateway');
            $table->string('event_id');
            $table->string('type');
            $table->json('payload');
            $table->string('signature');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_callbacks');
    }
};
