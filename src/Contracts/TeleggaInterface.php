<?php

declare(strict_types=1);

namespace Telegga\Laravel\Contracts;

use Illuminate\Support\Collection;

interface TeleggaInterface
{
    /**
     * Получить список доступных ботов.
     *
     * @return Collection<int, object>
     */
    public function getBots(): Collection;
}
