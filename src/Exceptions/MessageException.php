<?php

declare(strict_types=1);

namespace Telegga\Laravel\Exceptions;

use Throwable;

final class MessageException extends TeleggaException
{
    /**
     * Создать исключение сообщения.
     */
    public function __construct(
        string $message,
        public readonly ?string $connectionUuid = null,
        public readonly ?string $messageId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(message: $message, previous: $previous);
    }
}
