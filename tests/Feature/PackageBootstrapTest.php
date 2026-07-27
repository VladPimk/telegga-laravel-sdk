<?php

declare(strict_types=1);

use Telegga\Laravel\Contracts\TeleggaInterface;
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

it('публикует миграции пакета', function (): void {
    $paths = TeleggaServiceProvider::pathsToPublish(
        provider: TeleggaServiceProvider::class,
        group: 'telegga-migrations',
    );
    $source = str_replace('\\', '/', (string) array_key_first($paths));
    $destination = str_replace('\\', '/', (string) array_values($paths)[0]);

    expect($paths)
        ->toHaveCount(1)
        ->and($source)
        ->toEndWith('database/migrations')
        ->and($destination)
        ->toEndWith('database/migrations');
});
