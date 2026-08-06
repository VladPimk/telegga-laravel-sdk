<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

final readonly class QueuedMessageData extends ApiResponseData
{
    /**
     * Создать данные поставленного в очередь сообщения.
     */
    public function __construct(
        public string $message_id,
        public string $status,
        public ?string $created_at,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
