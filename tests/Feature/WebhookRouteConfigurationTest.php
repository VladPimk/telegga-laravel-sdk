<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Telegga\Laravel\Http\Middleware\VerifyWebhookToken;
use Telegga\Laravel\TeleggaServiceProvider;

it('не регистрирует маршрут webhook при отключении в конфигурации', function (): void {
    $originalRoutes = Route::getRoutes();

    try {
        Route::setRoutes(new RouteCollection);
        config()->set('telegga.webhooks.enabled', false);

        (new TeleggaServiceProvider(app: $this->app))->boot();

        expect(Route::getRoutes()->getByName('telegga.webhooks.connect-account'))
            ->toBeNull();

        $this
            ->postJson('/webhooks/v1/telegram/connect-account')
            ->assertNotFound();
    } finally {
        Route::setRoutes($originalRoutes);
    }
});

it('регистрирует webhook с настроенными prefix и middleware', function (): void {
    $originalRoutes = Route::getRoutes();

    try {
        Route::setRoutes(new RouteCollection);
        config()->set('telegga.webhooks.enabled', true);
        config()->set('telegga.webhooks.prefix', 'integrations/telegga');
        config()->set('telegga.webhooks.middleware', [
            'throttle:10,1',
            'bindings',
        ]);

        (new TeleggaServiceProvider(app: $this->app))->boot();

        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('telegga.webhooks.connect-account');

        expect($route)
            ->not->toBeNull()
            ->and($route?->uri())
            ->toBe('integrations/telegga/connect-account')
            ->and($route?->gatherMiddleware())
            ->toBe([
                'throttle:10,1',
                'bindings',
                VerifyWebhookToken::class,
            ]);
    } finally {
        Route::setRoutes($originalRoutes);
    }
});
