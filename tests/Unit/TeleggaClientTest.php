<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Http\TeleggaClient;

it('подписывает запрос api ключом и возвращает json', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [['bot_id' => 'bot-1']],
        ]),
    ]);

    $client = new TeleggaClient(
        http: app(Factory::class),
        baseUrl: 'https://api.telegga.net/api/v1',
        apiKey: 'tg_live_test',
        timeout: 15,
        connectTimeout: 5,
    );

    $response = $client->get(uri: 'bots');

    expect($response->object()->data[0]->bot_id)->toBe('bot-1');

    Http::assertSent(fn ($request): bool => $request->hasHeader(
        key: 'Authorization',
        value: 'Bearer tg_live_test',
    ));
});

it('преобразует ошибку api и сохраняет retry after', function () {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/messages' => Http::response(
            body: [
                'error' => [
                    'code' => 'rate_limited',
                    'message' => 'Too many requests.',
                ],
            ],
            status: 429,
            headers: ['Retry-After' => '7'],
        ),
    ]);

    $client = new TeleggaClient(
        http: app(Factory::class),
        baseUrl: 'https://api.telegga.net/api/v1',
        apiKey: 'tg_live_test',
        timeout: 15,
        connectTimeout: 5,
    );

    try {
        $client->post(uri: 'messages', data: ['type' => 'text']);
    } catch (TeleggaApiException $exception) {
        expect($exception->status)->toBe(429)
            ->and($exception->apiCode)->toBe('rate_limited')
            ->and($exception->retryAfter)->toBe(7)
            ->and($exception->attempts)->toBe(1)
            ->and($exception->getMessage())->toBe('Too many requests.');

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();

        return;
    }

    test()->fail('Ожидалось исключение TeleggaApiException.');
});

it('скрывает ошибку транспорта', function () {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::failedConnection(),
    ]);

    $client = new TeleggaClient(
        http: app(Factory::class),
        baseUrl: 'https://api.telegga.net/api/v1',
        apiKey: 'tg_live_test',
        timeout: 15,
        connectTimeout: 5,
    );

    try {
        $client->get(uri: 'bots');
    } catch (TeleggaApiException $exception) {
        expect($exception->status)->toBe(0)
            ->and($exception->apiCode)->toBe('transport_error')
            ->and($exception->attempts)->toBe(3);

        Http::assertSentCount(3);
        Sleep::assertSequence([
            Sleep::for(200)->milliseconds(),
            Sleep::for(400)->milliseconds(),
        ]);

        return;
    }

    test()->fail('Ожидалось исключение TeleggaApiException.');
});

it('повторяет безопасный get после временных ошибок', function (): void {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::sequence()
            ->push(['error' => ['code' => 'internal', 'message' => 'Temporary error.']], 503)
            ->push(['error' => ['code' => 'internal', 'message' => 'Temporary error.']], 503)
            ->push(['data' => [['bot_id' => 'bot-1']]], 200),
    ]);

    $client = new TeleggaClient(
        http: app(Factory::class),
        baseUrl: 'https://api.telegga.net/api/v1',
        apiKey: 'tg_live_test',
        timeout: 15,
        connectTimeout: 5,
    );

    $response = $client->get(uri: 'bots');

    expect($response->object()->data[0]->bot_id)->toBe('bot-1');

    Http::assertSentCount(3);
    Sleep::assertSequence([
        Sleep::for(200)->milliseconds(),
        Sleep::for(400)->milliseconds(),
    ]);
});

it('учитывает retry after при повторе идемпотентного post', function (): void {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users' => Http::sequence()
            ->push(
                body: ['error' => ['code' => 'rate_limited', 'message' => 'Too many requests.']],
                status: 429,
                headers: ['Retry-After' => '2'],
            )
            ->push(['user_id' => 'user-1'], 201),
    ]);

    $client = new TeleggaClient(
        http: app(Factory::class),
        baseUrl: 'https://api.telegga.net/api/v1',
        apiKey: 'tg_live_test',
        timeout: 15,
        connectTimeout: 5,
    );

    $response = $client->post(
        uri: 'users',
        data: ['external_id' => 'external-1'],
        idempotent: true,
    );

    expect($response->object()->user_id)->toBe('user-1');

    Http::assertSentCount(2);
    Sleep::assertSequence([
        Sleep::for(2)->seconds(),
    ]);
});

it('не повторяет неидемпотентный post', function (): void {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/messages' => Http::sequence()
            ->push(['error' => ['code' => 'internal', 'message' => 'Temporary error.']], 503)
            ->push(['message_id' => 'message-1'], 202),
    ]);

    $client = new TeleggaClient(
        http: app(Factory::class),
        baseUrl: 'https://api.telegga.net/api/v1',
        apiKey: 'tg_live_test',
        timeout: 15,
        connectTimeout: 5,
    );

    try {
        $client->post(uri: 'messages', data: ['type' => 'text']);
    } catch (TeleggaApiException $exception) {
        expect($exception->status)->toBe(503)
            ->and($exception->attempts)->toBe(1);

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();

        return;
    }

    test()->fail('Ожидалось исключение TeleggaApiException.');
});

it('возвращает последнюю ошибку после исчерпания попыток', function (): void {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::sequence()
            ->push(['error' => ['code' => 'internal', 'message' => 'First error.']], 503)
            ->push(['error' => ['code' => 'internal', 'message' => 'Second error.']], 503)
            ->push(['error' => ['code' => 'internal', 'message' => 'Last error.']], 503),
    ]);

    $client = new TeleggaClient(
        http: app(Factory::class),
        baseUrl: 'https://api.telegga.net/api/v1',
        apiKey: 'tg_live_test',
        timeout: 15,
        connectTimeout: 5,
    );

    try {
        $client->get(uri: 'bots');
    } catch (TeleggaApiException $exception) {
        expect($exception->getMessage())->toBe('Last error.')
            ->and($exception->status)->toBe(503)
            ->and($exception->attempts)->toBe(3);

        Http::assertSentCount(3);

        return;
    }

    test()->fail('Ожидалось исключение TeleggaApiException.');
});

it('не повторяет постоянную ошибку идемпотентного запроса', function (): void {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/groups/missing' => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'Group was not found.'],
        ], 404),
    ]);

    $client = new TeleggaClient(
        http: app(Factory::class),
        baseUrl: 'https://api.telegga.net/api/v1',
        apiKey: 'tg_live_test',
        timeout: 15,
        connectTimeout: 5,
    );

    try {
        $client->delete(uri: 'groups/missing', idempotent: true);
    } catch (TeleggaApiException $exception) {
        expect($exception->status)->toBe(404)
            ->and($exception->attempts)->toBe(1)
            ->and($exception->wasRetried())->toBeFalse();

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();

        return;
    }

    test()->fail('Ожидалось исключение TeleggaApiException.');
});

it('отклоняет запрос без api ключа', function () {
    $client = new TeleggaClient(
        http: app(Factory::class),
        baseUrl: 'https://api.telegga.net/api/v1',
        apiKey: '',
        timeout: 15,
        connectTimeout: 5,
    );

    $client->get(uri: 'bots');
})->throws(TeleggaApiException::class, 'Telegga API key is not configured.');

it('отклоняет незащищённый базовый адрес api', function (): void {
    Http::preventStrayRequests();

    $client = new TeleggaClient(
        http: app(Factory::class),
        baseUrl: 'http://api.telegga.net/api/v1',
        apiKey: 'tg_live_test',
        timeout: 15,
        connectTimeout: 5,
    );

    try {
        $client->get(uri: 'bots');
    } catch (TeleggaApiException $exception) {
        expect($exception->status)->toBe(0)
            ->and($exception->apiCode)->toBe('invalid_base_url')
            ->and($exception->getMessage())->toBe('Telegga API base URL must use HTTPS.');

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение TeleggaApiException.');
});
