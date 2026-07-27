<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
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
                ],
            ],
        ]),
    ]);

    $bots = app(TeleggaInterface::class)->getBots();

    expect($bots['data'][0]['bot_id'])->toBe('bot-1');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.telegga.net/api/v1/bots';
    });
});
