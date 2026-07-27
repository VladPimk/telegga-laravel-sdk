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
}
