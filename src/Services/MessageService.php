<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use DateTimeInterface;
use Telegga\Laravel\Dto\MessageData;
use Telegga\Laravel\Dto\MessagePageData;
use Telegga\Laravel\Dto\QueuedMessageData;
use Telegga\Laravel\Exceptions\MessageException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Http\TeleggaClient;
use Telegga\Laravel\Mappers\MessageResponseMapper;
use Telegga\Laravel\Resolvers\ConnectionContextResolver;

final class MessageService
{
    /**
     * Создать сервис сообщений.
     */
    public function __construct(
        private readonly TeleggaClient $client,
        private readonly ConnectionContextResolver $connections,
        private readonly MessageResponseMapper $mapper,
    ) {}

    /**
     * Отправить сообщение.
     *
     * @param  array<string, mixed>  $data
     */
    public function send(
        string $uuid,
        string $type,
        array $data = [],
    ): QueuedMessageData {
        if (trim($uuid) === '') {
            throw new MessageException(
                message: 'Connection UUID cannot be empty.',
                connectionUuid: $uuid,
            );
        }

        if (trim($type) === '') {
            throw new MessageException(
                message: 'Message type cannot be empty.',
                connectionUuid: $uuid,
            );
        }

        $context = $this->connections->resolve(uuid: $uuid);
        unset(
            $data['external_id'],
            $data['user_id'],
            $data['bot_id'],
            $data['group_id'],
            $data['type'],
        );
        $data['external_id'] = $context->connection->uuid;
        $data['bot_id'] = $context->link->bot_id;
        $data['type'] = trim($type);

        try {
            $response = $this->mapper->fromSend(
                response: $this->client->post(
                    uri: 'messages',
                    data: $data,
                )->object(),
            );
        } catch (TeleggaApiException $exception) {
            throw new MessageException(
                message: $exception->getMessage(),
                connectionUuid: $uuid,
                previous: $exception,
            );
        }

        return $response;
    }

    /**
     * Получить сообщение по идентификатору.
     */
    public function get(string $messageId): MessageData
    {
        if (trim($messageId) === '') {
            throw new MessageException(
                message: 'Message identifier cannot be empty.',
                messageId: $messageId,
            );
        }

        try {
            return $this->mapper->fromGet(
                response: $this->client->get(
                    uri: 'messages/'.rawurlencode($messageId),
                )->object(),
            );
        } catch (TeleggaApiException $exception) {
            throw new MessageException(
                message: $exception->getMessage(),
                messageId: $messageId,
                previous: $exception,
            );
        }
    }

    /**
     * Получить историю сообщений пользователя.
     */
    public function getHistory(
        string $uuid,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?string $status = null,
        ?string $cursor = null,
    ): MessagePageData {
        if (trim($uuid) === '') {
            throw new MessageException(
                message: 'Connection UUID cannot be empty.',
                connectionUuid: $uuid,
            );
        }

        if ($from > $to) {
            throw new MessageException(
                message: 'Message history date range is invalid.',
                connectionUuid: $uuid,
            );
        }

        $context = $this->connections->resolveUser(uuid: $uuid);
        $userId = $context->user->user_id;

        $query = [
            'user_id' => $userId,
            'from' => $from->format(DATE_RFC3339),
            'to' => $to->format(DATE_RFC3339),
        ];

        if ($status !== null && trim($status) !== '') {
            $query['status'] = trim($status);
        }

        if ($cursor !== null && trim($cursor) !== '') {
            $query['cursor'] = trim($cursor);
        }

        try {
            return $this->mapper->fromList(
                response: $this->client->get(
                    uri: 'messages',
                    query: $query,
                )->object(),
            );
        } catch (TeleggaApiException $exception) {
            throw new MessageException(
                message: $exception->getMessage(),
                connectionUuid: $uuid,
                previous: $exception,
            );
        }
    }
}
