<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TeleggaWebhookEvent;
use Telegga\Laravel\Models\TelegramConnectedUser;

/**
 * Создать полный payload события подключения пользователя.
 *
 * @param  array<string, mixed>  $overrides
 * @param  array<int, string>  $except
 * @return array<string, mixed>
 */
function userLinkedWebhookPayload(array $overrides = [], array $except = []): array
{
    $payload = array_replace([
        'event' => 'user.linked',
        'event_id' => 'd5b7d0e1-0000-4000-8000-000000000001',
        'service_id' => '0a1f376a-0000-4000-8000-000000000001',
        'user_id' => 'b7c0d091-0000-4000-8000-000000000001',
        'external_id' => 'connection-uuid',
        'bot_id' => 'b2040855-0000-4000-8000-000000000001',
        'bot_username' => 'mybot',
        'telegram_user_id' => 6141109792,
        'linked_at' => '2026-07-22T10:15:00Z',
    ], $overrides);

    return collect($payload)->except($except)->all();
}

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

    $this->telegramBot = AvailableTelegramBot::query()->create(['bot_name' => 'mybot']);
    $this->eventId = 'd5b7d0e1-0000-4000-8000-000000000001';
});

afterEach(function (): void {
    Schema::dropIfExists('telegga_webhook_events');
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

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', $payload)
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'message' => 'Webhook event has already been processed.',
            'data' => [
                'external_id' => $connection->uuid,
                'bot_username' => 'mybot',
                'is_connected' => true,
            ],
        ]);

    $duplicateQueries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    $webhookEvent = TeleggaWebhookEvent::query()->sole();

    expect($connection->refresh()->is_connected)
        ->toBeTrue()
        ->and(TelegramConnectedUser::query()->count())
        ->toBe(1)
        ->and($webhookEvent->telegram_connected_user_id)
        ->toBe($connection->id)
        ->and($webhookEvent->event_id)
        ->toBe($this->eventId)
        ->and($webhookEvent->event)
        ->toBe('user.linked')
        ->and($webhookEvent->attempts)
        ->toBe(2)
        ->and($webhookEvent->first_seen_at)
        ->not->toBeNull()
        ->and($webhookEvent->processed_at)
        ->not->toBeNull()
        ->and($duplicateQueries->filter(
            fn (array $query): bool => str_contains($query['query'], 'available_telegram_bots'),
        ))
        ->toBeEmpty()
        ->and($duplicateQueries->filter(
            fn (array $query): bool => str_contains($query['query'], 'update "telegram_connected_users"'),
        ))
        ->toBeEmpty();
});

it('регистрирует новый event id для уже подключённого пользователя', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(overrides: [
            'external_id' => $connection->uuid,
        ]))
        ->assertOk();

    $secondEventId = 'd5b7d0e1-0000-4000-8000-000000000002';

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(overrides: [
            'event_id' => $secondEventId,
            'external_id' => $connection->uuid,
        ]))
        ->assertOk()
        ->assertJsonPath('event_id', $secondEventId)
        ->assertJsonPath('message', 'Telegram connection is already connected.');

    expect(TeleggaWebhookEvent::query()->count())
        ->toBe(2)
        ->and(TeleggaWebhookEvent::query()->pluck('attempts')->all())
        ->toBe([1, 1])
        ->and(TeleggaWebhookEvent::query()->whereNull('processed_at')->doesntExist())
        ->toBeTrue();
});

it('отклоняет event id, уже связанный с другим подключением', function (): void {
    Log::spy();
    $firstConnection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);
    $secondConnection = TelegramConnectedUser::query()->create([
        'name' => 'Пётр',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(overrides: [
            'external_id' => $firstConnection->uuid,
        ]))
        ->assertOk();

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(overrides: [
            'external_id' => $secondConnection->uuid,
        ]))
        ->assertConflict()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'error' => [
                'code' => 'event_id_conflict',
                'message' => 'Webhook event_id is already assigned to a different connection or event.',
                'details' => [
                    'external_id' => $secondConnection->uuid,
                    'received_bot_username' => 'mybot',
                    'expected_event' => 'user.linked',
                ],
            ],
        ]);

    $webhookEvent = TeleggaWebhookEvent::query()->sole();

    expect($webhookEvent->telegram_connected_user_id)
        ->toBe($firstConnection->id)
        ->and($webhookEvent->attempts)
        ->toBe(1)
        ->and($secondConnection->refresh()->is_connected)
        ->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(
            fn (string $message, array $context): bool => $message === 'Telegga webhook request was rejected.'
                && $context['error_code'] === 'event_id_conflict'
                && $context['external_id'] === $secondConnection->uuid,
        );
});

it('завершает ранее зарегистрированное необработанное событие', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);
    $webhookEvent = TeleggaWebhookEvent::query()->create([
        'telegram_connected_user_id' => $connection->id,
        'event_id' => $this->eventId,
        'event' => 'user.linked',
        'first_seen_at' => now()->subMinute(),
    ]);

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(overrides: [
            'external_id' => $connection->uuid,
        ]))
        ->assertOk()
        ->assertJsonPath('message', 'Telegram connection marked as connected.');

    expect($connection->refresh()->is_connected)
        ->toBeTrue()
        ->and($webhookEvent->refresh()->attempts)
        ->toBe(2)
        ->and($webhookEvent->processed_at)
        ->not->toBeNull();
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
        ->toBeFalse()
        ->and(TeleggaWebhookEvent::query()->doesntExist())
        ->toBeTrue();
});

it('возвращает ошибку для неизвестного external id', function (): void {
    Log::spy();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(overrides: [
            'external_id' => 'unknown-external-id',
        ]))
        ->assertNotFound()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'error' => [
                'code' => 'connection_not_found',
                'message' => 'Telegram connection was not found for the provided external_id.',
                'details' => [
                    'external_id' => 'unknown-external-id',
                    'received_bot_username' => 'mybot',
                ],
            ],
        ]);

    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    expect(TelegramConnectedUser::query()->doesntExist())
        ->toBeTrue()
        ->and(TeleggaWebhookEvent::query()->doesntExist())
        ->toBeTrue()
        ->and($queries->filter(
            fn (array $query): bool => str_contains($query['query'], 'telegram_connected_users'),
        ))
        ->toHaveCount(1)
        ->and($queries->filter(
            fn (array $query): bool => str_contains($query['query'], 'available_telegram_bots'),
        ))
        ->toBeEmpty();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(
            fn (string $message, array $context): bool => $message === 'Telegga webhook request was rejected.'
                && $context['error_code'] === 'connection_not_found'
                && $context['external_id'] === 'unknown-external-id',
        );
});

it('возвращает отдельную ошибку для удалённого подключения', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);
    $connection->delete();

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(overrides: [
            'external_id' => $connection->uuid,
        ]))
        ->assertNotFound()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'error' => [
                'code' => 'connection_deleted',
                'message' => 'Telegram connection for the provided external_id has been deleted.',
                'details' => [
                    'external_id' => $connection->uuid,
                    'received_bot_username' => 'mybot',
                ],
            ],
        ]);

    expect(TelegramConnectedUser::withTrashed()->find($connection->id)?->is_connected)
        ->toBeFalse()
        ->and(TeleggaWebhookEvent::query()->doesntExist())
        ->toBeTrue();
});

it('возвращает отдельную ошибку для не созданного в Telegga подключения', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(overrides: [
            'external_id' => $connection->uuid,
        ]))
        ->assertConflict()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'error' => [
                'code' => 'connection_not_created',
                'message' => 'Telegram connection has not been created in Telegga.',
                'details' => [
                    'external_id' => $connection->uuid,
                    'received_bot_username' => 'mybot',
                ],
            ],
        ]);

    expect($connection->refresh()->is_connected)
        ->toBeFalse()
        ->and(TeleggaWebhookEvent::query()->doesntExist())
        ->toBeTrue();
});

it('возвращает отдельную ошибку для удалённого связанного бота', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);
    $this->telegramBot->delete();

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(overrides: [
            'external_id' => $connection->uuid,
        ]))
        ->assertNotFound()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'error' => [
                'code' => 'bot_deleted',
                'message' => 'Telegram bot assigned to the connection has been deleted.',
                'details' => [
                    'external_id' => $connection->uuid,
                    'received_bot_username' => 'mybot',
                    'expected_bot_username' => 'mybot',
                ],
            ],
        ]);

    expect($connection->refresh()->is_connected)
        ->toBeFalse()
        ->and(TeleggaWebhookEvent::query()->doesntExist())
        ->toBeTrue();
});

it('возвращает отдельную ошибку для отсутствующего связанного бота', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Schema::disableForeignKeyConstraints();
    $this->telegramBot->forceDelete();
    Schema::enableForeignKeyConstraints();

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(overrides: [
            'external_id' => $connection->uuid,
        ]))
        ->assertNotFound()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'error' => [
                'code' => 'bot_not_found',
                'message' => 'Telegram bot assigned to the connection was not found.',
                'details' => [
                    'external_id' => $connection->uuid,
                    'received_bot_username' => 'mybot',
                ],
            ],
        ]);

    expect($connection->refresh()->is_connected)
        ->toBeFalse()
        ->and(TeleggaWebhookEvent::query()->doesntExist())
        ->toBeTrue();
});

it('сравнивает имя бота без учёта регистра', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(overrides: [
            'external_id' => $connection->uuid,
            'bot_username' => 'MyBot',
        ]))
        ->assertOk()
        ->assertJsonPath('data.bot_username', 'mybot');

    expect($connection->refresh()->is_connected)
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
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(overrides: [
            'external_id' => $connection->uuid,
            'bot_username' => '@mybot',
        ]))
        ->assertConflict()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'error' => [
                'code' => 'bot_mismatch',
                'message' => 'Telegram connection is assigned to a different bot.',
                'details' => [
                    'external_id' => $connection->uuid,
                    'received_bot_username' => '@mybot',
                    'expected_bot_username' => 'mybot',
                ],
            ],
        ]);

    expect($connection->refresh()->is_connected)
        ->toBeFalse()
        ->and(TeleggaWebhookEvent::query()->doesntExist())
        ->toBeTrue();
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
    Log::spy();

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

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(
            fn (string $message, array $context): bool => $message === 'Telegga webhook authorization failed.'
                && $context['error_code'] === 'unauthorized'
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'wrong-secret'),
        );
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
    Log::spy();

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

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(
            fn (string $message, array $context): bool => $message === 'Telegga webhook request validation failed.'
                && $context['error_code'] === 'invalid_request',
        );
});

it('отклоняет событие подключения без event id', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(
            overrides: [
                'external_id' => $connection->uuid,
            ],
            except: ['event_id'],
        ))
        ->assertBadRequest()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'error' => [
                'code' => 'invalid_request',
                'message' => 'Webhook event_id must be a non-empty string.',
            ],
        ]);

    expect($connection->refresh()->is_connected)
        ->toBeFalse()
        ->and(TeleggaWebhookEvent::query()->doesntExist())
        ->toBeTrue();
});

it('требует документированные поля события подключения', function (
    string $field,
    string $message,
): void {
    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(
            except: [$field],
        ))
        ->assertBadRequest()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'error' => [
                'code' => 'invalid_request',
                'message' => $message,
            ],
        ]);
})->with([
    'service id' => ['service_id', 'Webhook service_id is required.'],
    'user id' => ['user_id', 'Webhook user_id is required.'],
    'bot id' => ['bot_id', 'Webhook bot_id is required.'],
    'telegram user id' => ['telegram_user_id', 'Webhook telegram_user_id is required.'],
    'linked at' => ['linked_at', 'Webhook linked_at is required.'],
]);

it('требует документированные поля тестового события', function (
    string $field,
    string $message,
): void {
    $payload = collect([
        'event' => 'test',
        'service_id' => '0a1f376a-0000-4000-8000-000000000001',
        'sent_at' => '2026-07-22T10:15:00Z',
    ])->except([$field])->all();

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', $payload)
        ->assertBadRequest()
        ->assertExactJson([
            'success' => false,
            'event' => 'test',
            'error' => [
                'code' => 'invalid_request',
                'message' => $message,
            ],
        ]);
})->with([
    'service id' => ['service_id', 'Webhook service_id is required.'],
    'sent at' => ['sent_at', 'Webhook sent_at is required.'],
]);

it('отклоняет некорректное название события', function (mixed $event): void {
    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', [
            'event' => $event,
        ])
        ->assertBadRequest()
        ->assertExactJson([
            'success' => false,
            'error' => [
                'code' => 'invalid_request',
                'message' => 'Webhook event is required.',
            ],
        ]);
})->with([
    'пустая строка' => '',
    'строка из пробелов' => '   ',
    'число' => 1,
    'массив' => [[]],
]);

it('отклоняет некорректное поле события подключения', function (
    string $field,
    mixed $value,
    string $message,
): void {
    $payload = userLinkedWebhookPayload();
    $payload[$field] = $value;

    $expected = [
        'success' => false,
        'event' => 'user.linked',
    ];

    if ($field !== 'event_id') {
        $expected['event_id'] = $this->eventId;
    }

    $expected['error'] = [
        'code' => 'invalid_request',
        'message' => $message,
    ];

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', $payload)
        ->assertBadRequest()
        ->assertExactJson($expected);
})->with([
    'пустой event id' => ['event_id', '', 'Webhook event_id must be a non-empty string.'],
    'event id из пробелов' => ['event_id', '   ', 'Webhook event_id must be a non-empty string.'],
    'null вместо event id' => ['event_id', null, 'Webhook event_id must be a non-empty string.'],
    'числовой event id' => ['event_id', 1, 'Webhook event_id must be a non-empty string.'],
    'external id из пробелов' => ['external_id', '   ', 'Webhook external_id is required.'],
    'числовой external id' => ['external_id', 1, 'Webhook external_id is required.'],
    'имя бота из пробелов' => ['bot_username', '   ', 'Webhook bot_username is required.'],
    'числовое имя бота' => ['bot_username', 1, 'Webhook bot_username is required.'],
]);

it('отклоняет событие подключения без external id', function (): void {
    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(
            except: ['external_id'],
        ))
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
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(
            except: ['bot_username'],
        ))
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
    Log::spy();

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(overrides: [
            'external_id' => 'unknown-external-id',
        ]))
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

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(
            fn (string $message, array $context): bool => $message === 'Telegga webhook could not be processed.'
                && $context['error_code'] === 'internal'
                && $context['external_id'] === 'unknown-external-id',
        );
});
