<?php

declare(strict_types=1);

namespace Telegga\Laravel\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

final readonly class WebhookResponse implements Responsable
{
    /**
     * Создать HTTP-ответ webhook.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $details
     */
    private function __construct(
        private WebhookResponseCode $code,
        private ?string $event,
        private ?string $eventId,
        private string $message,
        private array $data,
        private array $details,
    ) {}

    /**
     * Создать успешный ответ webhook.
     *
     * @param  array<string, mixed>  $data
     */
    public static function success(
        WebhookResponseCode $code,
        ?string $event = null,
        ?string $eventId = null,
        array $data = [],
    ): self {
        if (! $code->successful()) {
            throw new LogicException('A webhook error code cannot create a successful response.');
        }

        return new self(
            code: $code,
            event: $event,
            eventId: $eventId,
            message: $code->message(),
            data: $data,
            details: [],
        );
    }

    /**
     * Создать ответ webhook с ошибкой.
     *
     * @param  array<string, string>  $details
     */
    public static function error(
        WebhookResponseCode $code,
        ?string $event = null,
        ?string $eventId = null,
        array $details = [],
        ?string $message = null,
    ): self {
        if ($code->successful()) {
            throw new LogicException('A successful webhook code cannot create an error response.');
        }

        return new self(
            code: $code,
            event: $event,
            eventId: $eventId,
            message: $message ?? $code->message(),
            data: [],
            details: $details,
        );
    }

    /**
     * Преобразовать webhook-ответ в JSON.
     *
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        $successful = $this->code->successful();
        $payload = [
            'success' => $successful,
        ];

        if ($this->event !== null) {
            $payload['event'] = $this->event;
        }

        if ($this->eventId !== null) {
            $payload['event_id'] = $this->eventId;
        }

        if ($successful) {
            $payload['message'] = $this->message;

            if ($this->data !== []) {
                $payload['data'] = $this->data;
            }
        } else {
            $payload['error'] = [
                'code' => $this->code->value,
                'message' => $this->message,
            ];

            if ($this->details !== []) {
                $payload['error']['details'] = $this->details;
            }
        }

        return response()->json(
            data: $payload,
            status: $this->code->httpStatus(),
        );
    }
}
