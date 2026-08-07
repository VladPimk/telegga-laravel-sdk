<?php

declare(strict_types=1);

namespace Telegga\Laravel\Mappers;

use Telegga\Laravel\Dto\BroadcastCancellationData;
use Telegga\Laravel\Dto\BroadcastCreatedData;
use Telegga\Laravel\Dto\BroadcastData;

final class BroadcastResponseMapper
{
    /**
     * Create the broadcast response mapper.
     */
    public function __construct(
        private readonly ResponseReader $reader,
    ) {}

    /**
     * Map a broadcast start response.
     */
    public function fromStart(mixed $response): BroadcastCreatedData
    {
        $context = 'broadcast creation response';
        $response = $this->reader->object(response: $response, context: $context);

        return new BroadcastCreatedData(
            broadcast_id: $this->reader->requiredString(response: $response, field: 'broadcast_id', context: $context),
            status: $this->reader->requiredString(response: $response, field: 'status', context: $context),
            raw: $response,
        );
    }

    /**
     * Map a broadcast response.
     */
    public function fromGet(mixed $response): BroadcastData
    {
        $context = 'broadcast response';
        $response = $this->reader->object(response: $response, context: $context);

        return new BroadcastData(
            broadcast_id: $this->reader->requiredString(response: $response, field: 'broadcast_id', context: $context),
            status: $this->reader->requiredString(response: $response, field: 'status', context: $context),
            total: $this->reader->nullableInteger(response: $response, field: 'total', context: $context),
            sent: $this->reader->nullableInteger(response: $response, field: 'sent', context: $context),
            failed: $this->reader->nullableInteger(response: $response, field: 'failed', context: $context),
            created_at: $this->reader->nullableString(response: $response, field: 'created_at', context: $context),
            raw: $response,
        );
    }

    /**
     * Map a broadcast cancellation response.
     */
    public function fromCancel(mixed $response): BroadcastCancellationData
    {
        $context = 'broadcast cancellation response';
        $response = $this->reader->object(response: $response, context: $context);

        return new BroadcastCancellationData(
            status: $this->reader->requiredString(response: $response, field: 'status', context: $context),
            cancelled_messages: $this->reader->requiredInteger(response: $response, field: 'cancelled_messages', context: $context),
            raw: $response,
        );
    }
}
