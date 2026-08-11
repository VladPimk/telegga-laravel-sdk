<?php

declare(strict_types=1);

namespace Telegga\Laravel\Mappers;

use Telegga\Laravel\Dto\DeliveryAttemptData;
use Telegga\Laravel\Dto\MessageData;
use Telegga\Laravel\Dto\MessagePageData;
use Telegga\Laravel\Dto\QueuedMessageData;

final class MessageResponseMapper
{
    /**
     * Create the message response mapper.
     */
    public function __construct(
        private readonly ResponseReader $reader,
    ) {}

    /**
     * Map a message send response.
     */
    public function fromSend(mixed $response): QueuedMessageData
    {
        $context = 'message creation response';
        $response = $this->reader->object(response: $response, context: $context);

        return new QueuedMessageData(
            message_id: $this->reader->requiredString(response: $response, field: 'message_id', context: $context),
            status: $this->reader->requiredString(response: $response, field: 'status', context: $context),
            created_at: $this->reader->nullableString(response: $response, field: 'created_at', context: $context),
            raw: $response,
        );
    }

    /**
     * Map a message response.
     */
    public function fromGet(mixed $response): MessageData
    {
        return $this->mapMessage(response: $response, context: 'message response');
    }

    /**
     * Map a message history response.
     */
    public function fromList(mixed $response): MessagePageData
    {
        $context = 'message list response';
        $response = $this->reader->object(response: $response, context: $context);
        $messages = $this->reader->requiredArray(response: $response, field: 'data', context: $context);

        return new MessagePageData(
            data: collect($messages)
                ->map(fn (mixed $message): MessageData => $this->mapMessage(
                    response: $message,
                    context: 'message list item response',
                ))
                ->values(),
            next_cursor: $this->reader->nullableString(response: $response, field: 'next_cursor', context: $context),
            raw: $response,
        );
    }

    /**
     * Map message data.
     */
    private function mapMessage(mixed $response, string $context): MessageData
    {
        $response = $this->reader->object(response: $response, context: $context);
        $attempts = $this->reader->optionalArray(response: $response, field: 'delivery_attempts', context: $context);

        return new MessageData(
            message_id: $this->reader->requiredString(response: $response, field: 'message_id', context: $context),
            status: $this->reader->requiredString(response: $response, field: 'status', context: $context),
            type: $this->reader->nullableString(response: $response, field: 'type', context: $context),
            attempts: $this->reader->nullableInteger(response: $response, field: 'attempts', context: $context),
            telegram_message_id: $this->reader->nullableInteger(response: $response, field: 'telegram_message_id', context: $context),
            created_at: $this->reader->nullableString(response: $response, field: 'created_at', context: $context),
            sent_at: $this->reader->nullableString(response: $response, field: 'sent_at', context: $context),
            error_code: $this->reader->nullableString(response: $response, field: 'error_code', context: $context),
            error_message: $this->reader->nullableString(response: $response, field: 'error_message', context: $context),
            delivery_attempts: collect($attempts)
                ->map(fn (mixed $attempt): DeliveryAttemptData => $this->mapDeliveryAttempt(response: $attempt))
                ->values(),
            raw: $response,
        );
    }

    /**
     * Map delivery attempt data.
     */
    private function mapDeliveryAttempt(mixed $response): DeliveryAttemptData
    {
        $context = 'message delivery attempt response';
        $response = $this->reader->object(response: $response, context: $context);

        return new DeliveryAttemptData(
            at: $this->reader->requiredString(response: $response, field: 'at', context: $context),
            ok: $this->reader->requiredBoolean(response: $response, field: 'ok', context: $context),
            latency_ms: $this->reader->requiredInteger(response: $response, field: 'latency_ms', context: $context),
            raw: $response,
        );
    }
}
