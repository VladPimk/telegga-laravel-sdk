<?php

declare(strict_types=1);

namespace Telegga\Laravel\Exceptions;

use Throwable;

final class ConnectionException extends TeleggaException
{
    /**
     * Create a connection exception.
     */
    public function __construct(
        string $message,
        public readonly ?string $connectionUuid = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(message: $message, previous: $previous);
    }
}
