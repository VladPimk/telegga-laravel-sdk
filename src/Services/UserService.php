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
