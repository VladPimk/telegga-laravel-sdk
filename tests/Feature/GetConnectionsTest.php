<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Dto\UserData;
use Telegga\Laravel\Dto\UserPageData;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\AvailableTelegramBot;

it('получает страницу подключений по email статусу и курсору', function (): void {
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

    expect($page)
        ->toBeInstanceOf(UserPageData::class)
        ->and($page->data)
        ->toBeInstanceOf(Collection::class)
        ->and($page->data)
        ->toHaveCount(1)
        ->and($page->data->first())->toBeInstanceOf(UserData::class)
        ->and($page->data->first()->raw()->new_api_field)
        ->toBe('new-value')
        ->and($page->next_cursor)
        ->toBe('next-cursor');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.telegga.net/api/v1/users?email=ivan%40example.com&status=active&cursor=current-cursor';
    });
});

it('преобразует локальный uuid бота в bot_id для списка подключений', function (): void {
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
        ->toBeInstanceOf(Collection::class)
        ->and($page->data)
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

it('не выполняет запрос для неизвестного локального бота', function (): void {
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

    test()->fail('Ожидалось исключение ConnectionException.');
});

it('скрывает некорректный ответ списка подключений', function (): void {
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

    test()->fail('Ожидалось исключение ConnectionException.');
});
