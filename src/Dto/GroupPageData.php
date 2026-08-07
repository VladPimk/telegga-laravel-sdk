<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

use Illuminate\Support\Collection;

final readonly class GroupPageData extends ApiResponseData
{
    /**
     * Create a group page.
     *
     * @param  Collection<int, GroupData>  $data
     */
    public function __construct(
        public Collection $data,
        public ?string $next_cursor,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
