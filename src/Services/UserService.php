<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

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
     */
    public function create(
        string $externalId,
        string $botId,
        string $displayName,
        ?string $email = null,
    ): object {
        $data = [
            'external_id' => $externalId,
            'bot_id' => $botId,
            'display_name' => $displayName,
        ];

        if ($email !== null) {
            $data['email'] = $email;
        }

        return $this->ensureObject(
            response: $this->client->post(
                uri: 'users',
                data: $data,
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
}
