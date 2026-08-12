<?php

declare(strict_types=1);

namespace Telegga\Laravel;

enum TelegramLinkStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Blocked = 'blocked';
    case Revoked = 'revoked';
}
