<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
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
    );

    $response = $client->get(uri: 'bots');

    expect($response['data'][0]['bot_id'])->toBe('bot-1');

    Http::assertSent(fn ($request): bool => $request->hasHeader(
        key: 'Authorization',
        value: 'Bearer tg_live_test',
    ));
});

it('преобразует ошибку api и сохраняет retry after', function () {
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
    );

    try {
        $client->post(uri: 'messages', data: ['type' => 'text']);
    } catch (TeleggaApiException $exception) {
        expect($exception->status)->toBe(429)
            ->and($exception->apiCode)->toBe('rate_limited')
            ->and($exception->retryAfter)->toBe(7)
            ->and($exception->getMessage())->toBe('Too many requests.');

        return;
    }

    test()->fail('Ожидалось исключение TeleggaApiException.');
});

it('скрывает ошибку транспорта', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::failedConnection(),
    ]);

    $client = new TeleggaClient(
        http: app(Factory::class),
        baseUrl: 'https://api.telegga.net/api/v1',
        apiKey: 'tg_live_test',
        timeout: 15,
    );

    try {
        $client->get(uri: 'bots');
    } catch (TeleggaApiException $exception) {
        expect($exception->status)->toBe(0)
            ->and($exception->apiCode)->toBe('transport_error');

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
    );

    $client->get(uri: 'bots');
})->throws(TeleggaApiException::class, 'Telegga API key is not configured.');
