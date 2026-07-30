<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Support\Str;
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
     * Отправить текстовое сообщение.
     *
     * @param  array<int, array<int, array{text: string, url: string}>>  $buttons
     */
    public function sendText(
        string $uuid,
        string $text,
        ?string $parseMode = null,
        array $buttons = [],
        bool $disableWebPagePreview = false,
        bool $disableNotification = false,
    ): object {
        $this->validateTextMessage(
            uuid: $uuid,
            text: $text,
            parseMode: $parseMode,
            buttons: $buttons,
        );

        $context = $this->connections->resolve(uuid: $uuid);
        $data = [
            'external_id' => $context->connection->uuid,
            'bot_id' => $context->link->bot_id,
            'type' => 'text',
            'text' => $text,
        ];

        if ($parseMode !== null) {
            $data['parse_mode'] = $parseMode;
        }

        if ($buttons !== []) {
            $data['buttons'] = $buttons;
        }

        if ($disableWebPagePreview) {
            $data['disable_web_page_preview'] = true;
        }

        if ($disableNotification) {
            $data['disable_notification'] = true;
        }

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
     * Проверить данные текстового сообщения.
     *
     * @param  array<int, array<int, array{text: string, url: string}>>  $buttons
     */
    private function validateTextMessage(
        string $uuid,
        string $text,
        ?string $parseMode,
        array $buttons,
    ): void {
        if (trim($text) === '') {
            throw new MessageException(
                message: 'Message text cannot be empty.',
                connectionUuid: $uuid,
            );
        }

        if (Str::length(value: $text) > 4096) {
            throw new MessageException(
                message: 'Message text cannot exceed 4096 characters.',
                connectionUuid: $uuid,
            );
        }

        if ($parseMode !== null && ! in_array($parseMode, ['HTML', 'MarkdownV2'], true)) {
            throw new MessageException(
                message: 'Message parse mode is invalid.',
                connectionUuid: $uuid,
            );
        }

        if (count($buttons) > 10) {
            throw new MessageException(
                message: 'Message buttons cannot exceed 10 rows.',
                connectionUuid: $uuid,
            );
        }

        foreach ($buttons as $row) {
            if (! is_array($row) || $row === [] || count($row) > 8) {
                throw new MessageException(
                    message: 'Message button row must contain between 1 and 8 buttons.',
                    connectionUuid: $uuid,
                );
            }

            foreach ($row as $button) {
                if (
                    ! is_array($button)
                    || ! is_string($button['text'] ?? null)
                    || trim($button['text']) === ''
                    || ! is_string($button['url'] ?? null)
                    || trim($button['url']) === ''
                ) {
                    throw new MessageException(
                        message: 'Message button must contain non-empty text and url.',
                        connectionUuid: $uuid,
                    );
                }
            }
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
}
