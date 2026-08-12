<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Telegga\Laravel\Dto\ConnectionData;
use Telegga\Laravel\Dto\UserData;
use Telegga\Laravel\Dto\UserPageData;
use Telegga\Laravel\Exceptions\BotException;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TelegramConnectedUser;
use Telegga\Laravel\Resolvers\ConnectionContextResolver;
use Telegga\Laravel\Support\ExceptionLogContext;
use Telegga\Laravel\TelegramLinkStatus;
use Telegga\Laravel\TelegramUserStatus;
use Throwable;

final class ConnectionService
{
    /**
     * Create the connection service.
     */
    public function __construct(
        private readonly BotService $bots,
        private readonly UserService $users,
        private readonly ConnectionContextResolver $contexts,
    ) {}

    /**
     * Create a local connection and send it to Telegga.
     *
     * @param  array<string, mixed>  $meta
     */
    public function create(
        string $name,
        string $telegramBotUuid,
        ?string $email = null,
        ?int $userId = null,
        array $meta = [],
        ?string $groupId = null,
    ): ConnectionData {
        if (trim($name) === '') {
            throw new ConnectionException(message: 'Connection name cannot be empty.');
        }

        $groupId = $this->normalizeGroupId(groupId: $groupId);

        try {
            $telegramBot = $this->bots->getAvailableByUuid(uuid: $telegramBotUuid);
        } catch (BotException $exception) {
            throw new ConnectionException(
                message: $exception->getMessage(),
                previous: $exception,
            );
        }

        try {
            $connection = TelegramConnectedUser::query()->create([
                'name' => $name,
                'email' => $email,
                'user_id' => $userId,
                'available_telegram_bot_id' => $telegramBot->getKey(),
            ]);
        } catch (QueryException $exception) {
            throw new ConnectionException(
                message: 'Local Telegga connection could not be created.',
                previous: $exception,
            );
        }

        $response = $this->createRemoteConnection(
            connection: $connection,
            telegramBot: $telegramBot,
            meta: $meta,
            groupId: $groupId,
        );

        $this->synchronizeCreation(
            connection: $connection,
            response: $response,
        );

        return $response;
    }

    /**
     * Resend an existing connection to Telegga.
     *
     * @param  array<string, mixed>  $meta
     */
    public function retry(
        string $uuid,
        array $meta = [],
        ?string $groupId = null,
    ): ConnectionData {
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

        if ($connection->status->existsInTelegga()) {
            throw new ConnectionException(
                message: 'Telegga connection is already created.',
                connectionUuid: $connection->uuid,
            );
        }

        $telegramBot = $connection->telegramBot;

        if ($telegramBot === null) {
            throw new ConnectionException(
                message: 'Telegga connection has no selected Telegram bot.',
                connectionUuid: $connection->uuid,
            );
        }

        $groupId = $this->normalizeGroupId(
            groupId: $groupId,
            connectionUuid: $connection->uuid,
        );

        $response = $this->createRemoteConnection(
            connection: $connection,
            telegramBot: $telegramBot,
            meta: $meta,
            groupId: $groupId,
        );

        try {
            $user = $this->users->findByExternalId(externalId: $connection->uuid);
        } catch (TeleggaApiException $exception) {
            throw new ConnectionException(
                message: $exception->getMessage(),
                connectionUuid: $connection->uuid,
                previous: $exception,
            );
        }

        $this->contexts->synchronizeStatuses(
            connection: $connection,
            user: $user,
            withBot: true,
        );

        return $response;
    }

    /**
     * Get a connected Telegga user.
     */
    public function get(string $uuid): UserData
    {
        $connection = $this->contexts->resolveConnection(uuid: $uuid, withBot: true);

        try {
            $response = $this->users->get(userId: $connection->uuid);
        } catch (TeleggaApiException $exception) {
            if ($exception->apiCode === 'not_found') {
                $this->contexts->synchronizeMissingUser(connection: $connection);
            }

            throw new ConnectionException(
                message: $exception->getMessage(),
                connectionUuid: $uuid,
                previous: $exception,
            );
        }

        $this->contexts->synchronizeStatuses(
            connection: $connection,
            user: $response,
            withBot: true,
        );

        return $response;
    }

    /**
     * Get a list of Telegga connections.
     */
    public function getAll(
        ?string $email = null,
        ?string $telegramBotUuid = null,
        ?string $status = null,
        ?string $cursor = null,
    ): UserPageData {
        $query = [];

        if ($email !== null) {
            $email = trim($email);

            if ($email === '') {
                throw new ConnectionException(message: 'Connection email cannot be empty.');
            }

            $query['email'] = $email;
        }

        if ($telegramBotUuid !== null) {
            try {
                $telegramBot = $this->bots->getAvailableByUuid(uuid: $telegramBotUuid);
                $bot = $this->bots->find(botName: $telegramBot->bot_name);
            } catch (BotException $exception) {
                throw new ConnectionException(
                    message: $exception->getMessage(),
                    previous: $exception,
                );
            }

            $query['bot_id'] = $bot->bot_id;
        }

        if ($status !== null && trim($status) !== '') {
            $query['status'] = trim($status);
        }

        if ($cursor !== null && trim($cursor) !== '') {
            $query['cursor'] = trim($cursor);
        }

        try {
            return $this->users->getAll(query: $query);
        } catch (TeleggaApiException $exception) {
            throw new ConnectionException(
                message: $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * Update a connected Telegga user.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $uuid, array $data): UserData
    {
        if ($data === []) {
            throw new ConnectionException(
                message: 'Connection update data cannot be empty.',
                connectionUuid: $uuid,
            );
        }

        $connection = $this->contexts->resolveConnection(uuid: $uuid);

        try {
            $response = $this->users->update(
                userId: $connection->uuid,
                data: $data,
            );
        } catch (TeleggaApiException $exception) {
            throw new ConnectionException(
                message: $exception->getMessage(),
                connectionUuid: $uuid,
                previous: $exception,
            );
        }

        $this->synchronizeUpdate(
            connection: $connection,
            response: $response,
            data: $data,
        );

        return $response;
    }

    /**
     * Delete a connected Telegga user and the local record.
     */
    public function delete(string $uuid): void
    {
        $connection = $this->contexts->resolveConnection(uuid: $uuid);

        try {
            $this->users->delete(userId: $connection->uuid);
        } catch (TeleggaApiException $exception) {
            if (! $exception->wasRetried() || $exception->apiCode !== 'not_found') {
                throw new ConnectionException(
                    message: $exception->getMessage(),
                    connectionUuid: $uuid,
                    previous: $exception,
                );
            }
        }

        try {
            $connection->delete();
        } catch (Throwable $exception) {
            $this->handleLocalDeletionFailure(
                connection: $connection,
                deletionException: $exception,
            );
        }
    }

    /**
     * Generate a new user connection code.
     */
    public function regenerateCode(string $uuid): ConnectionData
    {
        $context = $this->contexts->resolveBot(uuid: $uuid);

        try {
            $response = $this->users->regenerateCode(
                userId: $context->connection->uuid,
                botId: $context->link->bot_id,
            );
        } catch (TeleggaApiException $exception) {
            throw new ConnectionException(
                message: $exception->getMessage(),
                connectionUuid: $uuid,
                previous: $exception,
            );
        }

        $this->synchronizeLinkStatus(
            connection: $context->connection,
            linkStatus: $response->link_status,
        );

        return $response;
    }

    /**
     * Unlink a connected user from the bot.
     */
    public function unlink(string $uuid): void
    {
        $context = $this->contexts->resolveBot(uuid: $uuid);

        try {
            $this->users->unlink(
                userId: $context->connection->uuid,
                botId: $context->link->bot_id,
            );
        } catch (TeleggaApiException $exception) {
            if (! $exception->wasRetried() || $exception->apiCode !== 'user_not_linked') {
                throw new ConnectionException(
                    message: $exception->getMessage(),
                    connectionUuid: $uuid,
                    previous: $exception,
                );
            }
        }

        try {
            $context->connection->update([
                'link_status' => TelegramLinkStatus::Revoked,
            ]);
        } catch (Throwable $exception) {
            throw new ConnectionException(
                message: 'Local Telegga connection state could not be updated.',
                connectionUuid: $uuid,
                previous: $exception,
            );
        }
    }

    /**
     * Send a local connection to Telegga.
     *
     * @param  array<string, mixed>  $meta
     */
    private function createRemoteConnection(
        TelegramConnectedUser $connection,
        AvailableTelegramBot $telegramBot,
        array $meta = [],
        ?string $groupId = null,
    ): ConnectionData {
        try {
            $bot = $this->bots->find(
                botName: $telegramBot->bot_name,
                status: 'active',
            );

            $response = $this->users->create(
                externalId: $connection->uuid,
                botId: $bot->bot_id,
                displayName: $connection->name,
                email: $connection->email,
                meta: $meta,
                groupId: $groupId,
            );
        } catch (BotException|TeleggaApiException $exception) {
            throw new ConnectionException(
                message: $exception->getMessage(),
                connectionUuid: $connection->uuid,
                previous: $exception,
            );
        }

        return $response;
    }

    /**
     * Normalize a group identifier.
     */
    private function normalizeGroupId(
        ?string $groupId,
        ?string $connectionUuid = null,
    ): ?string {
        if ($groupId === null) {
            return null;
        }

        $groupId = trim($groupId);

        if ($groupId === '') {
            throw new ConnectionException(
                message: 'Group identifier cannot be empty.',
                connectionUuid: $connectionUuid,
            );
        }

        return $groupId;
    }

    /**
     * Handle a local deletion failure after deleting the Telegga user.
     */
    private function handleLocalDeletionFailure(
        TelegramConnectedUser $connection,
        Throwable $deletionException,
    ): never {
        $stateException = null;
        $stateSynchronized = false;

        try {
            TelegramConnectedUser::query()
                ->whereKey($connection->getKey())
                ->update([
                    'status' => TelegramUserStatus::NotCreated,
                    'link_status' => null,
                ]);

            $storedConnection = TelegramConnectedUser::query()
                ->whereKey($connection->getKey())
                ->first();
            $stateSynchronized = $storedConnection === null
                || (
                    $storedConnection->status === TelegramUserStatus::NotCreated
                    && $storedConnection->link_status === null
                );
        } catch (Throwable $exception) {
            $stateException = $exception;
        }

        Log::critical(
            message: 'Telegga connection orphaned: remote user deleted, local record kept.',
            context: [
                'connection_uuid' => $connection->uuid,
                'state_synchronized' => $stateSynchronized,
                'deletion_exception' => ExceptionLogContext::from(exception: $deletionException),
                'state_exception' => $stateException === null
                    ? null
                    : ExceptionLogContext::from(exception: $stateException),
            ],
        );

        report($deletionException);

        if ($stateException !== null) {
            report($stateException);
        }

        throw new ConnectionException(
            message: 'Local Telegga connection could not be deleted after remote deletion.',
            connectionUuid: $connection->uuid,
            previous: $deletionException,
        );
    }

    /**
     * Synchronize local connection data.
     *
     * @param  array<string, mixed>  $data
     */
    private function synchronizeUpdate(
        TelegramConnectedUser $connection,
        UserData $response,
        array $data,
    ): void {
        $attributes = [];
        $displayName = $response->display_name ?? $data['display_name'] ?? null;

        if (is_string($displayName)) {
            $attributes['name'] = $displayName;
        }

        if (array_key_exists('email', $data)) {
            $email = $response->email ?? $data['email'];

            if ($email !== null && ! is_string($email)) {
                throw new TeleggaApiException(
                    message: 'Telegga returned an invalid user email.',
                    status: 0,
                    apiCode: 'invalid_response',
                );
            }

            $attributes['email'] = ($email === null || $email === '') ? null : $email;
        }

        $status = $response->status ?? $data['status'] ?? null;
        $userStatus = is_string($status)
            ? TelegramUserStatus::tryFrom($status)
            : null;

        if ($userStatus === null || $userStatus === TelegramUserStatus::NotCreated) {
            throw new ConnectionException(
                message: 'Telegga returned an invalid user status.',
                connectionUuid: $connection->uuid,
            );
        }

        $attributes['status'] = $userStatus;

        try {
            $connection->update(attributes: $attributes);
        } catch (Throwable $exception) {
            throw new ConnectionException(
                message: 'Local Telegga connection data could not be updated.',
                connectionUuid: $connection->uuid,
                previous: $exception,
            );
        }
    }

    /**
     * Synchronize a newly created Telegga user and its bot link.
     */
    private function synchronizeCreation(
        TelegramConnectedUser $connection,
        ConnectionData $response,
    ): void {
        try {
            $connection->update([
                'status' => TelegramUserStatus::Active,
                'link_status' => $this->parseLinkStatus(
                    status: $response->link_status,
                    connectionUuid: $connection->uuid,
                ),
            ]);
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ConnectionException(
                message: 'Telegga connection state could not be updated.',
                connectionUuid: $connection->uuid,
                previous: $exception,
            );
        }
    }

    /**
     * Synchronize a Telegga bot-link status.
     */
    private function synchronizeLinkStatus(
        TelegramConnectedUser $connection,
        string $linkStatus,
    ): void {
        try {
            $connection->update([
                'link_status' => $this->parseLinkStatus(
                    status: $linkStatus,
                    connectionUuid: $connection->uuid,
                ),
            ]);
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ConnectionException(
                message: 'Local Telegga connection state could not be updated.',
                connectionUuid: $connection->uuid,
                previous: $exception,
            );
        }
    }

    /**
     * Convert a Telegga bot-link status to the local enum.
     */
    private function parseLinkStatus(
        string $status,
        string $connectionUuid,
    ): TelegramLinkStatus {
        $linkStatus = TelegramLinkStatus::tryFrom($status);

        if ($linkStatus === null) {
            throw new ConnectionException(
                message: 'Telegga returned an invalid bot link status.',
                connectionUuid: $connectionUuid,
            );
        }

        return $linkStatus;
    }
}
