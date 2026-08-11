<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

final readonly class DeliveryAttemptData extends ApiResponseData
{
    /**
     * Create message delivery attempt data.
     */
    public function __construct(
        public string $at,
        public bool $ok,
        public int $latency_ms,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
