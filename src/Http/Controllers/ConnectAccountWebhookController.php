<?php

declare(strict_types=1);

namespace Telegga\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $event = $request->input(key: 'event');

        if (! is_string($event) || trim($event) === '') {
            return $this->invalidRequest(message: 'Webhook event is required.');
        }

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

        $eventId = $request->input(key: 'event_id');

        if ($eventId !== null && (! is_string($eventId) || trim($eventId) === '')) {
            return $this->invalidRequest(
                message: 'Webhook event_id must be a non-empty string.',
                event: $event,
            );
        }

        $externalId = $request->input(key: 'external_id');

        if (! is_string($externalId) || trim($externalId) === '') {
            return $this->invalidRequest(
                message: 'Webhook external_id is required.',
                event: $event,
                eventId: $eventId,
            );
        }

        $botName = $request->input(key: 'bot_username');

        if (! is_string($botName) || trim($botName) === '') {
            return $this->invalidRequest(
                message: 'Webhook bot_username is required.',
                event: $event,
                eventId: $eventId,
            );
        }

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
