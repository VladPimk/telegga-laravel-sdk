<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

final readonly class BroadcastCancellationData extends ApiResponseData
{
    /**
     * Создать результат отмены рассылки.
     */
    public function __construct(
        public string $status,
        public int $cancelled_messages,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
