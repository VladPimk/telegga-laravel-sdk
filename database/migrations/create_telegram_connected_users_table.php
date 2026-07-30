<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создать таблицу подключений Telegga.
     */
    public function up(): void
    {
        Schema::create('telegram_connected_users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->boolean('is_connected')->default(false);
            $table->boolean('is_created')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Удалить таблицу подключений Telegga.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_connected_users');
    }
};
