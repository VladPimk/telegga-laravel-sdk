<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Database\QueryException;
use Telegga\Laravel\Exceptions\WebhookException;
use Telegga\Laravel\Models\TelegramConnectedUser;
use Telegga\Laravel\Webhooks\WebhookProcessingResult;
use Telegga\Laravel\Webhooks\WebhookProcessingStatus;

final class WebhookService
{
    /**
     * Отметить локальное подключение активным.
     */
    public function markConnected(string $externalId, string $botName): WebhookProcessingResult
    {
        try {
            $connection = TelegramConnectedUser::withTrashed()
                ->where('uuid', $externalId)
                ->first();
        } catch (QueryException $exception) {
            throw new WebhookException(
                message: 'Local Telegga connection could not be loaded.',
                externalId: $externalId,
                previous: $exception,
            );
        }

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

        if ($connection->is_connected) {
            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::AlreadyConnected,
                expectedBotName: $expectedBotName,
            );
        }

        try {
            $connection->update([
                'is_connected' => true,
            ]);
        } catch (QueryException $exception) {
            throw new WebhookException(
                message: 'Local Telegga connection state could not be updated.',
                externalId: $externalId,
                previous: $exception,
            );
        }

        return new WebhookProcessingResult(
            status: WebhookProcessingStatus::Connected,
            expectedBotName: $expectedBotName,
        );
    }
}
