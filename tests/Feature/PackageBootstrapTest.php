<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Resolvers\ConnectionContextResolver;
use Telegga\Laravel\Services\BotService;
use Telegga\Laravel\Services\ConnectionService;
use Telegga\Laravel\Services\MessageService;
use Telegga\Laravel\Services\UserService;
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
        ->toBe('https://api.telegga.net/api/v1');
});

it('регистрирует внутренние сервисы как singleton', function (): void {
    expect(app(BotService::class))
        ->toBe(app(BotService::class))
        ->and(app(ConnectionService::class))
        ->toBe(app(ConnectionService::class))
        ->and(app(UserService::class))
        ->toBe(app(UserService::class))
        ->and(app(MessageService::class))
        ->toBe(app(MessageService::class))
        ->and(app(ConnectionContextResolver::class))
        ->toBe(app(ConnectionContextResolver::class));
});

it('выполняет миграции пакета стандартной командой migrate', function (): void {
    $this->artisan('migrate', ['--force' => true])
        ->assertExitCode(0);

    expect(Schema::hasTable('available_telegram_bots'))
        ->toBeTrue()
        ->and(Schema::hasTable('telegram_connected_users'))
        ->toBeTrue();
});
