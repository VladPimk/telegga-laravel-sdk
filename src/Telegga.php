<?php

declare(strict_types=1);

namespace Telegga\Laravel;

use Illuminate\Support\Collection;
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
     * @return Collection<int, object>
     */
    public function getBots(): Collection
    {
        $response = $this->client->get(uri: 'bots');

        return collect($response['data'] ?? [])
            ->values()
            ->map(static fn (mixed $bot): object => (object) $bot);
    }
}
