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

        if ($event === 'test') {
            return response()->json(data: [
                'success' => true,
                'event' => 'test',
                'message' => 'Webhook accepted.',
            ]);
        }

        if ($event !== 'user.linked') {
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

        $payloadValidation = Validator::make(
            data: $request->all(),
            rules: [
                'event_id' => ['sometimes', 'required', 'string'],
                'external_id' => ['required', 'string'],
                'bot_username' => ['required', 'string'],
            ],
            messages: [
                'event_id.required' => 'Webhook event_id must be a non-empty string.',
                'event_id.string' => 'Webhook event_id must be a non-empty string.',
                'external_id.required' => 'Webhook external_id is required.',
                'external_id.string' => 'Webhook external_id is required.',
                'bot_username.required' => 'Webhook bot_username is required.',
                'bot_username.string' => 'Webhook bot_username is required.',
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
        $eventId = isset($validated['event_id']) ? (string) $validated['event_id'] : null;
        $externalId = (string) $validated['external_id'];
        $botName = str()->lower((string) $validated['bot_username']);

        try {
            $result = $this->webhooks->markConnected(
                externalId: $externalId,
                botName: $botName,
            );
        } catch (WebhookException $exception) {
            Log::error('Telegga webhook could not be processed.', [
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

        $response = [
            'success' => true,
            'event' => $event,
        ];

        if ($eventId !== null) {
            $response['event_id'] = $eventId;
        }

        $response['message'] = 'Telegram connection marked as connected.';
        $response['data'] = [
            'external_id' => $externalId,
            'bot_username' => $botName,
            'is_connected' => true,
        ];

        return response()->json(data: $response);
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
        ?string $eventId,
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

        Log::warning('Telegga webhook request was rejected.', [
            'event' => $event,
            'event_id' => $eventId,
            'external_id' => $externalId,
            'received_bot_username' => $botName,
            'expected_bot_username' => $result->expectedBotName,
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
            WebhookProcessingStatus::ConnectionNotCreated,
            WebhookProcessingStatus::BotMismatch => 409,
            WebhookProcessingStatus::Connected,
            WebhookProcessingStatus::AlreadyConnected => 500,
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
            WebhookProcessingStatus::ConnectionNotCreated => 'Telegram connection has not been created in Telegga.',
            WebhookProcessingStatus::BotNotFound => 'Telegram bot assigned to the connection was not found.',
            WebhookProcessingStatus::BotDeleted => 'Telegram bot assigned to the connection has been deleted.',
            WebhookProcessingStatus::BotMismatch => 'Telegram connection is assigned to a different bot.',
            WebhookProcessingStatus::Connected,
            WebhookProcessingStatus::AlreadyConnected => 'Webhook could not be processed.',
        };
    }
}
