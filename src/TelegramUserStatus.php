<?php

declare(strict_types=1);

namespace Telegga\Laravel;

enum TelegramUserStatus: string
{
    case NotCreated = 'not_created';
    case Active = 'active';
    case Disabled = 'disabled';

    /**
     * Determine whether the user exists in Telegga.
     */
    public function existsInTelegga(): bool
    {
        return $this !== self::NotCreated;
    }
}
