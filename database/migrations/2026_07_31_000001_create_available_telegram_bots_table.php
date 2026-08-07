<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the available Telegram bots table.
     */
    public function up(): void
    {
        Schema::create('available_telegram_bots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->index();
            $table->string('bot_name')->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Drop the available Telegram bots table.
     */
    public function down(): void
    {
        Schema::dropIfExists('available_telegram_bots');
    }
};
