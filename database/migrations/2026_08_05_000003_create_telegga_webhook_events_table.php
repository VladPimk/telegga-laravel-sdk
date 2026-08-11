<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the Telegga webhook events table.
     */
    public function up(): void
    {
        Schema::create('telegga_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->index();
            $table->unsignedBigInteger('telegram_connected_user_id')->index();
            $table->foreign('telegram_connected_user_id', 'fk_telegga_webhook_events_telegram_connected_user_id')
                ->references('id')
                ->on('telegram_connected_users')
                ->cascadeOnDelete();
            $table->string('event_id')->unique();
            $table->string('event')->index();
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    /**
     * Drop the Telegga webhook events table.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegga_webhook_events');
    }
};
