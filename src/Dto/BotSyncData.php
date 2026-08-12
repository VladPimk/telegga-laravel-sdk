<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

final readonly class BotSyncData
{
    /**
     * Create Telegram bot synchronization data.
     */
    public function __construct(
        public int $received,
        public int $active,
        public int $created,
        public int $existing,
    ) {}
}
