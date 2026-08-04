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
        $botName = $this->getBotName(connection: $context->connection);
        $link = $this->findBotLink(
            user: $context->user,
            botName: $botName,
            activeOnly: true,
        );

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
     * Получить локальное подключение, пользователя Telegga и привязку к боту.
     *
     * @return object{
     *     connection: TelegramConnectedUser,
     *     user: object,
     *     link: object
     * }
     */
    public function resolveBot(string $uuid): object
    {
        $context = $this->resolveUser(uuid: $uuid);
        $botName = $this->getBotName(connection: $context->connection);
        $link = $this->findBotLink(
            user: $context->user,
            botName: $botName,
            activeOnly: false,
        );

        if ($link === null) {
            throw new ConnectionException(
                message: 'Telegga connection has no bot link.',
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
     * Найти локальное подключение по UUID.
     */
    private function findConnection(string $uuid): TelegramConnectedUser
    {
        try {
            $connection = TelegramConnectedUser::query()
                ->with('telegramBot')
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
     * Найти доступную привязку пользователя к боту.
     */
    private function findBotLink(object $user, string $botName, bool $activeOnly): ?object
    {
        if (! is_array($user->links ?? null)) {
            return null;
        }

        $fallback = null;

        foreach ($user->links as $link) {
            if (
                is_object($link)
                && $this->linkMatchesBot(link: $link, botName: $botName)
                && is_string($link->bot_id ?? null)
                && $link->bot_id !== ''
            ) {
                if (($link->status ?? null) === 'active') {
                    return $link;
                }

                if (! $activeOnly && $fallback === null) {
                    $fallback = $link;
                }
            }
        }

        return $fallback;
    }

    /**
     * Получить имя выбранного Telegram-бота.
     */
    private function getBotName(TelegramConnectedUser $connection): string
    {
        $botName = $connection->telegramBot?->bot_name;

        if (! is_string($botName) || trim($botName) === '') {
            throw new ConnectionException(
                message: 'Telegga connection has no selected Telegram bot.',
                connectionUuid: $connection->uuid,
            );
        }

        return $botName;
    }

    /**
     * Проверить принадлежность привязки выбранному Telegram-боту.
     */
    private function linkMatchesBot(object $link, string $botName): bool
    {
        return is_string($link->bot_username ?? null)
            && $link->bot_username === $botName;
    }
}
