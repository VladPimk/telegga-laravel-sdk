<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Http\TeleggaClient;

final class UserService
{
    /**
     * Создать сервис пользователей.
     */
    public function __construct(
        private readonly TeleggaClient $client,
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
    ): object {
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

        return $this->ensureObject(
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
    public function findByExternalId(string $externalId): object
    {
        return $this->ensureObject(
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
    public function getAll(array $query = []): object
    {
        if (array_key_exists(key: 'external_id', array: $query)) {
            throw new InvalidArgumentException(
                message: 'Use findByExternalId() for exact external_id lookup: the API returns a single object.',
            );
        }

        return $this->ensurePage(
            response: $this->client->get(
                uri: 'users',
                query: $query,
            )->object(),
        );
    }

    /**
     * Получить пользователя Telegga по идентификатору.
     */
    public function get(string $userId): object
    {
        return $this->ensureObject(
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
    public function update(string $userId, array $data): object
    {
        return $this->ensureObject(
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
    public function regenerateCode(string $userId, string $botId): object
    {
        return $this->ensureObject(
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
    public function addToGroup(string $userId, string $groupId): object
    {
        return $this->ensureObject(
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

    /**
     * Проверить объект ответа пользователя.
     */
    private function ensureObject(mixed $response): object
    {
        if (! is_object($response)) {
            throw new TeleggaApiException(
                message: 'Telegga returned an invalid user response.',
                status: 0,
                apiCode: 'invalid_response',
            );
        }

        return $response;
    }

    /**
     * Проверить страницу пользователей.
     *
     * @return object{
     *     data: Collection<int, object>,
     *     next_cursor: string|null
     * }
     */
    private function ensurePage(mixed $response): object
    {
        $response = $this->ensureObject(response: $response);
        $data = $response->data ?? null;

        if (! is_array($data)) {
            throw new TeleggaApiException(
                message: 'Telegga returned an invalid user list response.',
                status: 0,
                apiCode: 'invalid_response',
            );
        }

        foreach ($data as $user) {
            if (! is_object($user)) {
                throw new TeleggaApiException(
                    message: 'Telegga returned an invalid user list response.',
                    status: 0,
                    apiCode: 'invalid_response',
                );
            }
        }

        $nextCursor = $response->next_cursor ?? null;

        if ($nextCursor !== null && ! is_string($nextCursor)) {
            throw new TeleggaApiException(
                message: 'Telegga returned an invalid user list response.',
                status: 0,
                apiCode: 'invalid_response',
            );
        }

        $response->data = collect($data)->values();
        $response->next_cursor = $nextCursor;

        return $response;
    }
}
