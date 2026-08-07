<?php

declare(strict_types=1);

use App\Models\User;

return [
    'base_url' => env('TELEGGA_BASE_URL', 'https://api.telegga.net/api/v1'),
    'api_key' => env('TELEGGA_API_KEY'),
    'user_model' => User::class,
    'users_table' => 'users',
    'webhook_token' => [
        env('TELEGGA_WEBHOOK_TOKEN'),
        env('TELEGGA_WEBHOOK_PREVIOUS_TOKEN'),
    ],
    'webhooks' => [
        'enabled' => env('TELEGGA_WEBHOOKS_ENABLED', true),
        'prefix' => env('TELEGGA_WEBHOOKS_PREFIX', 'webhooks/v1/telegram'),
        'middleware' => [
            'throttle:60,1',
        ],
    ],
    'timeout' => env('TELEGGA_TIMEOUT', 15),
    'connect_timeout' => env('TELEGGA_CONNECT_TIMEOUT', 5),
    'retry' => [
        'times' => env('TELEGGA_RETRY_TIMES', 3),
        'sleep_ms' => env('TELEGGA_RETRY_SLEEP_MS', 200),
    ],
];
