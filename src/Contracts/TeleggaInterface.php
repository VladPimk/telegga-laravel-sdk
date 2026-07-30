<?php

declare(strict_types=1);

namespace Telegga\Laravel\Contracts;

use Illuminate\Support\Collection;

interface TeleggaInterface
{
    /**
     * Создать подключение пользователя.
     */
    public function createConnection(
        string $name,
        ?string $email = null,
        ?int $userId = null,
    ): object;

    /**
     * Повторно отправить существующее подключение.
     */
    public function retryConnection(string $uuid): object;

    /**
     * Получить список доступных ботов.
     *
     * @return Collection<int, object>
     */
    public function getBots(): Collection;
}
