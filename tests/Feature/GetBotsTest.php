<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Telegga\Laravel\Contracts\TeleggaInterface;

it('получает доступных ботов через публичный интерфейс', function () {
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
        ->and($bots->first())->toBeInstanceOf(stdClass::class)
        ->and($bots->first()->bot_id)->toBe('bot-1')
        ->and($bots->first()->username)->toBe('mybot')
        ->and($bots->first()->display_name)->toBe('Уведомления')
        ->and($bots->first()->status)->toBe('active')
        ->and($bots->first()->new_api_field)->toBe('new-value');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.telegga.net/api/v1/bots';
    });
});
