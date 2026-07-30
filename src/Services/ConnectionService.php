<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Database\QueryException;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Http\TeleggaClient;
use Telegga\Laravel\Models\TelegramConnectedUser;
use Throwable;

final class ConnectionService
{
    /**
     * Создать сервис подключений.
     */
    public function __construct(
        private readonly TeleggaClient $client,
        private readonly BotService $bots,
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

            if (! is_object($bot) || ! isset($bot->bot_id)) {
                throw new ConnectionException(
                    message: 'No Telegga bots are available.',
                    connectionUuid: $connection->uuid,
                );
            }

            $data = [
                'external_id' => $connection->uuid,
                'bot_id' => $bot->bot_id,
                'display_name' => $connection->name,
            ];

            if ($connection->email !== null) {
                $data['email'] = $connection->email;
            }

            $response = $this->client->post(uri: 'users', data: $data)->object();
        } catch (TeleggaApiException $exception) {
            throw new ConnectionException(
                message: $exception->getMessage(),
                connectionUuid: $connection->uuid,
                previous: $exception,
            );
        }

        if (! is_object($response)) {
            throw new ConnectionException(
                message: 'Telegga returned an invalid connection response.',
                connectionUuid: $connection->uuid,
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
