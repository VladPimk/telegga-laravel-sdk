<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Telegga\Laravel\Http\Controllers\ConnectAccountWebhookController;
use Telegga\Laravel\Http\Middleware\VerifyWebhookToken;

$middleware = [
    ...(array) config(key: 'telegga.webhooks.middleware', default: []),
    VerifyWebhookToken::class,
];

Route::prefix((string) config(
    key: 'telegga.webhooks.prefix',
    default: 'webhooks/v1/telegram',
))
    ->middleware($middleware)
    ->group(function (): void {
        Route::post(
            uri: '/connect-account',
            action: ConnectAccountWebhookController::class,
        )->name('telegga.webhooks.connect-account');
    });
