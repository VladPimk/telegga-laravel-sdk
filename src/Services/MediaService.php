<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Telegga\Laravel\Dto\MediaData;
use Telegga\Laravel\Exceptions\MediaException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Http\TeleggaClient;
use Telegga\Laravel\Mappers\MediaResponseMapper;

final class MediaService
{
    private const int MAX_FILE_SIZE_BYTES = 50 * 1024 * 1024;

    /**
     * Создать сервис медиафайлов.
     */
    public function __construct(
        private readonly TeleggaClient $client,
        private readonly MediaResponseMapper $mapper,
    ) {}

    /**
     * Загрузить медиафайл.
     */
    public function upload(string $contents, string $filename): MediaData
    {
        if ($contents === '') {
            throw new MediaException(
                message: 'Media file contents cannot be empty.',
                filename: $filename,
            );
        }

        if (trim($filename) === '') {
            throw new MediaException(
                message: 'Media filename cannot be empty.',
                filename: $filename,
            );
        }

        if (strlen($contents) > self::MAX_FILE_SIZE_BYTES) {
            throw new MediaException(
                message: 'Media file exceeds the maximum size of 50 MB.',
                filename: $filename,
            );
        }

        try {
            return $this->mapper->fromUpload(
                response: $this->client->upload(
                    uri: 'media',
                    contents: $contents,
                    filename: $filename,
                )->object(),
            );
        } catch (TeleggaApiException $exception) {
            throw new MediaException(
                message: $exception->getMessage(),
                filename: $filename,
                previous: $exception,
            );
        }
    }

    /**
     * Получить метаданные медиафайла.
     */
    public function get(string $mediaId): MediaData
    {
        if (trim($mediaId) === '') {
            throw new MediaException(
                message: 'Media identifier cannot be empty.',
                mediaId: $mediaId,
            );
        }

        try {
            return $this->mapper->fromGet(
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
}
