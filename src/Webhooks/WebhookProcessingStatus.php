<?php

declare(strict_types=1);

namespace Telegga\Laravel\Webhooks;

enum WebhookProcessingStatus: string
{
    case Connected = 'connected';
    case AlreadyConnected = 'already_connected';
    case Duplicate = 'duplicate';
    case EventIdConflict = 'event_id_conflict';
    case ConnectionNotFound = 'connection_not_found';
    case ConnectionDeleted = 'connection_deleted';
    case BotNotFound = 'bot_not_found';
    case BotDeleted = 'bot_deleted';
    case BotMismatch = 'bot_mismatch';

    /**
     * Determine whether webhook processing succeeded.
     */
    public function successful(): bool
    {
        return in_array($this, [
            self::Connected,
            self::AlreadyConnected,
            self::Duplicate,
        ], true);
    }
}
