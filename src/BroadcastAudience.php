<?php

declare(strict_types=1);

namespace Telegga\Laravel;

use Telegga\Laravel\Exceptions\BroadcastException;

final readonly class BroadcastAudience
{
    /**
     * Create a broadcast audience.
     */
    private function __construct(
        public ?string $groupId,
    ) {}

    /**
     * Target all linked users of the resolved bot.
     */
    public static function allLinkedUsers(): self
    {
        return new self(groupId: null);
    }

    /**
     * Target members of a specific group.
     */
    public static function group(string $groupId): self
    {
        $groupId = trim($groupId);

        if ($groupId === '') {
            throw new BroadcastException(
                message: 'Group identifier cannot be empty.',
            );
        }

        return new self(groupId: $groupId);
    }
}
