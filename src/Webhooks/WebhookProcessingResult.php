<?php

declare(strict_types=1);

namespace Telegga\Laravel\Webhooks;

final readonly class WebhookProcessingResult
{
    /**
     * Создать результат обработки webhook.
     */
    public function __construct(
        public WebhookProcessingStatus $status,
        public ?string $expectedBotName = null,
    ) {}
}
