<?php

declare(strict_types=1);

namespace Telegga\Laravel\Exceptions;

use Throwable;

final class BotException extends TeleggaException
{
    /**
     * Создать исключение Telegram-бота.
     */
    public function __construct(
        string $message,
        public readonly ?string $botName = null,
        public readonly ?string $botUuid = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(message: $message, previous: $previous);
    }
}
