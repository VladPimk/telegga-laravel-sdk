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
use Telegga\Laravel\Models\TelegramConnectedUser;

beforeEach(function (): void {
    Schema::enableForeignKeyConstraints();

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $migration = require __DIR__.'/../../database/migrations/create_telegram_connected_users_table.php';

    $migration->up();
});

afterEach(function (): void {
    Schema::dropIfExists('telegram_connected_users');
    Schema::dropIfExists('users');
});

it('отправляет текстовое сообщение через активную привязку', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [
                [
                    'bot_id' => 'bot-active',
                    'status' => 'active',
                ],
            ],
        ]),
        'api.telegga.net/api/v1/messages' => Http::response([
            'message_id' => 'message-1',
            'status' => 'queued',
            'created_at' => '2026-07-30T10:00:00Z',
            'new_api_field' => 'new-value',
        ], 202),
    ]);

    $result = app(TeleggaInterface::class)->sendText(
        uuid: $connection->uuid,
        text: 'Заказ <b>#1234</b> отправлен',
        parseMode: 'HTML',
        buttons: [
            [
                [
                    'text' => 'Отследить',
                    'url' => 'https://example.com/track/1234',
                ],
            ],
        ],
        disableWebPagePreview: true,
        disableNotification: true,
    );

    expect($result)
        ->toBeInstanceOf(stdClass::class)
        ->and($result->message_id)
        ->toBe('message-1')
        ->and($result->status)
        ->toBe('queued')
        ->and($result->new_api_field)
        ->toBe('new-value')
        ->and(TelegramConnectedUser::query()->count())
        ->toBe(1);

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/messages'
            && $request->data() === [
                'external_id' => $connection->uuid,
                'bot_id' => 'bot-active',
                'type' => 'text',
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
            ];
    });

    Http::assertSentCount(2);
});

it('не отправляет необязательные параметры с начальными значениями', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [
                [
                    'bot_id' => 'bot-active',
                    'status' => 'active',
                ],
            ],
        ]),
        'api.telegga.net/api/v1/messages' => Http::response([
            'message_id' => 'message-1',
            'status' => 'queued',
        ], 202),
    ]);

    app(TeleggaInterface::class)->sendText(
        uuid: $connection->uuid,
        text: 'Сообщение',
    );

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/messages'
            && $request->data() === [
                'external_id' => $connection->uuid,
                'bot_id' => 'bot-active',
                'type' => 'text',
                'text' => 'Сообщение',
            ];
    });
});

it('отклоняет некорректные параметры текстового сообщения', function (
    array $arguments,
    string $message,
): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
    ]);
    $arguments['uuid'] = $connection->uuid;

    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->sendText(...$arguments);
    } catch (MessageException $exception) {
        expect($exception->getMessage())
            ->toBe($message)
            ->and($exception->connectionUuid)
            ->toBe($connection->uuid);

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение MessageException.');
})->with([
    'пустой текст' => [
        ['text' => '   '],
        'Message text cannot be empty.',
    ],
    'слишком длинный текст' => [
        ['text' => str_repeat('я', 4097)],
        'Message text cannot exceed 4096 characters.',
    ],
    'неизвестный parse mode' => [
        ['text' => 'Сообщение', 'parseMode' => 'Markdown'],
        'Message parse mode is invalid.',
    ],
    'слишком много рядов кнопок' => [
        [
            'text' => 'Сообщение',
            'buttons' => array_fill(
                start_index: 0,
                count: 11,
                value: [['text' => 'Кнопка', 'url' => 'https://example.com']],
            ),
        ],
        'Message buttons cannot exceed 10 rows.',
    ],
    'пустой ряд кнопок' => [
        ['text' => 'Сообщение', 'buttons' => [[]]],
        'Message button row must contain between 1 and 8 buttons.',
    ],
    'слишком много кнопок в ряду' => [
        [
            'text' => 'Сообщение',
            'buttons' => [
                array_fill(
                    start_index: 0,
                    count: 9,
                    value: ['text' => 'Кнопка', 'url' => 'https://example.com'],
                ),
            ],
        ],
        'Message button row must contain between 1 and 8 buttons.',
    ],
    'кнопка без ссылки' => [
        [
            'text' => 'Сообщение',
            'buttons' => [
                [
                    ['text' => 'Кнопка'],
                ],
            ],
        ],
        'Message button must contain non-empty text and url.',
    ],
]);

it('не отправляет сообщение без активной привязки', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [
                [
                    'bot_id' => 'bot-revoked',
                    'status' => 'revoked',
                ],
            ],
        ]),
    ]);

    try {
        app(TeleggaInterface::class)->sendText(
            uuid: $connection->uuid,
            text: 'Сообщение',
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
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [
                [
                    'bot_id' => 'bot-active',
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
        app(TeleggaInterface::class)->sendText(
            uuid: $connection->uuid,
            text: 'Сообщение',
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
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [
                [
                    'bot_id' => 'bot-active',
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
        app(TeleggaInterface::class)->sendText(
            uuid: $connection->uuid,
            text: 'Сообщение',
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
