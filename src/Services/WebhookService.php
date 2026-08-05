<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Telegga\Laravel\Exceptions\WebhookException;
use Telegga\Laravel\Models\TeleggaWebhookEvent;
use Telegga\Laravel\Models\TelegramConnectedUser;
use Telegga\Laravel\Webhooks\WebhookProcessingResult;
use Telegga\Laravel\Webhooks\WebhookProcessingStatus;

final class WebhookService
{
    /**
     * Обработать событие подключения пользователя.
     */
    public function markConnected(
        string $eventId,
        string $event,
        string $externalId,
        string $botName,
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

        if (! $connection->is_created) {
            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::ConnectionNotCreated,
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

        $botValidation = $this->validateBot(
            connection: $connection,
            botName: $botName,
            externalId: $externalId,
        );

        if ($botValidation instanceof WebhookProcessingResult) {
            return $botValidation;
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

        return $this->processEvent(
            webhookEvent: $webhookEvent,
            connection: $connection,
            expectedBotName: $botValidation,
            externalId: $externalId,
        );
    }

    /**
     * Найти локальное подключение по внешнему идентификатору.
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
     * Найти ранее принятое событие.
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
     * Обработать повторно принятое событие.
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
            );
        }

        return null;
    }

    /**
     * Проверить назначенного подключению Telegram-бота.
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
     * Зарегистрировать первое получение события.
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
     * Выполнить эффект события и отметить его обработанным.
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

                if ($lockedEvent->processed_at !== null) {
                    return new WebhookProcessingResult(
                        status: WebhookProcessingStatus::Duplicate,
                        expectedBotName: $expectedBotName,
                    );
                }

                $lockedConnection = TelegramConnectedUser::withTrashed()
                    ->whereKey($connection->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $status = WebhookProcessingStatus::AlreadyConnected;

                if (! $lockedConnection->is_connected) {
                    $lockedConnection->update([
                        'is_connected' => true,
                    ]);
                    $status = WebhookProcessingStatus::Connected;
                }

                $lockedEvent->update([
                    'processed_at' => now(),
                ]);

                return new WebhookProcessingResult(
                    status: $status,
                    expectedBotName: $expectedBotName,
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
