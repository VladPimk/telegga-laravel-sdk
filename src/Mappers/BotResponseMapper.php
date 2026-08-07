<?php

declare(strict_types=1);

namespace Telegga\Laravel\Mappers;

use Illuminate\Support\Collection;
use Telegga\Laravel\Dto\BotData;

final class BotResponseMapper
{
    /**
     * Create the bot response mapper.
     */
    public function __construct(
        private readonly ResponseReader $reader,
    ) {}

    /**
     * Map a bot list response.
     *
     * @return Collection<int, BotData>
     */
    public function fromList(mixed $response): Collection
    {
        $response = $this->reader->object(
            response: $response,
            context: 'bot list response',
        );
        $bots = $this->reader->requiredArray(
            response: $response,
            field: 'data',
            context: 'bot list response',
        );

        return collect($bots)
            ->map(fn (mixed $bot): BotData => $this->mapBot(response: $bot))
            ->values();
    }

    /**
     * Map bot data.
     */
    private function mapBot(mixed $response): BotData
    {
        $response = $this->reader->object(
            response: $response,
            context: 'bot response',
        );

        return new BotData(
            bot_id: $this->reader->requiredString(
                response: $response,
                field: 'bot_id',
                context: 'bot response',
            ),
            username: $this->reader->requiredString(
                response: $response,
                field: 'username',
                context: 'bot response',
            ),
            display_name: $this->reader->nullableString(
                response: $response,
                field: 'display_name',
                context: 'bot response',
            ),
            status: $this->reader->requiredString(
                response: $response,
                field: 'status',
                context: 'bot response',
            ),
            raw: $response,
        );
    }
}
