<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

final readonly class UserGroupData extends ApiResponseData
{
    /**
     * Создать данные группы пользователя.
     */
    public function __construct(
        public string $group_id,
        public string $name,
        public string $bot_id,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
