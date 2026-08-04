<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Database\QueryException;
use Telegga\Laravel\Exceptions\BotException;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TelegramConnectedUser;
use Telegga\Laravel\Resolvers\ConnectionContextResolver;
use Throwable;

final class ConnectionService
{
    /**
     * Создать сервис подключений.
     */
    public function __construct(
        private readonly BotService $bots,
        private readonly UserService $users,
        private readonly ConnectionContextResolver $contexts,
    ) {}

    /**
     * Создать локальное подключение и отправить его в Telegga.
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
    ): object {
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

        return $this->send(
            connection: $connection,
            telegramBot: $telegramBot,
            meta: $meta,
            groupId: $groupId,
        );
    }

    /**
     * Повторно отправить существующее подключение в Telegga.
     *
     * @param  array<string, mixed>  $meta
     */
    public function retry(
        string $uuid,
        array $meta = [],
        ?string $groupId = null,
    ): object {
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

        if ($connection->is_created) {
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

        return $this->send(
            connection: $connection,
            telegramBot: $telegramBot,
            meta: $meta,
            groupId: $groupId,
        );
    }

    /**
     * Получить подключённого пользователя Telegga.
     */
    public function get(string $uuid): object
    {
        $context = $this->contexts->resolveUser(uuid: $uuid);
        $userId = $this->getUserId(user: $context->user, uuid: $uuid);

        try {
            return $this->users->get(userId: $userId);
        } catch (TeleggaApiException $exception) {
            throw new ConnectionException(
                message: $exception->getMessage(),
                connectionUuid: $uuid,
                previous: $exception,
            );
        }
    }

    /**
     * Получить список подключений Telegga.
     */
    public function getAll(
        ?string $email = null,
        ?string $telegramBotUuid = null,
        ?string $status = null,
        ?string $cursor = null,
    ): object {
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
     * Обновить подключённого пользователя Telegga.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $uuid, array $data): object
    {
        if ($data === []) {
            throw new ConnectionException(
                message: 'Connection update data cannot be empty.',
                connectionUuid: $uuid,
            );
        }

        $context = $this->contexts->resolveUser(uuid: $uuid);
        $userId = $this->getUserId(user: $context->user, uuid: $uuid);

        try {
            $response = $this->users->update(
                userId: $userId,
                data: $data,
            );
        } catch (TeleggaApiException $exception) {
            throw new ConnectionException(
                message: $exception->getMessage(),
                connectionUuid: $uuid,
                previous: $exception,
            );
        }

        $this->synchronize(
            connection: $context->connection,
            response: $response,
            data: $data,
        );

        return $response;
    }

    /**
     * Удалить подключённого пользователя Telegga и локальную запись.
     */
    public function delete(string $uuid): void
    {
        $context = $this->contexts->resolveUser(uuid: $uuid);
        $userId = $this->getUserId(user: $context->user, uuid: $uuid);

        try {
            $this->users->delete(userId: $userId);
        } catch (TeleggaApiException $exception) {
            throw new ConnectionException(
                message: $exception->getMessage(),
                connectionUuid: $uuid,
                previous: $exception,
            );
        }

        try {
            $deleted = $context->connection->delete();
        } catch (Throwable $exception) {
            throw new ConnectionException(
                message: 'Local Telegga connection could not be deleted.',
                connectionUuid: $uuid,
                previous: $exception,
            );
        }

        if ($deleted !== true) {
            throw new ConnectionException(
                message: 'Local Telegga connection could not be deleted.',
                connectionUuid: $uuid,
            );
        }
    }

    /**
     * Выпустить новый код подключения пользователя.
     */
    public function regenerateCode(string $uuid): object
    {
        $context = $this->contexts->resolveBot(uuid: $uuid);
        $userId = $this->getUserId(user: $context->user, uuid: $uuid);

        try {
            return $this->users->regenerateCode(
                userId: $userId,
                botId: $context->link->bot_id,
            );
        } catch (TeleggaApiException $exception) {
            throw new ConnectionException(
                message: $exception->getMessage(),
                connectionUuid: $uuid,
                previous: $exception,
            );
        }
    }

    /**
     * Отвязать подключённого пользователя от бота.
     */
    public function unlink(string $uuid): void
    {
        $context = $this->contexts->resolveBot(uuid: $uuid);
        $userId = $this->getUserId(user: $context->user, uuid: $uuid);

        try {
            $this->users->unlink(
                userId: $userId,
                botId: $context->link->bot_id,
            );
        } catch (TeleggaApiException $exception) {
            throw new ConnectionException(
                message: $exception->getMessage(),
                connectionUuid: $uuid,
                previous: $exception,
            );
        }

        try {
            $updated = $context->connection->update([
                'is_connected' => false,
            ]);
        } catch (Throwable $exception) {
            throw new ConnectionException(
                message: 'Local Telegga connection state could not be updated.',
                connectionUuid: $uuid,
                previous: $exception,
            );
        }

        if (! $updated) {
            throw new ConnectionException(
                message: 'Local Telegga connection state could not be updated.',
                connectionUuid: $uuid,
            );
        }
    }

    /**
     * Отправить локальное подключение в Telegga.
     *
     * @param  array<string, mixed>  $meta
     */
    private function send(
        TelegramConnectedUser $connection,
        AvailableTelegramBot $telegramBot,
        array $meta = [],
        ?string $groupId = null,
    ): object {
        try {
            $bot = $this->bots->findActive(botName: $telegramBot->bot_name);

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

        $attributes = [
            'is_created' => true,
        ];

        if (($response->link_status ?? null) === 'active') {
            $attributes['is_connected'] = true;
        }

        try {
            $connection->update(attributes: $attributes);
        } catch (Throwable $exception) {
            throw new ConnectionException(
                message: 'Telegga connection state could not be updated.',
                connectionUuid: $connection->uuid,
                previous: $exception,
            );
        }

        return $response;
    }

    /**
     * Нормализовать идентификатор группы.
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
     * Получить идентификатор пользователя Telegga.
     */
    private function getUserId(object $user, string $uuid): string
    {
        $userId = $user->user_id ?? null;

        if (! is_string($userId) || trim($userId) === '') {
            throw new ConnectionException(
                message: 'Telegga user response does not contain user_id.',
                connectionUuid: $uuid,
            );
        }

        return $userId;
    }

    /**
     * Синхронизировать локальные данные подключения.
     *
     * @param  array<string, mixed>  $data
     */
    private function synchronize(
        TelegramConnectedUser $connection,
        object $response,
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

        if ($attributes === []) {
            return;
        }

        try {
            $updated = $connection->update(attributes: $attributes);
        } catch (Throwable $exception) {
            throw new ConnectionException(
                message: 'Local Telegga connection data could not be updated.',
                connectionUuid: $connection->uuid,
                previous: $exception,
            );
        }

        if (! $updated) {
            throw new ConnectionException(
                message: 'Local Telegga connection data could not be updated.',
                connectionUuid: $connection->uuid,
            );
        }
    }
}
