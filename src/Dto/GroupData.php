<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

final readonly class GroupData extends ApiResponseData
{
    /**
     * Create group data.
     */
    public function __construct(
        public string $group_id,
        public string $name,
        public ?string $bot_id,
        public ?string $description,
        public ?int $members_count,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
