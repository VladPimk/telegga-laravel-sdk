<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Telegga\Laravel\Exceptions\MessageException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Http\TeleggaClient;
use Telegga\Laravel\Resolvers\ConnectionContextResolver;

final class MessageService
{
    /**
     * Создать сервис сообщений.
     */
    public function __construct(
        private readonly TeleggaClient $client,
        private readonly ConnectionContextResolver $connections,
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
    ): object {
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
        unset($data['user_id']);
        $data['external_id'] = $context->connection->uuid;
        $data['bot_id'] = $context->link->bot_id;
        $data['type'] = trim($type);

        try {
            $response = $this->ensureObject(
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
    public function get(string $messageId): object
    {
        if (trim($messageId) === '') {
            throw new MessageException(
                message: 'Message identifier cannot be empty.',
                messageId: $messageId,
            );
        }

        try {
            return $this->ensureObject(
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
        ?string $status = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        ?string $cursor = null,
    ): object {
        if (trim($uuid) === '') {
            throw new MessageException(
                message: 'Connection UUID cannot be empty.',
                connectionUuid: $uuid,
            );
        }

        if ($from !== null && $to !== null && $from > $to) {
            throw new MessageException(
                message: 'Message history date range is invalid.',
                connectionUuid: $uuid,
            );
        }

        $context = $this->connections->resolveUser(uuid: $uuid);
        $userId = $context->user->user_id ?? null;

        if (! is_string($userId) || trim($userId) === '') {
            throw new MessageException(
                message: 'Telegga user response does not contain user_id.',
                connectionUuid: $uuid,
            );
        }

        $query = [
            'user_id' => $userId,
        ];

        if ($status !== null && trim($status) !== '') {
            $query['status'] = trim($status);
        }

        if ($from !== null) {
            $query['from'] = $from->format(DATE_RFC3339);
        }

        if ($to !== null) {
            $query['to'] = $to->format(DATE_RFC3339);
        }

        if ($cursor !== null && trim($cursor) !== '') {
            $query['cursor'] = trim($cursor);
        }

        try {
            return $this->ensurePage(
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

    /**
     * Проверить объект ответа сообщения.
     */
    private function ensureObject(mixed $response): object
    {
        if (! is_object($response)) {
            throw new TeleggaApiException(
                message: 'Telegga returned an invalid message response.',
                status: 0,
                apiCode: 'invalid_response',
            );
        }

        return $response;
    }

    /**
     * Проверить страницу истории сообщений.
     *
     * @return object{
     *     data: Collection<int, object>,
     *     next_cursor: string|null
     * }
     */
    private function ensurePage(mixed $response): object
    {
        $response = $this->ensureObject(response: $response);
        $data = $response->data ?? null;

        if (! is_array($data)) {
            throw new TeleggaApiException(
                message: 'Telegga returned an invalid message history response.',
                status: 0,
                apiCode: 'invalid_response',
            );
        }

        foreach ($data as $message) {
            if (! is_object($message)) {
                throw new TeleggaApiException(
                    message: 'Telegga returned an invalid message history response.',
                    status: 0,
                    apiCode: 'invalid_response',
                );
            }
        }

        $nextCursor = $response->next_cursor ?? null;

        if ($nextCursor !== null && ! is_string($nextCursor)) {
            throw new TeleggaApiException(
                message: 'Telegga returned an invalid message history response.',
                status: 0,
                apiCode: 'invalid_response',
            );
        }

        $response->data = collect($data)->values();
        $response->next_cursor = $nextCursor;

        return $response;
    }
}
