<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

use Illuminate\Support\Collection;

final readonly class GroupMembersAddedData extends ApiResponseData
{
    /**
     * Создать результат массового добавления участников.
     *
     * @param  Collection<int, string>  $not_found
     */
    public function __construct(
        public int $added,
        public Collection $not_found,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
