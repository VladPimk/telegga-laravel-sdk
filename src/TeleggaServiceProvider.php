<?php

declare(strict_types=1);

namespace Telegga\Laravel;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Http\TeleggaClient;
use Telegga\Laravel\Services\BotService;
use Telegga\Laravel\Services\ConnectionService;

final class TeleggaServiceProvider extends ServiceProvider
{
    /**
     * Зарегистрировать сервисы пакета.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            path: __DIR__.'/../config/telegga.php',
            key: 'telegga',
        );

        $this->app->singleton(TeleggaClient::class, function (): TeleggaClient {
            return new TeleggaClient(
                http: $this->app->make(Factory::class),
                baseUrl: (string) config(key: 'telegga.base_url'),
                apiKey: (string) config(key: 'telegga.api_key'),
                timeout: (int) config(key: 'telegga.timeout'),
            );
        });

        $this->app->singleton(BotService::class);
        $this->app->singleton(ConnectionService::class);
        $this->app->singleton(TeleggaInterface::class, Telegga::class);
    }

    /**
     * Загрузить ресурсы пакета.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/telegga.php' => config_path(path: 'telegga.php'),
        ], groups: 'telegga-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path(path: 'migrations'),
        ], groups: 'telegga-migrations');
    }
}
