<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Telegga\Laravel\Dto\BroadcastCancellationData;
use Telegga\Laravel\Dto\BroadcastCreatedData;
use Telegga\Laravel\Dto\BroadcastData;
use Telegga\Laravel\Exceptions\BroadcastException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Http\TeleggaClient;
use Telegga\Laravel\Mappers\BroadcastResponseMapper;
use Telegga\Laravel\Resolvers\ConnectionContextResolver;

final class BroadcastService
{
    /**
     * Создать сервис рассылок.
     */
    public function __construct(
        private readonly TeleggaClient $client,
        private readonly ConnectionContextResolver $contexts,
        private readonly BroadcastResponseMapper $mapper,
    ) {}

    /**
     * Запустить рассылку.
     *
     * @param  array<string, mixed>  $data
     */
    public function start(
        string $uuid,
        string $type,
        array $data = [],
        ?string $groupId = null,
    ): BroadcastCreatedData {
        if (trim($uuid) === '') {
            throw new BroadcastException(
                message: 'Connection UUID cannot be empty.',
                connectionUuid: $uuid,
            );
        }

        if (trim($type) === '') {
            throw new BroadcastException(
                message: 'Broadcast type cannot be empty.',
                connectionUuid: $uuid,
            );
        }

        if ($groupId !== null && trim($groupId) === '') {
            throw new BroadcastException(
                message: 'Group identifier cannot be empty.',
                connectionUuid: $uuid,
            );
        }

        $context = $this->contexts->resolveBot(uuid: $uuid);
        unset(
            $data['external_id'],
            $data['user_id'],
            $data['bot_id'],
            $data['group_id'],
            $data['type'],
        );
        $data['bot_id'] = $context->link->bot_id;

        if ($groupId !== null) {
            $data['group_id'] = trim($groupId);
        }

        $data['type'] = trim($type);

        try {
            return $this->mapper->fromStart(
                response: $this->client->post(
                    uri: 'broadcasts',
                    data: $data,
                )->object(),
            );
        } catch (TeleggaApiException $exception) {
            throw new BroadcastException(
                message: $exception->getMessage(),
                connectionUuid: $uuid,
                previous: $exception,
            );
        }
    }

    /**
     * Получить прогресс рассылки.
     */
    public function get(string $broadcastId): BroadcastData
    {
        $this->validateBroadcastId(broadcastId: $broadcastId);

        try {
            return $this->mapper->fromGet(
                response: $this->client->get(
                    uri: 'broadcasts/'.rawurlencode($broadcastId),
                )->object(),
            );
        } catch (TeleggaApiException $exception) {
            throw new BroadcastException(
                message: $exception->getMessage(),
                broadcastId: $broadcastId,
                previous: $exception,
            );
        }
    }

    /**
     * Отменить рассылку.
     */
    public function cancel(string $broadcastId): BroadcastCancellationData
    {
        $this->validateBroadcastId(broadcastId: $broadcastId);

        try {
            return $this->mapper->fromCancel(
                response: $this->client->post(
                    uri: 'broadcasts/'.rawurlencode($broadcastId).'/cancel',
                )->object(),
            );
        } catch (TeleggaApiException $exception) {
            throw new BroadcastException(
                message: $exception->getMessage(),
                broadcastId: $broadcastId,
                previous: $exception,
            );
        }
    }

    /**
     * Проверить идентификатор рассылки.
     */
    private function validateBroadcastId(string $broadcastId): void
    {
        if (trim($broadcastId) === '') {
            throw new BroadcastException(
                message: 'Broadcast identifier cannot be empty.',
                broadcastId: $broadcastId,
            );
        }
    }
}
