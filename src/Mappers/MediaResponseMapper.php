<?php

declare(strict_types=1);

namespace Telegga\Laravel\Mappers;

use Telegga\Laravel\Dto\MediaData;

final class MediaResponseMapper
{
    /**
     * Create the media response mapper.
     */
    public function __construct(
        private readonly ResponseReader $reader,
    ) {}

    /**
     * Map a media upload response.
     */
    public function fromUpload(mixed $response): MediaData
    {
        return $this->mapMedia(response: $response, context: 'media upload response');
    }

    /**
     * Map a media response.
     */
    public function fromGet(mixed $response): MediaData
    {
        return $this->mapMedia(response: $response, context: 'media response');
    }

    /**
     * Map media file data.
     */
    private function mapMedia(mixed $response, string $context): MediaData
    {
        $response = $this->reader->object(response: $response, context: $context);

        return new MediaData(
            media_id: $this->reader->requiredString(response: $response, field: 'media_id', context: $context),
            mime_type: $this->reader->requiredString(response: $response, field: 'mime_type', context: $context),
            size: $this->reader->requiredInteger(response: $response, field: 'size', context: $context),
            filename: $this->reader->requiredString(response: $response, field: 'filename', context: $context),
            raw: $response,
        );
    }
}
