<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Database\QueryException;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\TelegramConnectedUser;
use Throwable;

final class ConnectionService
{
    /**
     * Создать сервис подключений.
     */
    public function __construct(
        private readonly BotService $bots,
        private readonly UserService $users,
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
     * Отправить локальное подключение в Telegga.
     */
    private function send(TelegramConnectedUser $connection): object
    {
        try {
            $bot = $this->bots->getAll()->first();

            if (
                ! is_object($bot)
                || ! is_string($bot->bot_id ?? null)
                || $bot->bot_id === ''
            ) {
                throw new ConnectionException(
                    message: 'No Telegga bots are available.',
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
}
