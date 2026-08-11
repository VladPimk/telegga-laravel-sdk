<?php

declare(strict_types=1);

namespace Telegga\Laravel\Webhooks;

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
    ) {}
}
