<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TeleggaWebhookEvent;
use Telegga\Laravel\Models\TelegramConnectedUser;

beforeEach(function (): void {
    $telegramBot = AvailableTelegramBot::query()->create(['bot_name' => 'mybot']);
    $this->connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $telegramBot->id,
    ]);
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
