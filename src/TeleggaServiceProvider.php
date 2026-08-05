<?php

declare(strict_types=1);

namespace Telegga\Laravel;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Telegga\Laravel\Console\Commands\ClearWebhookEventsCommand;
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

        $this->app->bind(TeleggaClient::class, function (): TeleggaClient {
            return new TeleggaClient(
                http: $this->app->make(Factory::class),
                baseUrl: (string) config(key: 'telegga.base_url'),
                apiKey: (string) config(key: 'telegga.api_key'),
                timeout: (int) config(key: 'telegga.timeout'),
                connectTimeout: (int) config(key: 'telegga.connect_timeout'),
            );
        });

        $this->app->bind(BotService::class);
        $this->app->bind(ConnectionService::class);
        $this->app->bind(UserService::class);
        $this->app->bind(MessageService::class);
        $this->app->bind(MediaService::class);
        $this->app->bind(GroupService::class);
        $this->app->bind(BroadcastService::class);
        $this->app->bind(WebhookService::class);
        $this->app->bind(ConnectionContextResolver::class);
        $this->app->bind(TeleggaInterface::class, Telegga::class);
    }

    /**
     * Загрузить ресурсы пакета.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearWebhookEventsCommand::class,
            ]);
        }

        $this->loadRoutesFrom(
            path: __DIR__.'/../routes/webhooks.php',
        );

        $this->loadMigrationsFrom(
            paths: __DIR__.'/../database/migrations',
        );

        $this->publishes([
            __DIR__.'/../config/telegga.php' => config_path(path: 'telegga.php'),
        ], groups: 'telegga-config');
    }
}
