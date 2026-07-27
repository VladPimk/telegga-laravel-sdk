<?php

declare(strict_types=1);

namespace Telegga\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Telegga\Laravel\Contracts\TeleggaInterface;

final class Telegga extends Facade
{
    /**
     * Получить имя компонента из контейнера.
     */
    protected static function getFacadeAccessor(): string
    {
        return TeleggaInterface::class;
    }
}
