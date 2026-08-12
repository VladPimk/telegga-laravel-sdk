<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the Telegga connections table.
     */
    public function up(): void
    {
        $usersTable = config(key: 'telegga.users_table');

        if (! is_string($usersTable) || trim($usersTable) === '') {
            throw new LogicException('Telegga users_table must be a non-empty table name.');
        }

        Schema::create('telegram_connected_users', function (Blueprint $table) use ($usersTable): void {
            $table->id();
            $table->uuid('uuid')->index();
            $table->string('name');
            $table->string('email')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->foreign('user_id', 'fk_telegram_connected_users_user_id')
                ->references('id')
                ->on($usersTable)
                ->nullOnDelete();
            $table->unsignedBigInteger('available_telegram_bot_id')->index();
            $table->foreign('available_telegram_bot_id', 'fk_telegram_connected_users_available_telegram_bot_id')
                ->references('id')
                ->on('available_telegram_bots')
                ->restrictOnDelete();
            $table->string('status')->default('not_created')->index();
            $table->string('link_status')->nullable()->index();
            $table->string('link_url')->nullable();
            $table->timestampTz('link_expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Drop the Telegga connections table.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_connected_users');
    }
};
