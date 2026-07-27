<?php

declare(strict_types=1);

namespace Telegga\Laravel\Contracts;

use Illuminate\Support\Collection;
use Telegga\Laravel\Data\BotData;

interface TeleggaInterface
{
    /**
     * Получить список доступных ботов.
     *
     * @return Collection<int, BotData>
     */
    public function getBots(): Collection;
}
