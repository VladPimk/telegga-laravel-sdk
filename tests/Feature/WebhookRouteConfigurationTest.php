<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Telegga\Laravel\Http\Middleware\VerifyWebhookToken;
use Telegga\Laravel\TeleggaServiceProvider;

it('does not register the webhook route when disabled in configuration', function (): void {
    $originalRoutes = Route::getRoutes();

    if (! $originalRoutes instanceof RouteCollection) {
        throw new RuntimeException('The router did not return a concrete route collection.');
    }

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

it('registers the webhook with configured prefix and middleware', function (): void {
    $originalRoutes = Route::getRoutes();

    if (! $originalRoutes instanceof RouteCollection) {
        throw new RuntimeException('The router did not return a concrete route collection.');
    }

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

it('keeps default webhook middleware with a partial legacy configuration', function (): void {
    $originalRoutes = Route::getRoutes();

    if (! $originalRoutes instanceof RouteCollection) {
        throw new RuntimeException('The router did not return a concrete route collection.');
    }

    try {
        Route::setRoutes(new RouteCollection);
        config()->set('telegga.webhooks', [
            'enabled' => true,
            'prefix' => 'legacy/telegga',
        ]);

        $provider = new TeleggaServiceProvider(app: $this->app);
        $provider->register();
        $provider->boot();

        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('telegga.webhooks.connect-account');

        expect($route)
            ->not->toBeNull()
            ->and($route?->uri())
            ->toBe('legacy/telegga/connect-account')
            ->and($route?->gatherMiddleware())
            ->toBe([
                'throttle:60,1',
                VerifyWebhookToken::class,
            ]);
    } finally {
        Route::setRoutes($originalRoutes);
    }
});
