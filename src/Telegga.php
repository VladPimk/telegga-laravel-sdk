<?php

declare(strict_types=1);

namespace Telegga\Laravel;

use Illuminate\Support\Collection;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Services\BotService;
use Telegga\Laravel\Services\ConnectionService;

final class Telegga implements TeleggaInterface
{
    /**
     * Создать сервис Telegga.
     */
    public function __construct(
        private readonly ConnectionService $connections,
        private readonly BotService $bots,
    ) {}

    /**
     * Создать подключение пользователя.
     */
    public function createConnection(
        string $name,
        ?string $email = null,
        ?int $userId = null,
    ): object {
        return $this->connections->create(
            name: $name,
            email: $email,
            userId: $userId,
        );
    }

    /**
     * Повторно отправить существующее подключение.
     */
    public function retryConnection(string $uuid): object
    {
        return $this->connections->retry(uuid: $uuid);
    }

    /**
     * Получить список доступных ботов.
     *
     * @return Collection<int, object>
     */
    public function getBots(): Collection
    {
        return $this->bots->getAll();
    }
}
