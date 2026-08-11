<?php

declare(strict_types=1);

namespace Telegga\Laravel\Exceptions;

use Throwable;

final class MediaException extends TeleggaException
{
    /**
     * Create a media file exception.
     */
    public function __construct(
        string $message,
        public readonly ?string $mediaId = null,
        public readonly ?string $filename = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(message: $message, previous: $previous);
    }
}
