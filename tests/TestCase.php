<?php

declare(strict_types=1);

namespace Telegga\Laravel\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Telegga\Laravel\TeleggaServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Получить сервис-провайдеры пакета.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [TeleggaServiceProvider::class];
    }

    /**
     * Настроить тестовое приложение.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('telegga.base_url', 'https://api.telegga.net/api/v1');
        $app['config']->set('telegga.api_key', 'tg_live_test');
        $app['config']->set('telegga.webhook_token', 'webhook-secret');
        $app['config']->set('telegga.timeout', 15);
        $app['config']->set('telegga.connect_timeout', 5);
    }
}
