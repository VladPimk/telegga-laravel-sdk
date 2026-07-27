<?php

declare(strict_types=1);

namespace Telegga\Laravel;

use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Http\TeleggaClient;

final class Telegga implements TeleggaInterface
{
    /**
     * Создать сервис Telegga.
     */
    public function __construct(
        private readonly TeleggaClient $client,
    ) {}

    /**
     * Получить список доступных ботов.
     *
     * @return array<string, mixed>
     */
    public function getBots(): array
    {
        return $this->client->get(uri: 'bots');
    }
}
