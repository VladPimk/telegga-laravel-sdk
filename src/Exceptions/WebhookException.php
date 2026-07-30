<?php

declare(strict_types=1);

namespace Telegga\Laravel\Exceptions;

use Throwable;

final class WebhookException extends TeleggaException
{
    /**
     * Создать исключение webhook.
     */
    public function __construct(
        string $message,
        public readonly ?string $externalId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(message: $message, previous: $previous);
    }
}
