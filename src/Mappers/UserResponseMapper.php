<?php

declare(strict_types=1);

namespace Telegga\Laravel\Mappers;

use Telegga\Laravel\Dto\ConnectionData;
use Telegga\Laravel\Dto\UserData;
use Telegga\Laravel\Dto\UserGroupData;
use Telegga\Laravel\Dto\UserGroupMembershipData;
use Telegga\Laravel\Dto\UserLinkData;
use Telegga\Laravel\Dto\UserPageData;

final class UserResponseMapper
{
    /**
     * Создать mapper ответов пользователей.
     */
    public function __construct(
        private readonly ResponseReader $reader,
    ) {}

    /**
     * Преобразовать ответ создания пользователя.
     */
    public function fromCreate(mixed $response): ConnectionData
    {
        return $this->mapConnection(response: $response, context: 'user creation response');
    }

    /**
     * Преобразовать ответ повторного выпуска кода.
     */
    public function fromRegenerateCode(mixed $response): ConnectionData
    {
        return $this->mapConnection(response: $response, context: 'connection code response');
    }

    /**
     * Преобразовать ответ точного поиска пользователя.
     */
    public function fromExternalIdLookup(mixed $response): UserData
    {
        return $this->mapUser(response: $response, context: 'user lookup response');
    }

    /**
     * Преобразовать ответ получения пользователя.
     */
    public function fromGet(mixed $response): UserData
    {
        return $this->mapUser(response: $response, context: 'user response');
    }

    /**
     * Преобразовать ответ обновления пользователя.
     */
    public function fromUpdate(mixed $response): UserData
    {
        return $this->mapUser(response: $response, context: 'user update response');
    }

    /**
     * Преобразовать ответ списка пользователей.
     */
    public function fromList(mixed $response): UserPageData
    {
        $response = $this->reader->object(
            response: $response,
            context: 'user list response',
        );
        $users = $this->reader->requiredArray(
            response: $response,
            field: 'data',
            context: 'user list response',
        );

        return new UserPageData(
            data: collect($users)
                ->map(fn (mixed $user): UserData => $this->mapUser(
                    response: $user,
                    context: 'user list item response',
                ))
                ->values(),
            next_cursor: $this->reader->nullableString(
                response: $response,
                field: 'next_cursor',
                context: 'user list response',
            ),
            raw: $response,
        );
    }

    /**
     * Преобразовать ответ добавления пользователя в группу.
     */
    public function fromAddToGroup(mixed $response): UserGroupMembershipData
    {
        $response = $this->reader->object(
            response: $response,
            context: 'user group membership response',
        );

        return new UserGroupMembershipData(
            group_id: $this->reader->requiredString(
                response: $response,
                field: 'group_id',
                context: 'user group membership response',
            ),
            user_id: $this->reader->requiredString(
                response: $response,
                field: 'user_id',
                context: 'user group membership response',
            ),
            added: $this->reader->requiredBoolean(
                response: $response,
                field: 'added',
                context: 'user group membership response',
            ),
            raw: $response,
        );
    }

    /**
     * Преобразовать ответ подключения пользователя.
     */
    private function mapConnection(mixed $response, string $context): ConnectionData
    {
        $response = $this->reader->object(response: $response, context: $context);

        return new ConnectionData(
            user_id: $this->reader->requiredString(response: $response, field: 'user_id', context: $context),
            external_id: $this->reader->requiredString(response: $response, field: 'external_id', context: $context),
            link_status: $this->reader->requiredString(response: $response, field: 'link_status', context: $context),
            link_code: $this->reader->nullableString(response: $response, field: 'link_code', context: $context),
            link_url: $this->reader->nullableString(response: $response, field: 'link_url', context: $context),
            expires_at: $this->reader->nullableString(response: $response, field: 'expires_at', context: $context),
            group_id: $this->reader->nullableString(response: $response, field: 'group_id', context: $context),
            raw: $response,
        );
    }

    /**
     * Преобразовать ответ пользователя.
     */
    private function mapUser(mixed $response, string $context): UserData
    {
        $response = $this->reader->object(response: $response, context: $context);
        $links = $this->reader->optionalArray(response: $response, field: 'links', context: $context);
        $groups = $this->reader->optionalArray(response: $response, field: 'groups', context: $context);

        return new UserData(
            user_id: $this->reader->requiredString(response: $response, field: 'user_id', context: $context),
            external_id: $this->reader->requiredString(response: $response, field: 'external_id', context: $context),
            display_name: $this->reader->nullableString(response: $response, field: 'display_name', context: $context),
            created_at: $this->reader->nullableString(response: $response, field: 'created_at', context: $context),
            email: $this->reader->nullableString(response: $response, field: 'email', context: $context),
            status: $this->reader->nullableString(response: $response, field: 'status', context: $context),
            link_status: $this->reader->nullableString(response: $response, field: 'link_status', context: $context),
            links: collect($links)
                ->map(fn (mixed $link): UserLinkData => $this->mapLink(response: $link))
                ->values(),
            groups: collect($groups)
                ->map(fn (mixed $group): UserGroupData => $this->mapUserGroup(response: $group))
                ->values(),
            raw: $response,
        );
    }

    /**
     * Преобразовать привязку пользователя.
     */
    private function mapLink(mixed $response): UserLinkData
    {
        $context = 'user link response';
        $response = $this->reader->object(response: $response, context: $context);

        return new UserLinkData(
            bot_id: $this->reader->requiredString(response: $response, field: 'bot_id', context: $context),
            bot_username: $this->reader->nullableString(response: $response, field: 'bot_username', context: $context),
            status: $this->reader->requiredString(response: $response, field: 'status', context: $context),
            linked_at: $this->reader->nullableString(response: $response, field: 'linked_at', context: $context),
            raw: $response,
        );
    }

    /**
     * Преобразовать группу пользователя.
     */
    private function mapUserGroup(mixed $response): UserGroupData
    {
        $context = 'user group response';
        $response = $this->reader->object(response: $response, context: $context);

        return new UserGroupData(
            group_id: $this->reader->requiredString(response: $response, field: 'group_id', context: $context),
            name: $this->reader->requiredString(response: $response, field: 'name', context: $context),
            bot_id: $this->reader->requiredString(response: $response, field: 'bot_id', context: $context),
            raw: $response,
        );
    }
}
