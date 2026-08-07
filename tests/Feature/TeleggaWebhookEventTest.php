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

it('creates the webhook events table with expected columns', function (): void {
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

it('creates webhook event table indexes and a foreign key', function (): void {
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

it('generates a UUID and relates an event to a connection', function (): void {
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

it('preserves the log after a soft deletion and removes it after a force deletion', function (): void {
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
