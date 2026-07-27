<?php

declare(strict_types=1);

use Telegga\Laravel\TeleggaServiceProvider;

it('загружает сервис провайдер пакета', function () {
    expect($this->app->getProvider(TeleggaServiceProvider::class))
        ->toBeInstanceOf(TeleggaServiceProvider::class);
});
