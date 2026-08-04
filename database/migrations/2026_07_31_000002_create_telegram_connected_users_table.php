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
            $table->uuid('uuid')->index();
            $table->string('name');
            $table->string('email')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->foreign('user_id', 'fk_telegram_connected_users_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->unsignedBigInteger('available_telegram_bot_id')->index();
            $table->foreign('available_telegram_bot_id', 'fk_telegram_connected_users_available_telegram_bot_id')
                ->references('id')
                ->on('available_telegram_bots')
                ->restrictOnDelete();
            $table->boolean('is_connected')->default(false);
            $table->boolean('is_created')->default(false);
            $table->timestamps();
            $table->softDeletes();
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
