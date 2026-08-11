<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

final readonly class MediaData extends ApiResponseData
{
    /**
     * Create media file data.
     */
    public function __construct(
        public string $media_id,
        public string $mime_type,
        public int $size,
        public string $filename,
        object $raw,
    ) {
        parent::__construct(rawResponse: $raw);
    }
}
