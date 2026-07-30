<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Telegga\Laravel\Http\Controllers\ConnectAccountWebhookController;
use Telegga\Laravel\Http\Middleware\VerifyWebhookToken;

Route::post(
    uri: '/webhooks/v1/telegram/connect-account',
    action: ConnectAccountWebhookController::class,
)
    ->middleware(VerifyWebhookToken::class)
    ->name('telegga.webhooks.connect-account');
