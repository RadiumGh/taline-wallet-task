<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->string('dedupe_key')->unique();
            $table->morphs('aggregate');
            $table->string('event_type');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->timestamp('available_at');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at', 'id'], 'outbox_events_relay_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
