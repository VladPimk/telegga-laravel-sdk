<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

use Illuminate\Support\Collection;

final readonly class UserData extends ApiResponseData
{
    /**
     * Создать данные пользователя Telegga.
     *
     * @param  Collection<int, UserLinkData>  $links
     * @param  Collection<int, UserGroupData>  $groups
     */
    public function __construct(
        public string $user_id,
        public string $external_id,
        public ?string $display_name,
        public ?string $created_at,
        public ?string $email,
        public ?string $status,
        public ?string $link_status,
        public Collection $links,
        public Collection $groups,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
