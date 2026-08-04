<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\MessageException;
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

it('передаёт тип и данные сообщения в единый маршрут api', function (
    string $type,
    array $data,
): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
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
        'api.telegga.net/api/v1/messages' => Http::response([
            'message_id' => 'message-1',
            'status' => 'queued',
            'new_api_field' => 'new-value',
        ], 202),
    ]);

    $result = app(TeleggaInterface::class)->sendMessage(
        uuid: $connection->uuid,
        type: $type,
        data: $data,
    );

    expect($result)
        ->toBeInstanceOf(stdClass::class)
        ->and($result->message_id)
        ->toBe('message-1')
        ->and($result->status)
        ->toBe('queued')
        ->and($result->new_api_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request) use ($connection, $data, $type): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/messages'
            && $request->data() === [
                ...$data,
                'external_id' => $connection->uuid,
                'bot_id' => 'bot-active',
                'type' => $type,
            ];
    });

    Http::assertSentCount(2);
})->with([
    'text' => [
        'text',
        [
            'text' => 'Заказ <b>#1234</b> отправлен',
            'parse_mode' => 'HTML',
            'buttons' => [
                [
                    [
                        'text' => 'Отследить',
                        'url' => 'https://example.com/track/1234',
                    ],
                ],
            ],
            'disable_web_page_preview' => true,
            'disable_notification' => true,
        ],
    ],
    'photo' => [
        'photo',
        [
            'media_id' => 'media-photo',
            'text' => 'Подпись',
        ],
    ],
    'video' => [
        'video',
        ['media_id' => 'media-video'],
    ],
    'document' => [
        'document',
        ['media_id' => 'media-document'],
    ],
    'audio' => [
        'audio',
        ['media_id' => 'media-audio'],
    ],
    'voice' => [
        'voice',
        ['media_id' => 'media-voice'],
    ],
    'animation' => [
        'animation',
        ['media_id' => 'media-animation'],
    ],
    'sticker' => [
        'sticker',
        ['media_id' => 'media-sticker'],
    ],
    'location' => [
        'location',
        [
            'latitude' => 50.4501,
            'longitude' => 30.5234,
        ],
    ],
    'contact' => [
        'contact',
        [
            'phone_number' => '+380501234567',
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ],
    ],
]);

it('не позволяет переопределить служебные поля сообщения', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
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
        'api.telegga.net/api/v1/messages' => Http::response([
            'message_id' => 'message-1',
            'status' => 'queued',
        ], 202),
    ]);

    app(TeleggaInterface::class)->sendMessage(
        uuid: $connection->uuid,
        type: 'photo',
        data: [
            'external_id' => 'foreign-external-id',
            'user_id' => 'foreign-user-id',
            'bot_id' => 'foreign-bot',
            'group_id' => 'foreign-group',
            'type' => 'contact',
            'media_id' => 'media-photo',
        ],
    );

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/messages'
            && $request->data() == [
                'external_id' => $connection->uuid,
                'bot_id' => 'bot-active',
                'type' => 'photo',
                'media_id' => 'media-photo',
            ];
    });
});

it('не отправляет сообщение с пустым uuid подключения', function (): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->sendMessage(
            uuid: '   ',
            type: 'text',
            data: ['text' => 'Сообщение'],
        );
    } catch (MessageException $exception) {
        expect($exception->getMessage())
            ->toBe('Connection UUID cannot be empty.')
            ->and($exception->connectionUuid)
            ->toBe('   ');

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение MessageException.');
});

it('не отправляет сообщение с пустым типом', function (): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->sendMessage(
            uuid: 'connection-uuid',
            type: '   ',
        );
    } catch (MessageException $exception) {
        expect($exception->getMessage())
            ->toBe('Message type cannot be empty.')
            ->and($exception->connectionUuid)
            ->toBe('connection-uuid');

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение MessageException.');
});

it('не отправляет сообщение без активной привязки', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [
                [
                    'bot_id' => 'bot-revoked',
                    'bot_username' => 'mybot',
                    'status' => 'revoked',
                ],
            ],
        ]),
    ]);

    try {
        app(TeleggaInterface::class)->sendMessage(
            uuid: $connection->uuid,
            type: 'text',
            data: ['text' => 'Сообщение'],
        );
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBe($connection->uuid);

        Http::assertSentCount(1);

        return;
    }

    test()->fail('Ожидалось исключение ConnectionException.');
});

it('скрывает ошибку api при отправке сообщения', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
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
        'api.telegga.net/api/v1/messages' => Http::response([
            'error' => [
                'code' => 'user_disabled',
                'message' => 'User is disabled.',
            ],
        ], 409),
    ]);

    try {
        app(TeleggaInterface::class)->sendMessage(
            uuid: $connection->uuid,
            type: 'text',
            data: ['text' => 'Сообщение'],
        );
    } catch (MessageException $exception) {
        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('user_disabled')
            ->and($exception->getPrevious()?->status)
            ->toBe(409);

        return;
    }

    test()->fail('Ожидалось исключение MessageException.');
});

it('отклоняет успешный ответ сообщения с некорректным json', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
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
        'api.telegga.net/api/v1/messages' => Http::response(
            body: 'not-json',
            status: 202,
        ),
    ]);

    try {
        app(TeleggaInterface::class)->sendMessage(
            uuid: $connection->uuid,
            type: 'text',
            data: ['text' => 'Сообщение'],
        );
    } catch (MessageException $exception) {
        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('invalid_response');

        return;
    }

    test()->fail('Ожидалось исключение MessageException.');
});
