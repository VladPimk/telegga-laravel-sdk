<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Database\QueryException;
use Telegga\Laravel\Exceptions\WebhookException;
use Telegga\Laravel\Models\TelegramConnectedUser;

final class WebhookService
{
    /**
     * Отметить локальное подключение активным.
     */
    public function markConnected(string $externalId): void
    {
        try {
            TelegramConnectedUser::query()
                ->where('uuid', $externalId)
                ->update([
                    'is_connected' => true,
                ]);
        } catch (QueryException $exception) {
            throw new WebhookException(
                message: 'Local Telegga connection state could not be updated.',
                externalId: $externalId,
                previous: $exception,
            );
        }
    }
}
