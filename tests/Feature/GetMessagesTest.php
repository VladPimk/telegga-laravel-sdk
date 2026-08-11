<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Dto\MessageData;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\MessageException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TelegramConnectedUser;

beforeEach(function (): void {
    $this->telegramBot = AvailableTelegramBot::query()->create(['bot_name' => 'mybot']);
});

it('gets message history only for the specified connection', function (): void {
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

    expect($page->data)
        ->toHaveCount(1)
        ->and($page->data->first())
        ->toBeInstanceOf(MessageData::class)
        ->and($page->data->first()->message_id)
        ->toBe('message-1')
        ->and($page->next_cursor)
        ->toBe('next-page');

    $this->assertSame('new-value', $page->data->first()->raw()->new_message_field);
    $this->assertSame('new-value', $page->raw()->new_page_field);

    Http::assertSent(function (Request $request) use ($connection): bool {
        if (
            $request->method() !== 'GET'
            || ! str_starts_with($request->url(), 'https://api.telegga.net/api/v1/messages?')
        ) {
            return false;
        }

        $query = [];
        parse_str(
            parse_url($request->url(), PHP_URL_QUERY) ?: '',
            $query,
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

it('returns null for a missing next page cursor', function (): void {
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
            parse_url($request->url(), PHP_URL_QUERY) ?: '',
            $query,
        );

        return $query === [
            'user_id' => $connection->uuid,
            'from' => '2026-07-01T00:00:00+00:00',
            'to' => '2026-07-30T23:59:59+00:00',
        ];
    });
});

it('does not request history with an empty connection UUID', function (): void {
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

    $this->fail('Expected a MessageException.');
});

it('does not request history with a reversed date range', function (): void {
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

    $this->fail('Expected a MessageException.');
});

it('does not request history for a local connection not created in Telegga', function (): void {
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

    $this->fail('Expected a ConnectionException.');
});

it('wraps an API error when getting message history', function (): void {
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
            ->and($this->previousApiException(exception: $exception)->apiCode)
            ->toBe('rate_limited')
            ->and($this->previousApiException(exception: $exception)->status)
            ->toBe(429);

        return;
    }

    $this->fail('Expected a MessageException.');
});

it('rejects an invalid message history page', function (): void {
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
            ->and($this->previousApiException(exception: $exception)->apiCode)
            ->toBe('invalid_response');

        return;
    }

    $this->fail('Expected a MessageException.');
});
