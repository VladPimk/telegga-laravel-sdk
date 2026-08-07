<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

final readonly class UserGroupMembershipData extends ApiResponseData
{
    /**
     * Create a user group membership result.
     */
    public function __construct(
        public string $group_id,
        public string $user_id,
        public bool $added,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
