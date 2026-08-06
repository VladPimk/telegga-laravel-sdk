<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Dto\BroadcastCancellationData;
use Telegga\Laravel\Dto\BroadcastCreatedData;
use Telegga\Laravel\Dto\BroadcastData;
use Telegga\Laravel\Exceptions\BroadcastException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
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
});

afterEach(function (): void {
    Schema::dropIfExists('telegram_connected_users');
    Schema::dropIfExists('available_telegram_bots');
    Schema::dropIfExists('users');
});

it('запускает рассылку всем пользователям бота подключения', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/broadcasts' => Http::response([
            'broadcast_id' => 'broadcast-1',
            'status' => 'pending',
            'new_api_field' => 'new-value',
        ], 202),
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [
                [
                    'bot_id' => 'bot-pending',
                    'bot_username' => 'mybot',
                    'status' => 'pending',
                ],
            ],
        ]),
    ]);

    $broadcast = app(TeleggaInterface::class)->startBroadcast(
        uuid: $connection->uuid,
        type: 'text',
        data: [
            'text' => 'Акция!',
            'bot_id' => 'foreign-bot',
            'group_id' => 'foreign-group',
            'type' => 'photo',
            'external_id' => 'foreign-external-id',
            'user_id' => 'foreign-user-id',
        ],
    );

    expect($broadcast)
        ->toBeInstanceOf(BroadcastCreatedData::class)
        ->and($broadcast->broadcast_id)
        ->toBe('broadcast-1')
        ->and($broadcast->status)
        ->toBe('pending')
        ->and($broadcast->raw()->new_api_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/broadcasts'
            && $request->data() === [
                'text' => 'Акция!',
                'bot_id' => 'bot-pending',
                'type' => 'text',
            ];
    });

    Http::assertSentCount(2);
});

it('запускает медиа рассылку участникам указанной группы', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/broadcasts' => Http::response([
            'broadcast_id' => 'broadcast-1',
            'status' => 'pending',
        ], 202),
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [
                [
                    'bot_id' => 'bot-active',
                    'bot_username' => 'mybot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    app(TeleggaInterface::class)->startBroadcast(
        uuid: $connection->uuid,
        type: 'photo',
        data: [
            'media_id' => 'media-photo',
            'text' => 'Новая акция',
            'group_id' => 'foreign-group',
        ],
        groupId: 'group-1',
    );

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/broadcasts'
            && $request->data() === [
                'media_id' => 'media-photo',
                'text' => 'Новая акция',
                'bot_id' => 'bot-active',
                'group_id' => 'group-1',
                'type' => 'photo',
            ];
    });
});

it('получает прогресс рассылки без потери новых полей', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/broadcasts/broadcast-1' => Http::response([
            'broadcast_id' => 'broadcast-1',
            'status' => 'in_progress',
            'total' => 2003,
            'sent' => 1200,
            'failed' => 3,
            'new_api_field' => 'new-value',
        ]),
    ]);

    $broadcast = app(TeleggaInterface::class)->getBroadcast(
        broadcastId: 'broadcast-1',
    );

    expect($broadcast)
        ->toBeInstanceOf(BroadcastData::class)
        ->and($broadcast->status)
        ->toBe('in_progress')
        ->and($broadcast->total)
        ->toBe(2003)
        ->and($broadcast->sent)
        ->toBe(1200)
        ->and($broadcast->raw()->new_api_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.telegga.net/api/v1/broadcasts/broadcast-1';
    });
});

it('отменяет рассылку и возвращает результат api', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/broadcasts/broadcast-1/cancel' => Http::response([
            'status' => 'cancelled',
            'cancelled_messages' => 803,
            'new_api_field' => 'new-value',
        ]),
    ]);

    $result = app(TeleggaInterface::class)->cancelBroadcast(
        broadcastId: 'broadcast-1',
    );

    expect($result)
        ->toBeInstanceOf(BroadcastCancellationData::class)
        ->and($result->status)
        ->toBe('cancelled')
        ->and($result->cancelled_messages)
        ->toBe(803)
        ->and($result->raw()->new_api_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/broadcasts/broadcast-1/cancel'
            && $request->data() === [];
    });
});

it('отклоняет некорректные параметры рассылки до api запроса', function (
    Closure $action,
    string $message,
): void {
    Http::preventStrayRequests();

    try {
        $action(app(TeleggaInterface::class));
    } catch (BroadcastException $exception) {
        expect($exception->getMessage())
            ->toBe($message);

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение BroadcastException.');
})->with([
    'пустой uuid' => [
        fn (TeleggaInterface $telegga) => $telegga->startBroadcast(
            uuid: '   ',
            type: 'text',
        ),
        'Connection UUID cannot be empty.',
    ],
    'пустой тип' => [
        fn (TeleggaInterface $telegga) => $telegga->startBroadcast(
            uuid: 'connection-uuid',
            type: '   ',
        ),
        'Broadcast type cannot be empty.',
    ],
    'пустая группа' => [
        fn (TeleggaInterface $telegga) => $telegga->startBroadcast(
            uuid: 'connection-uuid',
            type: 'text',
            groupId: '   ',
        ),
        'Group identifier cannot be empty.',
    ],
    'пустой идентификатор прогресса' => [
        fn (TeleggaInterface $telegga) => $telegga->getBroadcast(
            broadcastId: '   ',
        ),
        'Broadcast identifier cannot be empty.',
    ],
    'пустой идентификатор отмены' => [
        fn (TeleggaInterface $telegga) => $telegga->cancelBroadcast(
            broadcastId: '   ',
        ),
        'Broadcast identifier cannot be empty.',
    ],
]);

it('скрывает ошибку api при получении рассылки', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/broadcasts/broadcast-1' => Http::response([
            'error' => [
                'code' => 'not_found',
                'message' => 'Broadcast was not found.',
            ],
        ], 404),
    ]);

    try {
        app(TeleggaInterface::class)->getBroadcast(
            broadcastId: 'broadcast-1',
        );
    } catch (BroadcastException $exception) {
        expect($exception->broadcastId)
            ->toBe('broadcast-1')
            ->and($exception->connectionUuid)
            ->toBeNull()
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('not_found')
            ->and($exception->getPrevious()?->status)
            ->toBe(404);

        return;
    }

    test()->fail('Ожидалось исключение BroadcastException.');
});

it('отклоняет успешный ответ рассылки с некорректным json', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/broadcasts/broadcast-1' => Http::response(
            body: 'not-json',
            status: 200,
        ),
    ]);

    try {
        app(TeleggaInterface::class)->getBroadcast(
            broadcastId: 'broadcast-1',
        );
    } catch (BroadcastException $exception) {
        expect($exception->broadcastId)
            ->toBe('broadcast-1')
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('invalid_response');

        return;
    }

    test()->fail('Ожидалось исключение BroadcastException.');
});
