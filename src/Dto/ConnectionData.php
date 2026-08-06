<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

final readonly class ConnectionData extends ApiResponseData
{
    /**
     * Создать данные подключения пользователя.
     */
    public function __construct(
        public string $user_id,
        public string $external_id,
        public string $link_status,
        public ?string $link_code,
        public ?string $link_url,
        public ?string $expires_at,
        public ?string $group_id,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
