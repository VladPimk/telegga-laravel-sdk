<?php

declare(strict_types=1);

namespace Telegga\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Telegga\Laravel\Exceptions\WebhookException;
use Telegga\Laravel\Services\WebhookService;
use Telegga\Laravel\Webhooks\WebhookProcessingResult;
use Telegga\Laravel\Webhooks\WebhookProcessingStatus;

final class ConnectAccountWebhookController
{
    /**
     * Создать обработчик webhook подключения.
     */
    public function __construct(
        private readonly WebhookService $webhooks,
    ) {}

    /**
     * Обработать webhook подключения.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $eventValidation = Validator::make(
            data: $request->all(),
            rules: [
                'event' => ['required', 'string'],
            ],
            messages: [
                'event.required' => 'Webhook event is required.',
                'event.string' => 'Webhook event is required.',
            ],
        );

        if ($eventValidation->fails()) {
            return $this->invalidRequest(
                message: $eventValidation->errors()->first(),
            );
        }

        $event = (string) $eventValidation->validated()['event'];

        return match ($event) {
            'test' => $this->handleTest(request: $request),
            'user.linked' => $this->handleUserLinked(request: $request, event: $event),
            default => $this->unsupportedEvent(event: $event),
        };
    }

    /**
     * Обработать проверочное событие.
     */
    private function handleTest(Request $request): JsonResponse
    {
        $validation = Validator::make(
            data: $request->all(),
            rules: [
                'service_id' => ['required', 'string'],
                'sent_at' => ['required', 'date'],
            ],
            messages: [
                'service_id.required' => 'Webhook service_id is required.',
                'service_id.string' => 'Webhook service_id is required.',
                'sent_at.required' => 'Webhook sent_at is required.',
                'sent_at.date' => 'Webhook sent_at must be a valid RFC 3339 date.',
            ],
        );

        if ($validation->fails()) {
            return $this->invalidRequest(
                message: $validation->errors()->first(),
                event: 'test',
            );
        }

        return response()->json(data: [
            'success' => true,
            'event' => 'test',
            'message' => 'Webhook accepted.',
        ]);
    }

    /**
     * Обработать событие подключения пользователя.
     */
    private function handleUserLinked(Request $request, string $event): JsonResponse
    {
        $payloadValidation = Validator::make(
            data: $request->all(),
            rules: [
                'event_id' => ['required', 'string'],
                'external_id' => ['required', 'string'],
                'bot_username' => ['required', 'string'],
                'service_id' => ['required', 'string'],
                'user_id' => ['required', 'string'],
                'bot_id' => ['required', 'string'],
                'telegram_user_id' => ['required', 'integer'],
                'linked_at' => ['required', 'date'],
            ],
            messages: [
                'event_id.required' => 'Webhook event_id must be a non-empty string.',
                'event_id.string' => 'Webhook event_id must be a non-empty string.',
                'external_id.required' => 'Webhook external_id is required.',
                'external_id.string' => 'Webhook external_id is required.',
                'bot_username.required' => 'Webhook bot_username is required.',
                'bot_username.string' => 'Webhook bot_username is required.',
                'service_id.required' => 'Webhook service_id is required.',
                'service_id.string' => 'Webhook service_id is required.',
                'user_id.required' => 'Webhook user_id is required.',
                'user_id.string' => 'Webhook user_id is required.',
                'bot_id.required' => 'Webhook bot_id is required.',
                'bot_id.string' => 'Webhook bot_id is required.',
                'telegram_user_id.required' => 'Webhook telegram_user_id is required.',
                'telegram_user_id.integer' => 'Webhook telegram_user_id must be an integer.',
                'linked_at.required' => 'Webhook linked_at is required.',
                'linked_at.date' => 'Webhook linked_at must be a valid RFC 3339 date.',
            ],
        );

        if ($payloadValidation->fails()) {
            $eventId = $payloadValidation->valid()['event_id'] ?? null;

            return $this->invalidRequest(
                message: $payloadValidation->errors()->first(),
                event: $event,
                eventId: is_string($eventId) ? $eventId : null,
            );
        }

        $validated = $payloadValidation->validated();
        $eventId = (string) $validated['event_id'];
        $externalId = (string) $validated['external_id'];
        $botName = str()->lower((string) $validated['bot_username']);

        try {
            $result = $this->webhooks->markConnected(
                eventId: $eventId,
                event: $event,
                externalId: $externalId,
                botName: $botName,
            );
        } catch (WebhookException $exception) {
            Log::error('Telegga webhook could not be processed.', [
                'event' => $event,
                'event_id' => $eventId,
                'external_id' => $externalId,
                'bot_username' => $botName,
                'error_code' => 'internal',
                'exception' => $exception,
            ]);

            return $this->errorResponse(
                code: 'internal',
                message: 'Webhook could not be processed.',
                status: 500,
                event: $event,
                eventId: $eventId,
            );
        }

        if (! $result->status->successful()) {
            return $this->processingError(
                result: $result,
                event: $event,
                eventId: $eventId,
                externalId: $externalId,
                botName: $botName,
            );
        }

        return $this->successResponse(
            result: $result,
            event: $event,
            eventId: $eventId,
            externalId: $externalId,
            botName: $botName,
        );
    }

    /**
     * Создать ответ для неподдерживаемого события.
     */
    private function unsupportedEvent(string $event): JsonResponse
    {
        Log::warning('Telegga webhook event is not supported.', [
            'event' => $event,
            'error_code' => 'unsupported_event',
        ]);

        return $this->errorResponse(
            code: 'unsupported_event',
            message: 'Webhook event is not supported.',
            status: 400,
            event: $event,
        );
    }

    /**
     * Создать успешный ответ обработки webhook.
     */
    private function successResponse(
        WebhookProcessingResult $result,
        string $event,
        string $eventId,
        string $externalId,
        string $botName,
    ): JsonResponse {
        return response()->json(data: [
            'success' => true,
            'event' => $event,
            'event_id' => $eventId,
            'message' => match ($result->status) {
                WebhookProcessingStatus::Duplicate => 'Webhook event has already been processed.',
                WebhookProcessingStatus::AlreadyConnected => 'Telegram connection is already connected.',
                default => 'Telegram connection marked as connected.',
            },
            'data' => [
                'external_id' => $externalId,
                'bot_username' => $botName,
                'is_connected' => true,
            ],
        ]);
    }

    /**
     * Создать ответ о некорректном webhook.
     */
    private function invalidRequest(
        string $message,
        ?string $event = null,
        ?string $eventId = null,
    ): JsonResponse {
        Log::warning('Telegga webhook request validation failed.', [
            'event' => $event,
            'event_id' => $eventId,
            'error_code' => 'invalid_request',
            'error_message' => $message,
        ]);

        return $this->errorResponse(
            code: 'invalid_request',
            message: $message,
            status: 400,
            event: $event,
            eventId: $eventId,
        );
    }

    /**
     * Создать ответ об ошибке webhook.
     *
     * @param  array<string, string>  $details
     */
    private function errorResponse(
        string $code,
        string $message,
        int $status,
        ?string $event = null,
        ?string $eventId = null,
        array $details = [],
    ): JsonResponse {
        $data = [
            'success' => false,
        ];

        if ($event !== null) {
            $data['event'] = $event;
        }

        if ($eventId !== null) {
            $data['event_id'] = $eventId;
        }

        $data['error'] = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $data['error']['details'] = $details;
        }

        return response()->json(
            data: $data,
            status: $status,
        );
    }

    /**
     * Создать ответ об ошибке обработки webhook.
     */
    private function processingError(
        WebhookProcessingResult $result,
        string $event,
        string $eventId,
        string $externalId,
        string $botName,
    ): JsonResponse {
        $details = [
            'external_id' => $externalId,
            'received_bot_username' => $botName,
        ];

        if ($result->expectedBotName !== null) {
            $details['expected_bot_username'] = $result->expectedBotName;
        }

        if ($result->expectedEvent !== null) {
            $details['expected_event'] = $result->expectedEvent;
        }

        Log::warning('Telegga webhook request was rejected.', [
            'event' => $event,
            'event_id' => $eventId,
            'external_id' => $externalId,
            'received_bot_username' => $botName,
            'expected_bot_username' => $result->expectedBotName,
            'expected_event' => $result->expectedEvent,
            'error_code' => $result->status->value,
        ]);

        return $this->errorResponse(
            code: $result->status->value,
            message: $this->processingErrorMessage(status: $result->status),
            status: $this->processingErrorStatus(status: $result->status),
            event: $event,
            eventId: $eventId,
            details: $details,
        );
    }

    /**
     * Получить HTTP-статус ошибки обработки webhook.
     */
    private function processingErrorStatus(WebhookProcessingStatus $status): int
    {
        return match ($status) {
            WebhookProcessingStatus::ConnectionNotFound,
            WebhookProcessingStatus::ConnectionDeleted,
            WebhookProcessingStatus::BotNotFound,
            WebhookProcessingStatus::BotDeleted => 404,
            WebhookProcessingStatus::BotMismatch,
            WebhookProcessingStatus::EventIdConflict => 409,
            WebhookProcessingStatus::Connected,
            WebhookProcessingStatus::AlreadyConnected,
            WebhookProcessingStatus::Duplicate => 500,
        };
    }

    /**
     * Получить сообщение ошибки обработки webhook.
     */
    private function processingErrorMessage(WebhookProcessingStatus $status): string
    {
        return match ($status) {
            WebhookProcessingStatus::ConnectionNotFound => 'Telegram connection was not found for the provided external_id.',
            WebhookProcessingStatus::ConnectionDeleted => 'Telegram connection for the provided external_id has been deleted.',
            WebhookProcessingStatus::BotNotFound => 'Telegram bot assigned to the connection was not found.',
            WebhookProcessingStatus::BotDeleted => 'Telegram bot assigned to the connection has been deleted.',
            WebhookProcessingStatus::BotMismatch => 'Telegram connection is assigned to a different bot.',
            WebhookProcessingStatus::EventIdConflict => 'Webhook event_id is already assigned to a different connection or event.',
            WebhookProcessingStatus::Connected,
            WebhookProcessingStatus::AlreadyConnected,
            WebhookProcessingStatus::Duplicate => 'Webhook could not be processed.',
        };
    }
}
