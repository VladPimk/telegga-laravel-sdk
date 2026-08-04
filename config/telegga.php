<?php

declare(strict_types=1);

return [
    'base_url' => env('TELEGGA_BASE_URL', 'https://api.telegga.net/api/v1'),
    'api_key' => env('TELEGGA_API_KEY'),
    'webhook_token' => env('TELEGGA_WEBHOOK_TOKEN'),
    'timeout' => env('TELEGGA_TIMEOUT', 15),
    'connect_timeout' => env('TELEGGA_CONNECT_TIMEOUT', 5),
];
