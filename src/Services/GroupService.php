<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Telegga\Laravel\Dto\GroupData;
use Telegga\Laravel\Dto\GroupMembersAddedData;
use Telegga\Laravel\Dto\GroupPageData;
use Telegga\Laravel\Dto\UserGroupMembershipData;
use Telegga\Laravel\Exceptions\GroupException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Http\TeleggaClient;
use Telegga\Laravel\Mappers\GroupResponseMapper;
use Telegga\Laravel\Resolvers\ConnectionContextResolver;

final class GroupService
{
    /**
     * Создать сервис групп.
     */
    public function __construct(
        private readonly TeleggaClient $client,
        private readonly ConnectionContextResolver $contexts,
        private readonly UserService $users,
        private readonly GroupResponseMapper $mapper,
    ) {}

    /**
     * Создать группу для бота подключения.
     */
    public function create(
        string $uuid,
        string $name,
        ?string $description = null,
    ): GroupData {
        if (trim($name) === '') {
            throw new GroupException(
                message: 'Group name cannot be empty.',
                connectionUuid: $uuid,
            );
        }

        $context = $this->contexts->resolveBot(uuid: $uuid);
        $data = [
            'bot_id' => $context->link->bot_id,
            'name' => $name,
        ];

        if ($description !== null) {
            $data['description'] = $description;
        }

        try {
            return $this->mapper->fromCreate(
                response: $this->client->post(
                    uri: 'groups',
                    data: $data,
                )->object(),
            );
        } catch (TeleggaApiException $exception) {
            throw new GroupException(
                message: $exception->getMessage(),
                connectionUuid: $uuid,
                previous: $exception,
            );
        }
    }

    /**
     * Получить группы бота подключения.
     */
    public function getAll(string $uuid, ?string $cursor = null): GroupPageData
    {
        $context = $this->contexts->resolveBot(uuid: $uuid);
        $query = ['bot_id' => $context->link->bot_id];

        if ($cursor !== null && trim($cursor) !== '') {
            $query['cursor'] = trim($cursor);
        }

        try {
            return $this->mapper->fromList(
                response: $this->client->get(
                    uri: 'groups',
                    query: $query,
                )->object(),
            );
        } catch (TeleggaApiException $exception) {
            throw new GroupException(
                message: $exception->getMessage(),
                connectionUuid: $uuid,
                previous: $exception,
            );
        }
    }

    /**
     * Получить группу.
     */
    public function get(string $groupId): GroupData
    {
        $this->validateGroupId(groupId: $groupId);

        try {
            return $this->mapper->fromGet(
                response: $this->client->get(
                    uri: 'groups/'.rawurlencode($groupId),
                )->object(),
            );
        } catch (TeleggaApiException $exception) {
            throw new GroupException(
                message: $exception->getMessage(),
                groupId: $groupId,
                previous: $exception,
            );
        }
    }

    /**
     * Обновить группу.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $groupId, array $data): GroupData
    {
        $this->validateGroupId(groupId: $groupId);

        if ($data === []) {
            throw new GroupException(
                message: 'Group update data cannot be empty.',
                groupId: $groupId,
            );
        }

        try {
            return $this->mapper->fromUpdate(
                response: $this->client->put(
                    uri: 'groups/'.rawurlencode($groupId),
                    data: $data,
                    idempotent: true,
                )->object(),
            );
        } catch (TeleggaApiException $exception) {
            throw new GroupException(
                message: $exception->getMessage(),
                groupId: $groupId,
                previous: $exception,
            );
        }
    }

    /**
     * Удалить группу.
     */
    public function delete(string $groupId): void
    {
        $this->validateGroupId(groupId: $groupId);

        try {
            $this->client->delete(
                uri: 'groups/'.rawurlencode($groupId),
                idempotent: true,
            );
        } catch (TeleggaApiException $exception) {
            if ($exception->wasRetried() && $exception->apiCode === 'not_found') {
                return;
            }

            throw new GroupException(
                message: $exception->getMessage(),
                groupId: $groupId,
                previous: $exception,
            );
        }
    }

    /**
     * Добавить подключение в группу через маршрут пользователя.
     */
    public function addConnection(string $uuid, string $groupId): UserGroupMembershipData
    {
        $this->validateGroupId(groupId: $groupId);
        $connection = $this->contexts->resolveConnection(uuid: $uuid);

        try {
            return $this->users->addToGroup(
                userId: $connection->uuid,
                groupId: $groupId,
            );
        } catch (TeleggaApiException $exception) {
            throw new GroupException(
                message: $exception->getMessage(),
                groupId: $groupId,
                connectionUuid: $uuid,
                previous: $exception,
            );
        }
    }

    /**
     * Удалить подключение из группы через маршрут пользователя.
     */
    public function removeConnection(string $uuid, string $groupId): void
    {
        $this->validateGroupId(groupId: $groupId);
        $connection = $this->contexts->resolveConnection(uuid: $uuid);

        try {
            $this->users->removeFromGroup(
                userId: $connection->uuid,
                groupId: $groupId,
            );
        } catch (TeleggaApiException $exception) {
            throw new GroupException(
                message: $exception->getMessage(),
                groupId: $groupId,
                connectionUuid: $uuid,
                previous: $exception,
            );
        }
    }

    /**
     * Добавить подключения в группу через групповой маршрут.
     *
     * @param  array<int, string>  $uuids
     */
    public function addMembers(string $groupId, array $uuids): GroupMembersAddedData
    {
        $this->validateGroupId(groupId: $groupId);
        $uuids = $this->normalizeUuids(uuids: $uuids, groupId: $groupId);
        $this->contexts->resolveConnections(uuids: $uuids);

        try {
            return $this->mapper->fromAddMembers(
                response: $this->client->post(
                    uri: 'groups/'.rawurlencode($groupId).'/members',
                    data: ['external_ids' => $uuids],
                    idempotent: true,
                )->object(),
            );
        } catch (TeleggaApiException $exception) {
            throw new GroupException(
                message: $exception->getMessage(),
                groupId: $groupId,
                previous: $exception,
            );
        }
    }

    /**
     * Удалить подключение из группы через групповой маршрут.
     */
    public function removeMember(string $groupId, string $uuid): void
    {
        $this->validateGroupId(groupId: $groupId);
        $connection = $this->contexts->resolveConnection(uuid: $uuid);

        try {
            $this->client->delete(
                uri: 'groups/'.rawurlencode($groupId).'/members/'.rawurlencode($connection->uuid),
            );
        } catch (TeleggaApiException $exception) {
            throw new GroupException(
                message: $exception->getMessage(),
                groupId: $groupId,
                connectionUuid: $uuid,
                previous: $exception,
            );
        }
    }

    /**
     * Проверить идентификатор группы.
     */
    private function validateGroupId(string $groupId): void
    {
        if (trim($groupId) === '') {
            throw new GroupException(
                message: 'Group identifier cannot be empty.',
                groupId: $groupId,
            );
        }
    }

    /**
     * Нормализовать UUID подключений.
     *
     * @param  array<int, string>  $uuids
     * @return array<int, string>
     */
    private function normalizeUuids(array $uuids, string $groupId): array
    {
        $normalized = [];

        foreach ($uuids as $uuid) {
            if (! is_string($uuid) || trim($uuid) === '') {
                throw new GroupException(
                    message: 'Connection UUID cannot be empty.',
                    groupId: $groupId,
                    connectionUuid: is_string($uuid) ? $uuid : null,
                );
            }

            $normalized[] = trim($uuid);
        }

        $normalized = array_values(array_unique($normalized));

        if ($normalized === []) {
            throw new GroupException(
                message: 'Group members cannot be empty.',
                groupId: $groupId,
            );
        }

        if (count($normalized) > 10000) {
            throw new GroupException(
                message: 'Group members cannot exceed 10000 users.',
                groupId: $groupId,
            );
        }

        return $normalized;
    }
}
