<?php

declare(strict_types=1);

namespace Telegga\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Telegga\Laravel\Exceptions\WebhookException;
use Telegga\Laravel\Http\Responses\WebhookResponse;
use Telegga\Laravel\Http\Responses\WebhookResponseCode;
use Telegga\Laravel\Services\WebhookService;
use Telegga\Laravel\Webhooks\WebhookProcessingResult;
use Telegga\Laravel\Webhooks\WebhookProcessingStatus;

final class ConnectAccountWebhookController
{
    /**
     * Create the connection webhook handler.
     */
    public function __construct(
        private readonly WebhookService $webhooks,
    ) {}

    /**
     * Handle a connection webhook.
     */
    public function __invoke(Request $request): WebhookResponse
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
     * Handle a test event.
     */
    private function handleTest(Request $request): WebhookResponse
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

        return WebhookResponse::success(
            code: WebhookResponseCode::Accepted,
            event: 'test',
        );
    }

    /**
     * Handle a user connection event.
     */
    private function handleUserLinked(Request $request, string $event): WebhookResponse
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
        $linkedAt = Carbon::parse((string) $validated['linked_at']);

        try {
            $result = $this->webhooks->markConnected(
                eventId: $eventId,
                event: $event,
                externalId: $externalId,
                botName: $botName,
                linkedAt: $linkedAt,
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

            return WebhookResponse::error(
                code: WebhookResponseCode::Internal,
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
     * Create a response for an unsupported event.
     */
    private function unsupportedEvent(string $event): WebhookResponse
    {
        Log::warning('Telegga webhook event is not supported.', [
            'event' => $event,
            'error_code' => 'unsupported_event',
        ]);

        return WebhookResponse::error(
            code: WebhookResponseCode::UnsupportedEvent,
            event: $event,
        );
    }

    /**
     * Create a successful webhook processing response.
     */
    private function successResponse(
        WebhookProcessingResult $result,
        string $event,
        string $eventId,
        string $externalId,
        string $botName,
    ): WebhookResponse {
        return WebhookResponse::success(
            code: WebhookResponseCode::fromProcessingStatus(status: $result->status),
            event: $event,
            eventId: $eventId,
            data: [
                'external_id' => $externalId,
                'bot_username' => $botName,
                'is_connected' => true,
            ],
        );
    }

    /**
     * Create an invalid webhook response.
     */
    private function invalidRequest(
        string $message,
        ?string $event = null,
        ?string $eventId = null,
    ): WebhookResponse {
        Log::warning('Telegga webhook request validation failed.', [
            'event' => $event,
            'event_id' => $eventId,
            'error_code' => 'invalid_request',
            'error_message' => $message,
        ]);

        return WebhookResponse::error(
            code: WebhookResponseCode::InvalidRequest,
            message: $message,
            event: $event,
            eventId: $eventId,
        );
    }

    /**
     * Create a webhook processing error response.
     */
    private function processingError(
        WebhookProcessingResult $result,
        string $event,
        string $eventId,
        string $externalId,
        string $botName,
    ): WebhookResponse {
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

        if ($result->failureStatus !== null) {
            $details['failure_code'] = $result->failureStatus->value;
        }

        $logContext = [
            'event' => $event,
            'event_id' => $eventId,
            'external_id' => $externalId,
            'received_bot_username' => $botName,
            'expected_bot_username' => $result->expectedBotName,
            'expected_event' => $result->expectedEvent,
            'error_code' => $result->status->value,
            'failure_code' => $result->failureStatus?->value,
        ];

        if ($result->status === WebhookProcessingStatus::RetryWindowExpired) {
            Log::error('Telegga webhook retry window expired.', $logContext);
        } else {
            Log::warning('Telegga webhook request was rejected.', $logContext);
        }

        return WebhookResponse::error(
            code: WebhookResponseCode::fromProcessingStatus(status: $result->status),
            event: $event,
            eventId: $eventId,
            details: $details,
        );
    }
}
