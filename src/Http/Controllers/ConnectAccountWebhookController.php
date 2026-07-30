<?php

declare(strict_types=1);

namespace Telegga\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
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
    public function __invoke(Request $request): Response
    {
        $event = $request->input(key: 'event');

        if (! is_string($event) || trim($event) === '') {
            return $this->invalidRequest(message: 'Webhook event is required.');
        }

        if ($event === 'user.linked') {
            $externalId = $request->input(key: 'external_id');

            if (! is_string($externalId) || trim($externalId) === '') {
                return $this->invalidRequest(message: 'Webhook external_id is required.');
            }

            $this->webhooks->markConnected(externalId: $externalId);
        }

        return response()->noContent();
    }

    /**
     * Создать ответ о некорректном webhook.
     */
    private function invalidRequest(string $message): JsonResponse
    {
        return response()->json(
            data: [
                'error' => [
                    'code' => 'invalid_request',
                    'message' => $message,
                ],
            ],
            status: 400,
        );
    }
}
