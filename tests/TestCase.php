<?php

declare(strict_types=1);

namespace Telegga\Laravel\Tests;

use App\Providers\TestDatabaseServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;
use Telegga\Laravel\TeleggaServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Load a Telegga API response JSON fixture.
     *
     * @return array<string, mixed>
     */
    protected function apiFixture(string $path): array
    {
        $fixturePath = __DIR__.'/Fixtures/Api/'.$path.'.json';
        $contents = file_get_contents($fixturePath);

        if ($contents === false) {
            throw new RuntimeException("Telegga API fixture could not be read: {$path}.");
        }

        $fixture = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($fixture)) {
            throw new RuntimeException("Telegga API fixture must contain a JSON object: {$path}.");
        }

        return $fixture;
    }

    /**
     * Drop the connections table to test unavailable schema errors.
     */
    protected function dropConnectionTable(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            Schema::dropIfExists('telegram_connected_users');
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * Get package service providers.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            TestDatabaseServiceProvider::class,
            TeleggaServiceProvider::class,
        ];
    }

    /**
     * Configure the test application.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('telegga.base_url', 'https://api.telegga.net/api/v1');
        $app['config']->set('telegga.api_key', 'tg_live_test');
        $app['config']->set('telegga.webhook_token', 'webhook-secret');
        $app['config']->set('telegga.webhooks.enabled', true);
        $app['config']->set('telegga.webhooks.prefix', 'webhooks/v1/telegram');
        $app['config']->set('telegga.webhooks.middleware', ['throttle:60,1']);
        $app['config']->set('telegga.timeout', 15);
        $app['config']->set('telegga.connect_timeout', 5);
        $app['config']->set('telegga.retry.times', 3);
        $app['config']->set('telegga.retry.sleep_ms', 0);
    }

    /**
     * Enable foreign key checks after refreshing the test schema.
     */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed()
    {
        Schema::enableForeignKeyConstraints();
    }
}
