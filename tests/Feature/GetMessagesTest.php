<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Dto\MessageData;
use Telegga\Laravel\Dto\MessagePageData;
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

it('получает историю сообщений только для указанного подключения', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/messages*' => Http::response([
            'data' => [
                [
                    'message_id' => 'message-1',
                    'status' => 'sent',
                    'new_message_field' => 'new-value',
                ],
            ],
            'next_cursor' => 'next-page',
            'new_page_field' => 'new-value',
        ]),
    ]);

    $page = app(TeleggaInterface::class)->getMessages(
        uuid: $connection->uuid,
        status: 'sent',
        from: new DateTimeImmutable('2026-07-01T10:00:00+03:00'),
        to: new DateTimeImmutable('2026-07-30T18:30:00+03:00'),
        cursor: 'current-page',
    );

    expect($page)
        ->toBeInstanceOf(MessagePageData::class)
        ->and($page->data)
        ->toBeInstanceOf(Collection::class)
        ->and($page->data)
        ->toHaveCount(1)
        ->and($page->data->first())
        ->toBeInstanceOf(MessageData::class)
        ->and($page->data->first()->message_id)
        ->toBe('message-1')
        ->and($page->data->first()->raw()->new_message_field)
        ->toBe('new-value')
        ->and($page->next_cursor)
        ->toBe('next-page')
        ->and($page->raw()->new_page_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request) use ($connection): bool {
        if (
            $request->method() !== 'GET'
            || ! str_starts_with($request->url(), 'https://api.telegga.net/api/v1/messages?')
        ) {
            return false;
        }

        $query = [];
        parse_str(
            string: parse_url(url: $request->url(), component: PHP_URL_QUERY) ?: '',
            result: $query,
        );

        return $query === [
            'user_id' => $connection->uuid,
            'from' => '2026-07-01T10:00:00+03:00',
            'to' => '2026-07-30T18:30:00+03:00',
            'status' => 'sent',
            'cursor' => 'current-page',
        ];
    });

    Http::assertSentCount(1);
});

it('возвращает null вместо отсутствующего курсора следующей страницы', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/messages*' => Http::response([
            'data' => [],
        ]),
    ]);

    $page = app(TeleggaInterface::class)->getMessages(
        uuid: $connection->uuid,
        from: new DateTimeImmutable('2026-07-01T00:00:00Z'),
        to: new DateTimeImmutable('2026-07-30T23:59:59Z'),
    );

    expect($page->data)
        ->toBeInstanceOf(Collection::class)
        ->and($page->data)
        ->toBeEmpty()
        ->and($page->next_cursor)
        ->toBeNull();

    Http::assertSent(function (Request $request) use ($connection): bool {
        if (
            $request->method() !== 'GET'
            || ! str_starts_with($request->url(), 'https://api.telegga.net/api/v1/messages?')
        ) {
            return false;
        }

        $query = [];
        parse_str(
            string: parse_url(url: $request->url(), component: PHP_URL_QUERY) ?: '',
            result: $query,
        );

        return $query === [
            'user_id' => $connection->uuid,
            'from' => '2026-07-01T00:00:00+00:00',
            'to' => '2026-07-30T23:59:59+00:00',
        ];
    });
});

it('не запрашивает историю с пустым uuid подключения', function (): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->getMessages(
            uuid: '   ',
            from: new DateTimeImmutable('2026-07-01T00:00:00Z'),
            to: new DateTimeImmutable('2026-07-30T23:59:59Z'),
        );
    } catch (MessageException $exception) {
        expect($exception->getMessage())
            ->toBe('Connection UUID cannot be empty.')
            ->and($exception->connectionUuid)
            ->toBe('   ')
            ->and($exception->getPrevious())
            ->toBeNull();

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение MessageException.');
});

it('не запрашивает историю с обратным диапазоном дат', function (): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->getMessages(
            uuid: 'connection-uuid',
            from: new DateTimeImmutable('2026-07-30T10:00:00Z'),
            to: new DateTimeImmutable('2026-07-01T10:00:00Z'),
        );
    } catch (MessageException $exception) {
        expect($exception->getMessage())
            ->toBe('Message history date range is invalid.')
            ->and($exception->connectionUuid)
            ->toBe('connection-uuid')
            ->and($exception->getPrevious())
            ->toBeNull();

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение MessageException.');
});

it('не запрашивает историю для локального подключения, не созданного в Telegga', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->getMessages(
            uuid: $connection->uuid,
            from: new DateTimeImmutable('2026-07-01T00:00:00Z'),
            to: new DateTimeImmutable('2026-07-30T23:59:59Z'),
        );
    } catch (ConnectionException $exception) {
        expect($exception->getMessage())
            ->toBe('Telegga connection is not created.')
            ->and($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeNull();

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение ConnectionException.');
});

it('скрывает ошибку api при получении истории сообщений', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/messages*' => Http::response([
            'error' => [
                'code' => 'rate_limited',
                'message' => 'Too many requests.',
            ],
        ], 429),
    ]);

    try {
        app(TeleggaInterface::class)->getMessages(
            uuid: $connection->uuid,
            from: new DateTimeImmutable('2026-07-01T00:00:00Z'),
            to: new DateTimeImmutable('2026-07-30T23:59:59Z'),
        );
    } catch (MessageException $exception) {
        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('rate_limited')
            ->and($exception->getPrevious()?->status)
            ->toBe(429);

        return;
    }

    test()->fail('Ожидалось исключение MessageException.');
});

it('отклоняет некорректную страницу истории сообщений', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/messages*' => Http::response([
            'data' => 'not-an-array',
        ]),
    ]);

    try {
        app(TeleggaInterface::class)->getMessages(
            uuid: $connection->uuid,
            from: new DateTimeImmutable('2026-07-01T00:00:00Z'),
            to: new DateTimeImmutable('2026-07-30T23:59:59Z'),
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
