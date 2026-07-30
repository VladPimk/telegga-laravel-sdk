<?php

declare(strict_types=1);

namespace Telegga\Laravel;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
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
        $this->app->singleton(UserService::class);
        $this->app->singleton(MessageService::class);
        $this->app->singleton(MediaService::class);
        $this->app->singleton(GroupService::class);
        $this->app->singleton(BroadcastService::class);
        $this->app->singleton(WebhookService::class);
        $this->app->singleton(ConnectionContextResolver::class);
        $this->app->singleton(TeleggaInterface::class, Telegga::class);
    }

    /**
     * Загрузить ресурсы пакета.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(
            path: __DIR__.'/../routes/webhooks.php',
        );

        $this->publishes([
            __DIR__.'/../config/telegga.php' => config_path(path: 'telegga.php'),
        ], groups: 'telegga-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path(path: 'migrations'),
        ], groups: 'telegga-migrations');
    }
}
