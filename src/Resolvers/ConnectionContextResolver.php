<?php

declare(strict_types=1);

namespace Telegga\Laravel\Resolvers;

use Illuminate\Database\QueryException;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\TelegramConnectedUser;
use Telegga\Laravel\Services\UserService;

final class ConnectionContextResolver
{
    /**
     * Создать резолвер контекста подключения.
     */
    public function __construct(
        private readonly UserService $users,
    ) {}

    /**
     * Получить локальное подключение, пользователя Telegga и активную привязку.
     *
     * @return object{
     *     connection: TelegramConnectedUser,
     *     user: object,
     *     link: object
     * }
     */
    public function resolve(string $uuid): object
    {
        $context = $this->resolveUser(uuid: $uuid);
        $link = $this->findActiveLink(user: $context->user);

        if ($link === null) {
            throw new ConnectionException(
                message: 'Telegga connection has no active bot link.',
                connectionUuid: $context->connection->uuid,
            );
        }

        return (object) [
            'connection' => $context->connection,
            'user' => $context->user,
            'link' => $link,
        ];
    }

    /**
     * Получить локальное подключение и пользователя Telegga.
     *
     * @return object{
     *     connection: TelegramConnectedUser,
     *     user: object
     * }
     */
    public function resolveUser(string $uuid): object
    {
        $connection = $this->findConnection(uuid: $uuid);

        if (! $connection->is_created) {
            throw new ConnectionException(
                message: 'Telegga connection is not created.',
                connectionUuid: $connection->uuid,
            );
        }

        try {
            $user = $this->users->findByExternalId(externalId: $connection->uuid);
        } catch (TeleggaApiException $exception) {
            throw new ConnectionException(
                message: $exception->getMessage(),
                connectionUuid: $connection->uuid,
                previous: $exception,
            );
        }

        return (object) [
            'connection' => $connection,
            'user' => $user,
        ];
    }

    /**
     * Найти локальное подключение по UUID.
     */
    private function findConnection(string $uuid): TelegramConnectedUser
    {
        try {
            $connection = TelegramConnectedUser::query()
                ->where('uuid', $uuid)
                ->first();
        } catch (QueryException $exception) {
            throw new ConnectionException(
                message: 'Local Telegga connection could not be loaded.',
                connectionUuid: $uuid,
                previous: $exception,
            );
        }

        if ($connection === null) {
            throw new ConnectionException(
                message: 'Telegga connection was not found.',
                connectionUuid: $uuid,
            );
        }

        return $connection;
    }

    /**
     * Найти активную привязку пользователя к боту.
     */
    private function findActiveLink(object $user): ?object
    {
        if (! is_array($user->links ?? null)) {
            return null;
        }

        foreach ($user->links as $link) {
            if (
                is_object($link)
                && ($link->status ?? null) === 'active'
                && is_string($link->bot_id ?? null)
                && $link->bot_id !== ''
            ) {
                return $link;
            }
        }

        return null;
    }
}
