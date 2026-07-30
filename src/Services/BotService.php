<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Support\Collection;
use Telegga\Laravel\Http\TeleggaClient;

final class BotService
{
    /**
     * Создать сервис ботов.
     */
    public function __construct(
        private readonly TeleggaClient $client,
    ) {}

    /**
     * Получить список доступных ботов.
     *
     * @return Collection<int, object>
     */
    public function getAll(): Collection
    {
        $response = $this->client->get(uri: 'bots')->object();

        if (! is_object($response) || ! is_array($response->data ?? null)) {
            return collect();
        }

        return collect($response->data)->values();
    }
}
