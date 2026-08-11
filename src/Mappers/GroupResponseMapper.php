<?php

declare(strict_types=1);

namespace Telegga\Laravel\Mappers;

use Telegga\Laravel\Dto\GroupData;
use Telegga\Laravel\Dto\GroupMembersAddedData;
use Telegga\Laravel\Dto\GroupPageData;

final class GroupResponseMapper
{
    /**
     * Create the group response mapper.
     */
    public function __construct(
        private readonly ResponseReader $reader,
    ) {}

    /**
     * Map a group creation response.
     */
    public function fromCreate(mixed $response): GroupData
    {
        return $this->mapGroup(response: $response, context: 'group creation response');
    }

    /**
     * Map a group list response.
     */
    public function fromList(mixed $response): GroupPageData
    {
        $context = 'group list response';
        $response = $this->reader->object(response: $response, context: $context);
        $groups = $this->reader->requiredArray(response: $response, field: 'data', context: $context);
        $nextCursor = $this->reader->nullableString(
            response: $response,
            field: 'next_cursor',
            context: $context,
        );

        return new GroupPageData(
            data: collect($groups)
                ->map(fn (mixed $group): GroupData => $this->mapGroup(
                    response: $group,
                    context: 'group list item response',
                ))
                ->values(),
            next_cursor: $nextCursor === '' ? null : $nextCursor,
            raw: $response,
        );
    }

    /**
     * Map a group response.
     */
    public function fromGet(mixed $response): GroupData
    {
        return $this->mapGroup(response: $response, context: 'group response');
    }

    /**
     * Map a group update response.
     */
    public function fromUpdate(mixed $response): GroupData
    {
        return $this->mapGroup(response: $response, context: 'group update response');
    }

    /**
     * Map a bulk member addition response.
     */
    public function fromAddMembers(mixed $response): GroupMembersAddedData
    {
        $context = 'group members response';
        $response = $this->reader->object(response: $response, context: $context);

        return new GroupMembersAddedData(
            added: $this->reader->requiredInteger(response: $response, field: 'added', context: $context),
            not_found: collect($this->reader->optionalStringArray(
                response: $response,
                field: 'not_found',
                context: $context,
            ))->values(),
            raw: $response,
        );
    }

    /**
     * Map group data.
     */
    private function mapGroup(mixed $response, string $context): GroupData
    {
        $response = $this->reader->object(response: $response, context: $context);

        return new GroupData(
            group_id: $this->reader->requiredString(response: $response, field: 'group_id', context: $context),
            name: $this->reader->requiredString(response: $response, field: 'name', context: $context),
            bot_id: $this->reader->nullableString(response: $response, field: 'bot_id', context: $context),
            description: $this->reader->nullableString(response: $response, field: 'description', context: $context),
            members_count: $this->reader->nullableInteger(response: $response, field: 'members_count', context: $context),
            raw: $response,
        );
    }
}
