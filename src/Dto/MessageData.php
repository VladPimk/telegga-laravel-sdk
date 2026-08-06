<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

use Illuminate\Support\Collection;

final readonly class MessageData extends ApiResponseData
{
    /**
     * Создать данные сообщения.
     *
     * @param  Collection<int, DeliveryAttemptData>  $delivery_attempts
     */
    public function __construct(
        public string $message_id,
        public string $status,
        public ?string $type,
        public ?int $attempts,
        public ?int $telegram_message_id,
        public ?string $created_at,
        public ?string $sent_at,
        public ?string $error_code,
        public ?string $error_message,
        public Collection $delivery_attempts,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
