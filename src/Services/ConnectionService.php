<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Database\QueryException;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
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
     */
    public function create(
        string $name,
        ?string $email = null,
        ?int $userId = null,
    ): object {
        if (trim($name) === '') {
            throw new ConnectionException(message: 'Connection name cannot be empty.');
        }

        try {
            $connection = TelegramConnectedUser::query()->create([
                'name' => $name,
                'email' => $email,
                'user_id' => $userId,
            ]);
        } catch (QueryException $exception) {
            throw new ConnectionException(
                message: 'Local Telegga connection could not be created.',
                previous: $exception,
            );
        }

        return $this->send(connection: $connection);
    }

    /**
     * Повторно отправить существующее подключение в Telegga.
     */
    public function retry(string $uuid): object
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

        if ($connection->is_created) {
            throw new ConnectionException(
                message: 'Telegga connection is already created.',
                connectionUuid: $connection->uuid,
            );
        }

        return $this->send(connection: $connection);
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
     */
    private function send(TelegramConnectedUser $connection): object
    {
        try {
            $bot = $this->bots->getAll()->first(
                fn (mixed $bot): bool => is_object($bot)
                    && ($bot->status ?? null) === 'active',
            );

            if (
                ! is_object($bot)
                || ! is_string($bot->bot_id ?? null)
                || $bot->bot_id === ''
            ) {
                throw new ConnectionException(
                    message: 'No active Telegga bots are available.',
                    connectionUuid: $connection->uuid,
                );
            }

            $response = $this->users->create(
                externalId: $connection->uuid,
                botId: $bot->bot_id,
                displayName: $connection->name,
                email: $connection->email,
            );
        } catch (TeleggaApiException $exception) {
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

        if (property_exists($response, 'email')) {
            $email = $response->email;
        } else {
            $email = $data['email'] ?? null;
        }

        if ($email === null || is_string($email)) {
            if (property_exists($response, 'email') || array_key_exists('email', $data)) {
                $attributes['email'] = $email === '' ? null : $email;
            }
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
