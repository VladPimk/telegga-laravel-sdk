<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Support\Collection;
use Telegga\Laravel\Exceptions\GroupException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Http\TeleggaClient;
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
    ) {}

    /**
     * Создать группу для бота подключения.
     */
    public function create(
        string $uuid,
        string $name,
        ?string $description = null,
    ): object {
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
            return $this->ensureObject(
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
     *
     * @return Collection<int, object>
     */
    public function getAll(string $uuid): Collection
    {
        $context = $this->contexts->resolveBot(uuid: $uuid);

        try {
            return $this->ensureCollection(
                response: $this->client->get(
                    uri: 'groups',
                    query: ['bot_id' => $context->link->bot_id],
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
    public function get(string $groupId): object
    {
        $this->validateGroupId(groupId: $groupId);

        try {
            return $this->ensureObject(
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
    public function update(string $groupId, array $data): object
    {
        $this->validateGroupId(groupId: $groupId);

        if ($data === []) {
            throw new GroupException(
                message: 'Group update data cannot be empty.',
                groupId: $groupId,
            );
        }

        try {
            return $this->ensureObject(
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
    public function addConnection(string $uuid, string $groupId): object
    {
        $this->validateGroupId(groupId: $groupId);
        $context = $this->contexts->resolveUser(uuid: $uuid);
        $userId = $this->getUserId(
            user: $context->user,
            uuid: $uuid,
            groupId: $groupId,
        );

        try {
            return $this->users->addToGroup(
                userId: $userId,
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
        $context = $this->contexts->resolveUser(uuid: $uuid);
        $userId = $this->getUserId(
            user: $context->user,
            uuid: $uuid,
            groupId: $groupId,
        );

        try {
            $this->users->removeFromGroup(
                userId: $userId,
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
    public function addMembers(string $groupId, array $uuids): object
    {
        $this->validateGroupId(groupId: $groupId);
        $uuids = $this->normalizeUuids(uuids: $uuids, groupId: $groupId);
        $userIds = [];

        foreach ($uuids as $uuid) {
            $context = $this->contexts->resolveUser(uuid: $uuid);
            $userIds[] = $this->getUserId(
                user: $context->user,
                uuid: $uuid,
                groupId: $groupId,
            );
        }

        $userIds = array_values(array_unique($userIds));

        try {
            return $this->ensureObject(
                response: $this->client->post(
                    uri: 'groups/'.rawurlencode($groupId).'/members',
                    data: ['user_ids' => $userIds],
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
        $context = $this->contexts->resolveUser(uuid: $uuid);
        $userId = $this->getUserId(
            user: $context->user,
            uuid: $uuid,
            groupId: $groupId,
        );

        try {
            $this->client->delete(
                uri: 'groups/'.rawurlencode($groupId).'/members/'.rawurlencode($userId),
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
     * Получить идентификатор пользователя Telegga.
     */
    private function getUserId(object $user, string $uuid, string $groupId): string
    {
        $userId = $user->user_id ?? null;

        if (! is_string($userId) || trim($userId) === '') {
            throw new GroupException(
                message: 'Telegga user response does not contain user_id.',
                groupId: $groupId,
                connectionUuid: $uuid,
            );
        }

        return $userId;
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

    /**
     * Проверить объект ответа группы.
     */
    private function ensureObject(mixed $response): object
    {
        if (! is_object($response)) {
            throw new TeleggaApiException(
                message: 'Telegga returned an invalid group response.',
                status: 0,
                apiCode: 'invalid_response',
            );
        }

        return $response;
    }

    /**
     * Проверить список групп.
     *
     * @return Collection<int, object>
     */
    private function ensureCollection(mixed $response): Collection
    {
        if (! is_object($response) || ! is_array($response->data ?? null)) {
            throw new TeleggaApiException(
                message: 'Telegga returned an invalid group list response.',
                status: 0,
                apiCode: 'invalid_response',
            );
        }

        foreach ($response->data as $group) {
            if (! is_object($group)) {
                throw new TeleggaApiException(
                    message: 'Telegga returned an invalid group list response.',
                    status: 0,
                    apiCode: 'invalid_response',
                );
            }
        }

        return collect($response->data)->values();
    }
}
