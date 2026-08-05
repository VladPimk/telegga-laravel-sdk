<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Http\TeleggaClient;
use Telegga\Laravel\Resolvers\ConnectionContextResolver;
use Telegga\Laravel\Services\BotService;
use Telegga\Laravel\Services\BroadcastService;
use Telegga\Laravel\Services\ConnectionService;
use Telegga\Laravel\Services\GroupService;
use Telegga\Laravel\Services\MediaService;
use Telegga\Laravel\Services\MessageService;
use Telegga\Laravel\Services\UserService;
use Telegga\Laravel\Services\WebhookService;
use Telegga\Laravel\Telegga;
use Telegga\Laravel\TeleggaServiceProvider;

it('загружает сервис провайдер пакета', function () {
    expect($this->app->getProvider(TeleggaServiceProvider::class))
        ->toBeInstanceOf(TeleggaServiceProvider::class);
});

it('регистрирует публичный контракт и конфигурацию', function () {
    expect(app(TeleggaInterface::class))
        ->toBeInstanceOf(Telegga::class)
        ->and(config('telegga.base_url'))
        ->toBe('https://api.telegga.net/api/v1')
        ->and(config('telegga.timeout'))
        ->toBe(15)
        ->and(config('telegga.connect_timeout'))
        ->toBe(5);
});

it('создаёт новый экземпляр для каждого разрешения сервисов пакета', function (): void {
    $abstracts = [
        TeleggaClient::class,
        BotService::class,
        ConnectionService::class,
        UserService::class,
        MessageService::class,
        MediaService::class,
        GroupService::class,
        BroadcastService::class,
        WebhookService::class,
        ConnectionContextResolver::class,
        TeleggaInterface::class,
    ];

    foreach ($abstracts as $abstract) {
        expect(app($abstract))->not->toBe(app($abstract));
    }
});

it('использует актуальный api ключ при повторном разрешении клиента', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response(['data' => []]),
    ]);

    config(['telegga.api_key' => 'tg_live_first']);
    app(TeleggaClient::class)->get(uri: 'bots');

    config(['telegga.api_key' => 'tg_live_second']);
    app(TeleggaClient::class)->get(uri: 'bots');

    $requests = Http::recorded();

    expect($requests)
        ->toHaveCount(2)
        ->and($requests[0][0]->hasHeader('Authorization', 'Bearer tg_live_first'))
        ->toBeTrue()
        ->and($requests[1][0]->hasHeader('Authorization', 'Bearer tg_live_second'))
        ->toBeTrue();
});

it('выполняет миграции пакета стандартной командой migrate', function (): void {
    $this->artisan('migrate', ['--force' => true])
        ->assertExitCode(0);

    expect(Schema::hasTable('available_telegram_bots'))
        ->toBeTrue()
        ->and(Schema::hasTable('telegram_connected_users'))
        ->toBeTrue()
        ->and(Schema::hasTable('telegga_webhook_events'))
        ->toBeTrue();
});
