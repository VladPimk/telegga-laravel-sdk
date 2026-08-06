<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

use Illuminate\Support\Collection;

final readonly class UserPageData extends ApiResponseData
{
    /**
     * Создать страницу пользователей Telegga.
     *
     * @param  Collection<int, UserData>  $data
     */
    public function __construct(
        public Collection $data,
        public ?string $next_cursor,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
