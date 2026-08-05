<?php

declare(strict_types=1);

namespace Telegga\Laravel\Webhooks;

enum WebhookProcessingStatus: string
{
    case Connected = 'connected';
    case AlreadyConnected = 'already_connected';
    case ConnectionNotFound = 'connection_not_found';
    case ConnectionDeleted = 'connection_deleted';
    case ConnectionNotCreated = 'connection_not_created';
    case BotNotFound = 'bot_not_found';
    case BotDeleted = 'bot_deleted';
    case BotMismatch = 'bot_mismatch';

    /**
     * Проверить успешность обработки webhook.
     */
    public function successful(): bool
    {
        return $this === self::Connected || $this === self::AlreadyConnected;
    }
}
