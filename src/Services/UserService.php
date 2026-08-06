<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use InvalidArgumentException;
use Telegga\Laravel\Dto\ConnectionData;
use Telegga\Laravel\Dto\UserData;
use Telegga\Laravel\Dto\UserGroupMembershipData;
use Telegga\Laravel\Dto\UserPageData;
use Telegga\Laravel\Http\TeleggaClient;
use Telegga\Laravel\Mappers\UserResponseMapper;

final class UserService
{
    /**
     * Создать сервис пользователей.
     */
    public function __construct(
        private readonly TeleggaClient $client,
        private readonly UserResponseMapper $mapper,
    ) {}

    /**
     * Создать или обновить пользователя Telegga.
     *
     * @param  array<string, mixed>  $meta
     */
    public function create(
        string $externalId,
        string $botId,
        string $displayName,
        ?string $email = null,
        array $meta = [],
        ?string $groupId = null,
    ): ConnectionData {
        $data = [
            'external_id' => $externalId,
            'bot_id' => $botId,
            'display_name' => $displayName,
        ];

        if ($email !== null) {
            $data['email'] = $email;
        }

        if ($meta !== []) {
            $data['meta'] = $meta;
        }

        if ($groupId !== null) {
            $data['group_id'] = $groupId;
        }

        return $this->mapper->fromCreate(
            response: $this->client->post(
                uri: 'users',
                data: $data,
                idempotent: true,
            )->object(),
        );
    }

    /**
     * Получить пользователя Telegga по внешнему идентификатору.
     */
    public function findByExternalId(string $externalId): UserData
    {
        return $this->mapper->fromExternalIdLookup(
            response: $this->client->get(
                uri: 'users',
                query: ['external_id' => $externalId],
            )->object(),
        );
    }

    /**
     * Получить список пользователей Telegga.
     *
     * Поиск по external_id возвращает одиночный объект и выполняется через findByExternalId().
     *
     * @param  array<string, string>  $query
     */
    public function getAll(array $query = []): UserPageData
    {
        if (array_key_exists(key: 'external_id', array: $query)) {
            throw new InvalidArgumentException(
                message: 'Use findByExternalId() for exact external_id lookup: the API returns a single object.',
            );
        }

        return $this->mapper->fromList(
            response: $this->client->get(
                uri: 'users',
                query: $query,
            )->object(),
        );
    }

    /**
     * Получить пользователя Telegga по идентификатору.
     */
    public function get(string $userId): UserData
    {
        return $this->mapper->fromGet(
            response: $this->client->get(
                uri: 'users/'.rawurlencode($userId),
            )->object(),
        );
    }

    /**
     * Обновить пользователя Telegga.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $userId, array $data): UserData
    {
        return $this->mapper->fromUpdate(
            response: $this->client->patch(
                uri: 'users/'.rawurlencode($userId),
                data: $data,
                idempotent: true,
            )->object(),
        );
    }

    /**
     * Удалить пользователя Telegga.
     */
    public function delete(string $userId): void
    {
        $this->client->delete(
            uri: 'users/'.rawurlencode($userId),
            idempotent: true,
        );
    }

    /**
     * Выпустить новый код подключения пользователя.
     */
    public function regenerateCode(string $userId, string $botId): ConnectionData
    {
        return $this->mapper->fromRegenerateCode(
            response: $this->client->post(
                uri: 'users/'.rawurlencode($userId).'/regenerate-code',
                data: ['bot_id' => $botId],
            )->object(),
        );
    }

    /**
     * Отвязать пользователя от бота.
     */
    public function unlink(string $userId, string $botId): void
    {
        $this->client->delete(
            uri: 'users/'.rawurlencode($userId).'/link',
            query: ['bot_id' => $botId],
            idempotent: true,
        );
    }

    /**
     * Добавить пользователя Telegga в группу.
     */
    public function addToGroup(string $userId, string $groupId): UserGroupMembershipData
    {
        return $this->mapper->fromAddToGroup(
            response: $this->client->post(
                uri: 'users/'.rawurlencode($userId).'/groups',
                data: ['group_id' => $groupId],
                idempotent: true,
            )->object(),
        );
    }

    /**
     * Удалить пользователя Telegga из группы.
     */
    public function removeFromGroup(string $userId, string $groupId): void
    {
        $this->client->delete(
            uri: 'users/'.rawurlencode($userId).'/groups/'.rawurlencode($groupId),
        );
    }
}
