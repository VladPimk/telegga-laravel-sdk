<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Dto\UserData;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\AvailableTelegramBot;

it('gets a connection page by email status and cursor', function (): void {
    $response = $this->apiFixture(path: 'users/list-by-email');
    $response['data'][0]['new_api_field'] = 'new-value';

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users?email=ivan%40example.com&status=active&cursor=current-cursor' => Http::response($response),
    ]);

    $page = app(TeleggaInterface::class)->getConnections(
        email: 'ivan@example.com',
        status: 'active',
        cursor: 'current-cursor',
    );

    expect($page->data)
        ->toHaveCount(1)
        ->and($page->data->first())->toBeInstanceOf(UserData::class)
        ->and($page->next_cursor)
        ->toBe('next-cursor');

    $this->assertSame('new-value', $page->data->first()->raw()->new_api_field);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.telegga.net/api/v1/users?email=ivan%40example.com&status=active&cursor=current-cursor';
    });
});

it('resolves a local bot UUID to bot_id for the connection list', function (): void {
    $telegramBot = AvailableTelegramBot::query()->create([
        'bot_name' => 'mybot',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [
                [
                    'bot_id' => 'bot-1',
                    'username' => 'mybot',
                    'status' => 'inactive',
                ],
            ],
        ]),
        'api.telegga.net/api/v1/users?bot_id=bot-1' => Http::response([
            'data' => [],
        ]),
    ]);

    $page = app(TeleggaInterface::class)->getConnections(
        telegramBotUuid: $telegramBot->uuid,
    );

    expect($page->data)
        ->toBeEmpty()
        ->and($page->next_cursor)
        ->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.telegga.net/api/v1/users?bot_id=bot-1';
    });

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.telegga.net/api/v1/bots';
    });

    Http::assertSentCount(2);
});

it('does not send a request for an unknown local bot', function (): void {
    $telegramBotUuid = Str::uuid()->toString();
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->getConnections(
            telegramBotUuid: $telegramBotUuid,
        );
    } catch (ConnectionException $exception) {
        expect($exception->getPrevious())
            ->not->toBeNull();

        Http::assertNothingSent();

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('wraps an invalid connection list response', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users' => Http::response([
            'data' => 'invalid',
        ]),
    ]);

    try {
        app(TeleggaInterface::class)->getConnections();
    } catch (ConnectionException $exception) {
        expect($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class);

        return;
    }

    $this->fail('Expected a ConnectionException.');
});
