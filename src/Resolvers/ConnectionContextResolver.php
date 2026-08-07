<?php

declare(strict_types=1);

namespace Telegga\Laravel\Resolvers;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Telegga\Laravel\Dto\UserData;
use Telegga\Laravel\Dto\UserLinkData;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\TelegramConnectedUser;
use Telegga\Laravel\Services\UserService;

final class ConnectionContextResolver
{
    /**
     * Create the connection context resolver.
     */
    public function __construct(
        private readonly UserService $users,
    ) {}

    /**
     * Get a local connection, Telegga user, and active link.
     *
     * @return object{
     *     connection: TelegramConnectedUser,
     *     user: UserData,
     *     link: UserLinkData
     * }
     */
    public function resolve(string $uuid): object
    {
        $context = $this->resolveUser(uuid: $uuid, withBot: true);
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
     * Get a local connection and Telegga user.
     *
     * @return object{
     *     connection: TelegramConnectedUser,
     *     user: UserData
     * }
     */
    public function resolveUser(string $uuid, bool $withBot = false): object
    {
        $connection = $this->resolveConnection(uuid: $uuid, withBot: $withBot);

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
     * Get a created local connection without querying Telegga.
     */
    public function resolveConnection(string $uuid, bool $withBot = false): TelegramConnectedUser
    {
        $connection = $this->findConnection(uuid: $uuid, withBot: $withBot);

        if (! $connection->is_created) {
            throw new ConnectionException(
                message: 'Telegga connection is not created.',
                connectionUuid: $connection->uuid,
            );
        }

        return $connection;
    }

    /**
     * Get created local connections without querying Telegga.
     *
     * @param  array<int, string>  $uuids
     * @return Collection<int, TelegramConnectedUser>
     */
    public function resolveConnections(array $uuids): Collection
    {
        try {
            $connections = TelegramConnectedUser::query()
                ->whereIn('uuid', $uuids)
                ->get()
                ->keyBy('uuid');
        } catch (QueryException $exception) {
            throw new ConnectionException(
                message: 'Local Telegga connections could not be loaded.',
                previous: $exception,
            );
        }

        return collect($uuids)->map(function (string $uuid) use ($connections): TelegramConnectedUser {
            $connection = $connections->get($uuid);

            if (! $connection instanceof TelegramConnectedUser) {
                throw new ConnectionException(
                    message: 'Telegga connection was not found.',
                    connectionUuid: $uuid,
                );
            }

            if (! $connection->is_created) {
                throw new ConnectionException(
                    message: 'Telegga connection is not created.',
                    connectionUuid: $uuid,
                );
            }

            return $connection;
        });
    }

    /**
     * Get a local connection, Telegga user, and bot link.
     *
     * @return object{
     *     connection: TelegramConnectedUser,
     *     user: UserData,
     *     link: UserLinkData
     * }
     */
    public function resolveBot(string $uuid): object
    {
        $context = $this->resolveUser(uuid: $uuid, withBot: true);
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
     * Find a local connection by UUID.
     */
    private function findConnection(string $uuid, bool $withBot): TelegramConnectedUser
    {
        try {
            $query = TelegramConnectedUser::query();

            if ($withBot) {
                $query->with('telegramBot');
            }

            $connection = $query
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
     * Find an available user-to-bot link.
     */
    private function findBotLink(UserData $user, string $botName, bool $activeOnly): ?UserLinkData
    {
        $fallback = null;

        foreach ($user->links as $link) {
            if (
                $this->linkMatchesBot(link: $link, botName: $botName)
                && $link->bot_id !== ''
            ) {
                if ($link->status === 'active') {
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
     * Get the selected Telegram bot name.
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
     * Determine whether a link belongs to the selected Telegram bot.
     */
    private function linkMatchesBot(UserLinkData $link, string $botName): bool
    {
        return $link->bot_username !== null
            && str()->lower($link->bot_username) === str()->lower($botName);
    }
}
