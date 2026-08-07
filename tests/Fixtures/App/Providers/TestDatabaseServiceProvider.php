<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class TestDatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register test application migrations.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(
            paths: __DIR__.'/../../database/migrations',
        );
    }
}
