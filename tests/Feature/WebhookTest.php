<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Telegga\Laravel\Exceptions\WebhookException;
use Telegga\Laravel\Http\Middleware\VerifyWebhookToken;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TeleggaWebhookEvent;
use Telegga\Laravel\Models\TelegramConnectedUser;

/**
 * Create a complete user connection event payload.
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
    $this->telegramBot = AvailableTelegramBot::query()->create(['bot_name' => 'mybot']);
    $this->eventId = 'd5b7d0e1-0000-4000-8000-000000000001';
});

it('registers the webhook route at the expected URI', function (): void {
    $route = Route::getRoutes()->getByName('telegga.webhooks.connect-account');

    expect($route)
        ->not->toBeNull()
        ->and($route?->uri())
        ->toBe('webhooks/v1/telegram/connect-account')
        ->and($route?->methods())
        ->toContain('POST')
        ->and($route?->gatherMiddleware())
        ->toBe([
            'throttle:60,1',
            VerifyWebhookToken::class,
        ]);
});

it('accepts a connection event and returns the result idempotently', function (): void {
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

it('records a new event_id for an already connected user', function (): void {
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

it('rejects an event_id already assigned to another connection', function (): void {
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

it('completes a previously recorded unprocessed event', function (): void {
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

it('accepts a test event without changing connections', function (): void {
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

it('returns an error for an unknown external_id', function (): void {
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

it('returns a distinct error for a deleted connection', function (): void {
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

it('restores local state from an authorized user linked event', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', userLinkedWebhookPayload(overrides: [
            'external_id' => $connection->uuid,
        ]))
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'message' => 'Telegram connection marked as connected.',
            'data' => [
                'external_id' => $connection->uuid,
                'bot_username' => 'mybot',
                'is_connected' => true,
            ],
        ]);

    $connection->refresh();
    $webhookEvent = TeleggaWebhookEvent::query()->sole();

    expect($connection->is_created)
        ->toBeTrue()
        ->and($connection->is_connected)
        ->toBeTrue()
        ->and($webhookEvent->telegram_connected_user_id)
        ->toBe($connection->id)
        ->and($webhookEvent->processed_at)
        ->not->toBeNull();
});

it('requests another delivery when the related bot was deleted', function (): void {
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
            'linked_at' => now()->toRfc3339String(),
        ]))
        ->assertServiceUnavailable()
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

    $webhookEvent = TeleggaWebhookEvent::query()->sole();

    expect($connection->refresh()->is_connected)
        ->toBeFalse()
        ->and($webhookEvent->attempts)
        ->toBe(1)
        ->and($webhookEvent->processed_at)
        ->toBeNull();
});

it('requests another delivery when the related bot is missing', function (): void {
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
            'linked_at' => now()->toRfc3339String(),
        ]))
        ->assertServiceUnavailable()
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

    $webhookEvent = TeleggaWebhookEvent::query()->sole();

    expect($connection->refresh()->is_connected)
        ->toBeFalse()
        ->and($webhookEvent->attempts)
        ->toBe(1)
        ->and($webhookEvent->processed_at)
        ->toBeNull();
});

it('compares a bot name case-insensitively', function (): void {
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

it('requests another delivery when the bot name does not match', function (): void {
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
            'linked_at' => now()->toRfc3339String(),
        ]))
        ->assertServiceUnavailable()
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

    $webhookEvent = TeleggaWebhookEvent::query()->sole();

    expect($connection->refresh()->is_connected)
        ->toBeFalse()
        ->and($webhookEvent->attempts)
        ->toBe(1)
        ->and($webhookEvent->processed_at)
        ->toBeNull();
});

it('acknowledges an unresolved bot failure after the retry window expires', function (): void {
    Log::spy();
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);
    $payload = userLinkedWebhookPayload(overrides: [
        'external_id' => $connection->uuid,
        'bot_username' => '@mybot',
        'linked_at' => now()->toRfc3339String(),
    ]);

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', $payload)
        ->assertServiceUnavailable();

    $this->travel(7)->hours();

    $this
        ->withToken('webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', $payload)
        ->assertOk()
        ->assertExactJson([
            'success' => false,
            'event' => 'user.linked',
            'event_id' => $this->eventId,
            'error' => [
                'code' => 'retry_window_expired',
                'message' => 'Webhook retry window expired before the event could be processed.',
                'details' => [
                    'external_id' => $connection->uuid,
                    'received_bot_username' => '@mybot',
                    'expected_bot_username' => 'mybot',
                    'failure_code' => 'bot_mismatch',
                ],
            ],
        ]);

    $webhookEvent = TeleggaWebhookEvent::query()->sole();

    expect($connection->refresh()->is_connected)
        ->toBeFalse()
        ->and($webhookEvent->attempts)
        ->toBe(2)
        ->and($webhookEvent->processed_at)
        ->toBeNull();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(
            fn (string $message, array $context): bool => $message === 'Telegga webhook retry window expired.'
                && $context['event_id'] === $this->eventId
                && $context['external_id'] === $connection->uuid
                && $context['error_code'] === 'retry_window_expired'
                && $context['failure_code'] === 'bot_mismatch',
        );
});

it('rejects an unknown event', function (): void {
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

it('rejects a webhook without a bearer token', function (): void {
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

it('rejects a webhook with an invalid bearer token', function (): void {
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

it('accepts current and previous webhook tokens during rotation', function (string $token): void {
    config()->set('telegga.webhook_token', [
        'current-webhook-secret',
        'previous-webhook-secret',
    ]);

    $this
        ->withToken($token)
        ->postJson('/webhooks/v1/telegram/connect-account', [
            'event' => 'test',
            'service_id' => '0a1f376a-0000-4000-8000-000000000001',
            'sent_at' => '2026-07-22T10:15:00Z',
        ])
        ->assertOk();
})->with([
    'current token' => 'current-webhook-secret',
    'previous token' => 'previous-webhook-secret',
]);

it('rejects a token outside the configured rotation set', function (): void {
    config()->set('telegga.webhook_token', [
        'current-webhook-secret',
        'previous-webhook-secret',
    ]);

    $this
        ->withToken('unknown-webhook-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', [
            'event' => 'test',
        ])
        ->assertUnauthorized();
});

it('rate-limits webhook requests before bearer token validation', function (): void {
    Log::spy();

    for ($attempt = 1; $attempt <= 60; $attempt++) {
        $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withToken('invalid-secret')
            ->postJson('/webhooks/v1/telegram/connect-account', [
                'event' => 'test',
            ])
            ->assertUnauthorized();
    }

    $this
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->withToken('invalid-secret')
        ->postJson('/webhooks/v1/telegram/connect-account', [
            'event' => 'test',
        ])
        ->assertStatus(429);

    Log::shouldHaveReceived('warning')
        ->times(60)
        ->withArgs(
            fn (string $message, array $context): bool => $message === 'Telegga webhook authorization failed.'
                && $context['ip'] === '203.0.113.10'
                && $context['error_code'] === 'unauthorized',
        );
});

it('rejects a webhook when no valid token is configured', function (mixed $configuredTokens): void {
    config()->set('telegga.webhook_token', $configuredTokens);

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
})->with([
    'empty string' => '',
    'empty array' => [[]],
    'invalid array entries' => [[null, '', 123]],
]);

it('rejects a payload without an event name', function (): void {
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

it('rejects a connection event without an event_id', function (): void {
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

it('requires documented connection event fields', function (
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

it('requires documented test event fields', function (
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

it('rejects an invalid event name', function (mixed $event): void {
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
    'empty string' => '',
    'whitespace-only string' => '   ',
    'number' => 1,
    'array' => [[]],
]);

it('rejects an invalid connection event field', function (
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
    'empty event_id' => ['event_id', '', 'Webhook event_id must be a non-empty string.'],
    'whitespace-only event_id' => ['event_id', '   ', 'Webhook event_id must be a non-empty string.'],
    'null event_id' => ['event_id', null, 'Webhook event_id must be a non-empty string.'],
    'numeric event_id' => ['event_id', 1, 'Webhook event_id must be a non-empty string.'],
    'whitespace-only external_id' => ['external_id', '   ', 'Webhook external_id is required.'],
    'numeric external_id' => ['external_id', 1, 'Webhook external_id is required.'],
    'whitespace-only bot name' => ['bot_username', '   ', 'Webhook bot_username is required.'],
    'numeric bot name' => ['bot_username', 1, 'Webhook bot_username is required.'],
]);

it('rejects a connection event without an external_id', function (): void {
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

it('rejects a connection event without a bot name', function (): void {
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

it('returns server error JSON when the local table is unavailable', function (): void {
    Schema::disableForeignKeyConstraints();

    try {
        Schema::drop('telegram_connected_users');
    } finally {
        Schema::enableForeignKeyConstraints();
    }

    Log::spy();
    Exceptions::fake();

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
                && $context['external_id'] === 'unknown-external-id'
                && is_array($context['exception'])
                && $context['exception']['class'] === WebhookException::class
                && $context['exception']['previous']['class'] === QueryException::class
                && ! str_contains(
                    json_encode($context['exception'], JSON_THROW_ON_ERROR),
                    'unknown-external-id',
                ),
        );

    Exceptions::assertReported(
        fn (WebhookException $exception): bool => $exception->getPrevious() instanceof QueryException,
    );
});
