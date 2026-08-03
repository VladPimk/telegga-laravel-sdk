<?php

declare(strict_types=1);

namespace Telegga\Laravel\Exceptions;

use Throwable;

final class GroupException extends TeleggaException
{
    /**
     * Создать исключение группы.
     */
    public function __construct(
        string $message,
        public readonly ?string $groupId = null,
        public readonly ?string $connectionUuid = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(message: $message, previous: $previous);
    }
}
