<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Dto\BotData;
use Telegga\Laravel\Exceptions\TeleggaApiException;

it('gets available bots through the public interface', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [
                [
                    'bot_id' => 'bot-1',
                    'username' => 'mybot',
                    'display_name' => 'Уведомления',
                    'status' => 'active',
                    'new_api_field' => 'new-value',
                ],
            ],
        ]),
    ]);

    $bots = app(TeleggaInterface::class)->getBots();

    expect($bots)
        ->toBeInstanceOf(Collection::class)
        ->and($bots)->toHaveCount(1)
        ->and($bots->first())->toBeInstanceOf(BotData::class)
        ->and($bots->first()->bot_id)->toBe('bot-1')
        ->and($bots->first()->username)->toBe('mybot')
        ->and($bots->first()->display_name)->toBe('Уведомления')
        ->and($bots->first()->status)->toBe('active')
        ->and($bots->first()->raw()->new_api_field)->toBe('new-value');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.telegga.net/api/v1/bots';
    });
});

it('caches the available bot list for ten minutes', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'bot_id' => 'bot-1',
                        'username' => 'first_bot',
                        'status' => 'active',
                    ],
                ],
            ])
            ->push([
                'data' => [
                    [
                        'bot_id' => 'bot-2',
                        'username' => 'second_bot',
                        'status' => 'active',
                    ],
                ],
            ]),
    ]);

    $first = app(TeleggaInterface::class)->getBots();

    $this->travel(9)->minutes();

    $cached = app(TeleggaInterface::class)->getBots();

    expect($first->first()->bot_id)
        ->toBe('bot-1')
        ->and($cached->first()->bot_id)
        ->toBe('bot-1');

    Http::assertSentCount(1);

    $this->travel(2)->minutes();

    $refreshed = app(TeleggaInterface::class)->getBots();

    expect($refreshed->first()->bot_id)
        ->toBe('bot-2');

    Http::assertSentCount(2);
});

it('does not cache an invalid bot list response', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::sequence()
            ->push('not-json')
            ->push([
                'data' => [
                    [
                        'bot_id' => 'bot-1',
                        'username' => 'mybot',
                        'status' => 'active',
                    ],
                ],
            ]),
    ]);

    try {
        app(TeleggaInterface::class)->getBots();

        test()->fail('Expected a TeleggaApiException.');
    } catch (TeleggaApiException $exception) {
        expect($exception->apiCode)
            ->toBe('invalid_response');
    }

    $bots = app(TeleggaInterface::class)->getBots();

    expect($bots->first()->bot_id)
        ->toBe('bot-1');

    Http::assertSentCount(2);
});

it('separates bot list caches for different API keys', function (): void {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $botId = $request->hasHeader('Authorization', 'Bearer tg_live_first')
            ? 'first-service-bot'
            : 'second-service-bot';

        return Http::response([
            'data' => [
                [
                    'bot_id' => $botId,
                    'username' => 'mybot',
                    'status' => 'active',
                ],
            ],
        ]);
    });

    config()->set('telegga.api_key', 'tg_live_first');
    $first = app(TeleggaInterface::class)->getBots();

    config()->set('telegga.api_key', 'tg_live_second');
    $second = app(TeleggaInterface::class)->getBots();

    expect($first->first()->bot_id)
        ->toBe('first-service-bot')
        ->and($second->first()->bot_id)
        ->toBe('second-service-bot');

    Http::assertSentCount(2);
});
