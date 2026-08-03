<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Telegga\Laravel\Models\AvailableTelegramBot;
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

    $this->telegramBot = AvailableTelegramBot::query()->create(['bot_name' => 'mybot']);
    $this->eventId = 'd5b7d0e1-0000-4000-8000-000000000001';
});

afterEach(function (): void {
    Schema::dropIfExists('telegram_connected_users');
    Schema::dropIfExists('available_telegram_bots');
    Schema::dropIfExists('users');
});

it('регистрирует маршрут webhook по ожидаемому адресу', function (): void {
    $route = Route::getRoutes()->getByName('telegga.webhooks.connect-account');

    expect($route)
        ->not->toBeNull()
        ->and($route?->uri())
        ->toBe('webhooks/v1/telegram/connect-account')
        ->and($route?->methods())
        ->toContain('POST');
});

it('принимает событие подключения и идемпотентно возвращает результат', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);
    $payload = [
        'event' => 'user.linked',
        'event_id' => $this->eventId,
        'service_id' => '0a1f376a-0000-4000-8000-000000000001',
        'user_id' => 'b7c0d091-0000-4000-8000-000000000001',
        'external_id' => $connection->uuid,
        'bot_id' => 'b2040855-0000-4000-8000-000000000001',
        'bot_username' => 'mybot',
        'telegram_user_id' => 6141109792,
        'linked_at' => '2026-07-22T10:15:00Z',
    ];
    $expectedResponse = [
        'success' => true,
        'event' => 'user.linked',
        'event_id' => $this->eventId,
        'message' => 'Telegram connection marked as connected.',
        'data' => [
            'external_id' => $connection->uuid,
            'bot_username' => 'mybot',
            'is_connected' => true,
        ],
    ];

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', $payload)
        ->assertOk()
        ->assertExactJson($expectedResponse);

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', $payload)
        ->assertOk()
        ->assertExactJson($expectedResponse);

    expect($connection->refresh()->is_connected)
        ->toBeTrue()
        ->and(TelegramConnectedUser::query()->count())
        ->toBe(1);
});

it('принимает тестовое событие и возвращает результат без изменения подключений', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', [
            'event' => 'test',
            'service_id' => '0a1f376a-0000-4000-8000-000000000001',
            'sent_at' => '2026-07-22T10:15:00Z',
        ])
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'event' => 'test',
            'message' => 'Webhook accepted.',
        ]);

    expect($connection->refresh()->is_connected)
        ->toBeFalse();
});

it('возвращает ошибку для неизвестного external id', function (): void {
    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', [
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'external_id' => 'unknown-external-id',
            'bot_username' => 'mybot',
        ])
        ->assertNotFound()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'error' => [
                'code' => 'connection_not_found',
                'message' => 'Connection was not found for the provided external_id and bot_username.',
            ],
        ]);

    expect(TelegramConnectedUser::query()->doesntExist())
        ->toBeTrue();
});

it('возвращает ошибку при несовпадении имени бота', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', [
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'external_id' => $connection->uuid,
            'bot_username' => '@mybot',
        ])
        ->assertNotFound()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'error' => [
                'code' => 'connection_not_found',
                'message' => 'Connection was not found for the provided external_id and bot_username.',
            ],
        ]);

    expect($connection->refresh()->is_connected)
        ->toBeFalse();
});

it('отклоняет неизвестное событие', function (): void {
    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', [
            'event' => 'user.updated',
        ])
        ->assertBadRequest()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.updated',
            'error' => [
                'code' => 'unsupported_event',
                'message' => 'Webhook event is not supported.',
            ],
        ]);
});

it('отклоняет webhook без bearer токена', function (): void {
    $this
        ->postJson('/webhooks/v1/telegram/connect-account', [
            'event' => 'test',
        ])
        ->assertUnauthorized()
        ->assertExactJson([
            'success' => false,
            'error' => [
                'code' => 'unauthorized',
                'message' => 'Invalid webhook token.',
            ],
        ]);
});

it('отклоняет webhook с неверным bearer токеном', function (): void {
    $this
        ->withToken('wrong-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', [
            'event' => 'test',
        ])
        ->assertUnauthorized()
        ->assertExactJson([
            'success' => false,
            'error' => [
                'code' => 'unauthorized',
                'message' => 'Invalid webhook token.',
            ],
        ]);
});

it('отклоняет webhook при пустом токене в конфигурации', function (): void {
    config()->set('telegga.webhook_token', '');

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', [
            'event' => 'test',
        ])
        ->assertUnauthorized()
        ->assertExactJson([
            'success' => false,
            'error' => [
                'code' => 'unauthorized',
                'message' => 'Invalid webhook token.',
            ],
        ]);
});

it('отклоняет payload без названия события', function (): void {
    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account')
        ->assertBadRequest()
        ->assertExactJson([
            'success' => false,
            'error' => [
                'code' => 'invalid_request',
                'message' => 'Webhook event is required.',
            ],
        ]);
});

it('отклоняет событие подключения без event id', function (): void {
    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', [
            'event' => 'user.linked',
        ])
        ->assertBadRequest()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'error' => [
                'code' => 'invalid_request',
                'message' => 'Webhook event_id is required.',
            ],
        ]);
});

it('отклоняет событие подключения без external id', function (): void {
    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', [
            'event' => 'user.linked',
            'event_id' => $this->eventId,
        ])
        ->assertBadRequest()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'error' => [
                'code' => 'invalid_request',
                'message' => 'Webhook external_id is required.',
            ],
        ]);
});

it('отклоняет событие подключения без имени бота', function (): void {
    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', [
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'external_id' => 'connection-uuid',
        ])
        ->assertBadRequest()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'error' => [
                'code' => 'invalid_request',
                'message' => 'Webhook bot_username is required.',
            ],
        ]);
});

it('возвращает json с серверной ошибкой при недоступной локальной таблице', function (): void {
    Schema::drop('telegram_connected_users');

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', [
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'external_id' => 'unknown-external-id',
            'bot_username' => 'mybot',
        ])
        ->assertServerError()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'error' => [
                'code' => 'internal',
                'message' => 'Webhook could not be processed.',
            ],
        ]);
});
