<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

final readonly class UserLinkData extends ApiResponseData
{
    /**
     * Создать данные привязки пользователя к боту.
     */
    public function __construct(
        public string $bot_id,
        public ?string $bot_username,
        public string $status,
        public ?string $linked_at,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
