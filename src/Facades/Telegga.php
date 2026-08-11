<?php

declare(strict_types=1);

namespace Telegga\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Telegga\Laravel\Contracts\TeleggaInterface;

final class Telegga extends Facade
{
    /**
     * Get the component name from the container.
     */
    protected static function getFacadeAccessor(): string
    {
        return TeleggaInterface::class;
    }
}
