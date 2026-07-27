<?php

declare(strict_types=1);

namespace Telegga\Laravel\Contracts;

interface TeleggaInterface
{
    /**
     * Получить список доступных ботов.
     *
     * @return array<string, mixed>
     */
    public function getBots(): array;
}
