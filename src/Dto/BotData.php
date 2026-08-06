<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

final readonly class BotData extends ApiResponseData
{
    /**
     * Создать данные Telegram-бота.
     */
    public function __construct(
        public string $bot_id,
        public string $username,
        public ?string $display_name,
        public string $status,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
