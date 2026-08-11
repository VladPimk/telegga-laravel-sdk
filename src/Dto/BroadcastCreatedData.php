<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

final readonly class BroadcastCreatedData extends ApiResponseData
{
    /**
     * Create started broadcast data.
     */
    public function __construct(
        public string $broadcast_id,
        public string $status,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
