<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Telegga\Laravel\Exceptions\WebhookException;
use Telegga\Laravel\Models\TelegramConnectedUser;

final class WebhookService
{
    /**
     * Отметить локальное подключение активным.
     */
    public function markConnected(string $externalId, string $botName): bool
    {
        try {
            $connection = TelegramConnectedUser::query()
                ->where('uuid', $externalId)
                ->whereHas(
                    relation: 'telegramBot',
                    callback: fn (Builder $query): Builder => $query->where('bot_name', $botName),
                )
                ->first();

            if ($connection === null) {
                return false;
            }

            if ($connection->is_connected) {
                return true;
            }

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

        return true;
    }
}
