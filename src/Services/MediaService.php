<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use InvalidArgumentException;
use Telegga\Laravel\Exceptions\MediaException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Http\TeleggaClient;

final class MediaService
{
    /**
     * Создать сервис медиафайлов.
     */
    public function __construct(
        private readonly TeleggaClient $client,
    ) {}

    /**
     * Загрузить медиафайл.
     */
    public function upload(string $path): object
    {
        if (trim($path) === '') {
            throw new MediaException(
                message: 'Media file path cannot be empty.',
                filePath: $path,
            );
        }

        try {
            return $this->ensureObject(
                response: $this->client->upload(
                    uri: 'media',
                    path: $path,
                )->object(),
            );
        } catch (InvalidArgumentException|TeleggaApiException $exception) {
            throw new MediaException(
                message: $exception->getMessage(),
                filePath: $path,
                previous: $exception,
            );
        }
    }

    /**
     * Получить метаданные медиафайла.
     */
    public function get(string $mediaId): object
    {
        if (trim($mediaId) === '') {
            throw new MediaException(
                message: 'Media identifier cannot be empty.',
                mediaId: $mediaId,
            );
        }

        try {
            return $this->ensureObject(
                response: $this->client->get(
                    uri: 'media/'.rawurlencode($mediaId),
                )->object(),
            );
        } catch (TeleggaApiException $exception) {
            throw new MediaException(
                message: $exception->getMessage(),
                mediaId: $mediaId,
                previous: $exception,
            );
        }
    }

    /**
     * Проверить объект ответа медиафайла.
     */
    private function ensureObject(mixed $response): object
    {
        if (! is_object($response)) {
            throw new TeleggaApiException(
                message: 'Telegga returned an invalid media response.',
                status: 0,
                apiCode: 'invalid_response',
            );
        }

        return $response;
    }
}
