<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

final readonly class BroadcastData extends ApiResponseData
{
    /**
     * Create broadcast data.
     */
    public function __construct(
        public string $broadcast_id,
        public string $status,
        public ?int $total,
        public ?int $sent,
        public ?int $failed,
        public ?string $created_at,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
