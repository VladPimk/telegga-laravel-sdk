<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Telegga\Laravel\Exceptions\WebhookException;
use Telegga\Laravel\Models\TeleggaWebhookEvent;
use Telegga\Laravel\Models\TelegramConnectedUser;
use Telegga\Laravel\TelegramLinkStatus;
use Telegga\Laravel\TelegramUserStatus;
use Telegga\Laravel\Webhooks\WebhookProcessingResult;
use Telegga\Laravel\Webhooks\WebhookProcessingStatus;

final class WebhookService
{
    private const int BOT_VALIDATION_RETRY_WINDOW_HOURS = 6;

    /**
     * Process a user connection event.
     */
    public function markConnected(
        string $eventId,
        string $event,
        string $externalId,
        string $botName,
        Carbon $linkedAt,
    ): WebhookProcessingResult {
        $connection = $this->findConnection(externalId: $externalId);

        if ($connection === null) {
            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::ConnectionNotFound,
            );
        }

        if ($connection->trashed()) {
            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::ConnectionDeleted,
            );
        }

        $webhookEvent = $this->findEvent(eventId: $eventId);

        if ($webhookEvent !== null) {
            $existingEventResult = $this->handleExistingEvent(
                webhookEvent: $webhookEvent,
                connection: $connection,
                event: $event,
            );

            if ($existingEventResult !== null) {
                return $existingEventResult;
            }
        }

        if ($webhookEvent === null) {
            $webhookEvent = $this->registerEvent(
                eventId: $eventId,
                event: $event,
                connection: $connection,
            );

            if (! $webhookEvent->wasRecentlyCreated) {
                $existingEventResult = $this->handleExistingEvent(
                    webhookEvent: $webhookEvent,
                    connection: $connection,
                    event: $event,
                );

                if ($existingEventResult !== null) {
                    return $existingEventResult;
                }
            }
        }

        $botValidation = $this->validateBot(
            connection: $connection,
            botName: $botName,
            externalId: $externalId,
        );

        if ($botValidation instanceof WebhookProcessingResult) {
            return $this->resolveBotValidationFailure(
                result: $botValidation,
                linkedAt: $linkedAt,
            );
        }

        return $this->processEvent(
            webhookEvent: $webhookEvent,
            connection: $connection,
            expectedBotName: $botValidation,
            externalId: $externalId,
        );
    }

    /**
     * Resolve a retryable bot validation failure.
     */
    private function resolveBotValidationFailure(
        WebhookProcessingResult $result,
        Carbon $linkedAt,
    ): WebhookProcessingResult {
        if ($linkedAt->isAfter(now()->subHours(self::BOT_VALIDATION_RETRY_WINDOW_HOURS))) {
            return $result;
        }

        return new WebhookProcessingResult(
            status: WebhookProcessingStatus::RetryWindowExpired,
            expectedBotName: $result->expectedBotName,
            failureStatus: $result->status,
        );
    }

    /**
     * Find a local connection by external identifier.
     */
    private function findConnection(string $externalId): ?TelegramConnectedUser
    {
        try {
            return TelegramConnectedUser::withTrashed()
                ->where('uuid', $externalId)
                ->first();
        } catch (QueryException $exception) {
            throw new WebhookException(
                message: 'Local Telegga connection could not be loaded.',
                externalId: $externalId,
                previous: $exception,
            );
        }
    }

    /**
     * Find a previously accepted event.
     */
    private function findEvent(string $eventId): ?TeleggaWebhookEvent
    {
        try {
            return TeleggaWebhookEvent::query()
                ->where('event_id', $eventId)
                ->first();
        } catch (QueryException $exception) {
            throw new WebhookException(
                message: 'Telegga webhook event could not be loaded.',
                previous: $exception,
            );
        }
    }

    /**
     * Handle a repeatedly received event.
     */
    private function handleExistingEvent(
        TeleggaWebhookEvent $webhookEvent,
        TelegramConnectedUser $connection,
        string $event,
    ): ?WebhookProcessingResult {
        if (
            $webhookEvent->telegram_connected_user_id !== $connection->getKey()
            || $webhookEvent->event !== $event
        ) {
            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::EventIdConflict,
                expectedEvent: $webhookEvent->event,
            );
        }

        try {
            $webhookEvent->increment('attempts');
            $webhookEvent->refresh();
        } catch (QueryException $exception) {
            throw new WebhookException(
                message: 'Telegga webhook delivery attempt could not be recorded.',
                externalId: $connection->uuid,
                previous: $exception,
            );
        }

        if ($webhookEvent->processed_at !== null) {
            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::Duplicate,
                userStatus: $connection->status,
                linkStatus: $connection->link_status,
            );
        }

        return null;
    }

    /**
     * Validate the Telegram bot assigned to the connection.
     */
    private function validateBot(
        TelegramConnectedUser $connection,
        string $botName,
        string $externalId,
    ): WebhookProcessingResult|string {
        try {
            $telegramBot = $connection->telegramBot()
                ->withTrashed()
                ->first();
        } catch (QueryException $exception) {
            throw new WebhookException(
                message: 'Local Telegram bot could not be loaded.',
                externalId: $externalId,
                previous: $exception,
            );
        }

        if ($telegramBot === null) {
            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::BotNotFound,
            );
        }

        $expectedBotName = str()->lower($telegramBot->bot_name);

        if ($telegramBot->trashed()) {
            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::BotDeleted,
                expectedBotName: $expectedBotName,
            );
        }

        if ($expectedBotName !== str()->lower($botName)) {
            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::BotMismatch,
                expectedBotName: $expectedBotName,
            );
        }

        return $expectedBotName;
    }

    /**
     * Record the first delivery of an event.
     */
    private function registerEvent(
        string $eventId,
        string $event,
        TelegramConnectedUser $connection,
    ): TeleggaWebhookEvent {
        try {
            return TeleggaWebhookEvent::query()->firstOrCreate(
                attributes: [
                    'event_id' => $eventId,
                ],
                values: [
                    'telegram_connected_user_id' => $connection->getKey(),
                    'event' => $event,
                    'attempts' => 1,
                    'first_seen_at' => now(),
                ],
            );
        } catch (QueryException $exception) {
            throw new WebhookException(
                message: 'Telegga webhook event could not be registered.',
                externalId: $connection->uuid,
                previous: $exception,
            );
        }
    }

    /**
     * Apply the event effect and mark it as processed.
     */
    private function processEvent(
        TeleggaWebhookEvent $webhookEvent,
        TelegramConnectedUser $connection,
        string $expectedBotName,
        string $externalId,
    ): WebhookProcessingResult {
        try {
            return DB::transaction(function () use (
                $webhookEvent,
                $connection,
                $expectedBotName,
            ): WebhookProcessingResult {
                $lockedEvent = TeleggaWebhookEvent::query()
                    ->whereKey($webhookEvent->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedConnection = TelegramConnectedUser::withTrashed()
                    ->whereKey($connection->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedEvent->processed_at !== null) {
                    return new WebhookProcessingResult(
                        status: WebhookProcessingStatus::Duplicate,
                        expectedBotName: $expectedBotName,
                        userStatus: $lockedConnection->status,
                        linkStatus: $lockedConnection->link_status,
                    );
                }

                $status = $lockedConnection->link_status === TelegramLinkStatus::Active
                    ? WebhookProcessingStatus::AlreadyConnected
                    : WebhookProcessingStatus::Connected;

                $attributes = [];

                if ($lockedConnection->link_status !== TelegramLinkStatus::Active) {
                    $attributes['link_status'] = TelegramLinkStatus::Active;
                }

                if ($lockedConnection->link_url !== null) {
                    $attributes['link_url'] = null;
                }

                if ($lockedConnection->link_expires_at !== null) {
                    $attributes['link_expires_at'] = null;
                }

                if ($lockedConnection->status === TelegramUserStatus::NotCreated) {
                    $attributes['status'] = TelegramUserStatus::Active;
                }

                if ($attributes !== []) {
                    $lockedConnection->update(attributes: $attributes);
                }

                $lockedEvent->update([
                    'processed_at' => now(),
                ]);

                return new WebhookProcessingResult(
                    status: $status,
                    expectedBotName: $expectedBotName,
                    userStatus: $lockedConnection->status,
                    linkStatus: $lockedConnection->link_status,
                );
            });
        } catch (QueryException $exception) {
            throw new WebhookException(
                message: 'Telegga webhook event could not be processed.',
                externalId: $externalId,
                previous: $exception,
            );
        }
    }
}
