<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the Telegram bot display name.
     */
    public function up(): void
    {
        Schema::table('available_telegram_bots', function (Blueprint $table): void {
            $table->string('display_name')->nullable()->after('bot_name');
        });
    }

    /**
     * Remove the Telegram bot display name.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('available_telegram_bots', 'display_name')) {
            return;
        }

        Schema::table('available_telegram_bots', function (Blueprint $table): void {
            $table->dropColumn('display_name');
        });
    }
};
