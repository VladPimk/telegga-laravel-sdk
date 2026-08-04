<?php

declare(strict_types=1);

namespace Telegga\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Telegga\Laravel\Exceptions\WebhookException;
use Telegga\Laravel\Services\WebhookService;

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
        $botName = (string) $validated['bot_username'];

        try {
            $connected = $this->webhooks->markConnected(
                externalId: $externalId,
                botName: $botName,
            );
        } catch (WebhookException $exception) {
            Log::error('Telegga webhook could not be processed.', [
                'event_id' => $eventId,
                'external_id' => $externalId,
                'bot_username' => $botName,
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

        if (! $connected) {
            Log::warning('Telegga webhook connection was not found.', [
                'event_id' => $eventId,
                'external_id' => $externalId,
                'bot_username' => $botName,
            ]);

            return $this->errorResponse(
                code: 'connection_not_found',
                message: 'Connection was not found for the provided external_id and bot_username.',
                status: 404,
                event: $event,
                eventId: $eventId,
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
     */
    private function errorResponse(
        string $code,
        string $message,
        int $status,
        ?string $event = null,
        ?string $eventId = null,
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

        return response()->json(
            data: $data,
            status: $status,
        );
    }
}
