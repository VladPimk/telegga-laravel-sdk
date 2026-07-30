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
     * Получить пользователя Telegga по внешнему идентификатору.
     */
    public function findByExternalId(string $externalId): object
    {
        $response = $this->client->get(
            uri: 'users',
            query: ['external_id' => $externalId],
        )->object();

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
