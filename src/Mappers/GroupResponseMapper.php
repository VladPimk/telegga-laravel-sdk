<?php

declare(strict_types=1);

namespace Telegga\Laravel\Mappers;

use Telegga\Laravel\Dto\GroupData;
use Telegga\Laravel\Dto\GroupMembersAddedData;
use Telegga\Laravel\Dto\GroupPageData;

final class GroupResponseMapper
{
    /**
     * Создать mapper ответов групп.
     */
    public function __construct(
        private readonly ResponseReader $reader,
    ) {}

    /**
     * Преобразовать ответ создания группы.
     */
    public function fromCreate(mixed $response): GroupData
    {
        return $this->mapGroup(response: $response, context: 'group creation response');
    }

    /**
     * Преобразовать ответ списка групп.
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
     * Преобразовать ответ получения группы.
     */
    public function fromGet(mixed $response): GroupData
    {
        return $this->mapGroup(response: $response, context: 'group response');
    }

    /**
     * Преобразовать ответ обновления группы.
     */
    public function fromUpdate(mixed $response): GroupData
    {
        return $this->mapGroup(response: $response, context: 'group update response');
    }

    /**
     * Преобразовать ответ массового добавления участников.
     */
    public function fromAddMembers(mixed $response): GroupMembersAddedData
    {
        $context = 'group members response';
        $response = $this->reader->object(response: $response, context: $context);

        return new GroupMembersAddedData(
            added: $this->reader->requiredInteger(response: $response, field: 'added', context: $context),
            not_found: collect($this->reader->requiredStringArray(
                response: $response,
                field: 'not_found',
                context: $context,
            ))->values(),
            raw: $response,
        );
    }

    /**
     * Преобразовать данные группы.
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
