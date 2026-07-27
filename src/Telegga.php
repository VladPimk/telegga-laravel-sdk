<?php

declare(strict_types=1);

namespace Telegga\Laravel;

use Illuminate\Support\Collection;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Data\BotData;
use Telegga\Laravel\Http\TeleggaClient;
use UnexpectedValueException;

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
     * @return Collection<int, BotData>
     */
    public function getBots(): Collection
    {
        $response = $this->client->get(uri: 'bots');
        $bots = $response['data'] ?? null;

        if (! is_array($bots)) {
            throw new UnexpectedValueException(
                message: 'Telegga bots response data is invalid.',
            );
        }

        return collect($bots)
            ->values()
            ->map(function (mixed $bot): BotData {
                if (! is_array($bot)) {
                    throw new UnexpectedValueException(
                        message: 'Telegga bot response item is invalid.',
                    );
                }

                return BotData::fromArray(data: $bot);
            });
    }
}
