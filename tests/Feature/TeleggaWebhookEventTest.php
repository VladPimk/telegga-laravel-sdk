<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TeleggaWebhookEvent;
use Telegga\Laravel\Models\TelegramConnectedUser;

beforeEach(function (): void {
    Schema::enableForeignKeyConstraints();

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $botMigration = require __DIR__.'/../../database/migrations/2026_07_31_000001_create_available_telegram_bots_table.php';
    $botMigration->up();

    $connectionMigration = require __DIR__.'/../../database/migrations/2026_07_31_000002_create_telegram_connected_users_table.php';
    $connectionMigration->up();

    $eventMigration = require __DIR__.'/../../database/migrations/2026_08_05_000003_create_telegga_webhook_events_table.php';
    $eventMigration->up();

    $telegramBot = AvailableTelegramBot::query()->create(['bot_name' => 'mybot']);
    $this->connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $telegramBot->id,
    ]);
});

afterEach(function (): void {
    Schema::dropIfExists('telegga_webhook_events');
    Schema::dropIfExists('telegram_connected_users');
    Schema::dropIfExists('available_telegram_bots');
    Schema::dropIfExists('users');
});

it('создаёт таблицу событий webhook с ожидаемыми колонками', function (): void {
    expect(Schema::hasColumns('telegga_webhook_events', [
        'id',
        'uuid',
        'telegram_connected_user_id',
        'event_id',
        'event',
        'attempts',
        'first_seen_at',
        'processed_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('создаёт индексы и внешний ключ таблицы событий webhook', function (): void {
    $indexes = collect(Schema::getIndexes('telegga_webhook_events'));
    $foreignKeys = collect(Schema::getForeignKeys('telegga_webhook_events'));

    expect($indexes->contains(
        fn (array $index): bool => $index['columns'] === ['uuid']
            && $index['unique'] === false,
    ))->toBeTrue()
        ->and($indexes->contains(
            fn (array $index): bool => $index['columns'] === ['telegram_connected_user_id']
                && $index['unique'] === false,
        ))->toBeTrue()
        ->and($indexes->contains(
            fn (array $index): bool => $index['columns'] === ['event_id']
                && $index['unique'] === true,
        ))->toBeTrue()
        ->and($indexes->contains(
            fn (array $index): bool => $index['columns'] === ['event']
                && $index['unique'] === false,
        ))->toBeTrue()
        ->and($foreignKeys->contains(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['telegram_connected_user_id']
                && $foreignKey['foreign_table'] === 'telegram_connected_users'
                && $foreignKey['foreign_columns'] === ['id']
                && $foreignKey['on_delete'] === 'cascade',
        ))->toBeTrue();
});

it('генерирует uuid и связывает событие с подключением', function (): void {
    $event = TeleggaWebhookEvent::query()->create([
        'telegram_connected_user_id' => $this->connection->id,
        'event_id' => 'd5b7d0e1-0000-4000-8000-000000000001',
        'event' => 'user.linked',
        'first_seen_at' => now(),
        'processed_at' => now(),
    ]);

    expect($event->id)
        ->toBeInt()
        ->and($event->uuid)
        ->toBeString()
        ->and(Str::isUuid($event->uuid, 7))
        ->toBeTrue()
        ->and($event->attempts)
        ->toBe(1)
        ->and($event->first_seen_at)
        ->toBeInstanceOf(DateTimeInterface::class)
        ->and($event->processed_at)
        ->toBeInstanceOf(DateTimeInterface::class)
        ->and($event->connection->is($this->connection))
        ->toBeTrue()
        ->and($this->connection->webhookEvents->first()?->is($event))
        ->toBeTrue();
});

it('сохраняет журнал при мягком удалении подключения и удаляет при физическом', function (): void {
    $event = TeleggaWebhookEvent::query()->create([
        'telegram_connected_user_id' => $this->connection->id,
        'event_id' => 'd5b7d0e1-0000-4000-8000-000000000001',
        'event' => 'user.linked',
        'first_seen_at' => now(),
    ]);

    $this->connection->delete();

    expect($event->refresh()->connection?->trashed())
        ->toBeTrue();

    $this->connection->forceDelete();

    expect(TeleggaWebhookEvent::query()->whereKey($event->id)->doesntExist())
        ->toBeTrue();
});
