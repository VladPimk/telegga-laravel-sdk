<?php

declare(strict_types=1);

namespace Telegga\Laravel\Webhooks;

use Telegga\Laravel\TelegramLinkStatus;
use Telegga\Laravel\TelegramUserStatus;

final readonly class WebhookProcessingResult
{
    /**
     * Create a webhook processing result.
     */
    public function __construct(
        public WebhookProcessingStatus $status,
        public ?string $expectedBotName = null,
        public ?string $expectedEvent = null,
        public ?WebhookProcessingStatus $failureStatus = null,
        public ?TelegramUserStatus $userStatus = null,
        public ?TelegramLinkStatus $linkStatus = null,
    ) {}
}
