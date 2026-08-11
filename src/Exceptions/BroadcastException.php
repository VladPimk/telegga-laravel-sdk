<?php

declare(strict_types=1);

namespace Telegga\Laravel\Exceptions;

use Throwable;

final class BroadcastException extends TeleggaException
{
    /**
     * Create a broadcast exception.
     */
    public function __construct(
        string $message,
        public readonly ?string $broadcastId = null,
        public readonly ?string $connectionUuid = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(message: $message, previous: $previous);
    }
}
