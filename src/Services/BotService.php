<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Telegga\Laravel\Exceptions\BotException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Http\TeleggaClient;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Throwable;

final class BotService
{
    /**
     * Создать сервис ботов.
     */
    public function __construct(
        private readonly TeleggaClient $client,
    ) {}

    /**
     * Получить список доступных ботов.
     *
     * @return Collection<int, object>
     */
    public function getAll(): Collection
    {
        $response = $this->client->get(uri: 'bots')->object();

        if (! is_object($response) || ! is_array($response->data ?? null)) {
            throw new TeleggaApiException(
                message: 'Telegga returned an invalid bot list response.',
                status: 0,
                apiCode: 'invalid_response',
            );
        }

        foreach ($response->data as $bot) {
            if (! is_object($bot)) {
                throw new TeleggaApiException(
                    message: 'Telegga returned an invalid bot list response.',
                    status: 0,
                    apiCode: 'invalid_response',
                );
            }
        }

        return collect($response->data)->values();
    }

    /**
     * Добавить доступного Telegram-бота.
     */
    public function add(string $botName): AvailableTelegramBot
    {
        $botName = $this->validateName(botName: $botName);

        try {
            $exists = $this->getAll()->contains(
                fn (object $bot): bool => is_string($bot->username ?? null)
                    && str()->lower($bot->username) === $botName,
            );
        } catch (TeleggaApiException $exception) {
            throw new BotException(
                message: $exception->getMessage(),
                botName: $botName,
                previous: $exception,
            );
        }

        if (! $exists) {
            throw new BotException(
                message: 'Telegram bot is not available in Telegga.',
                botName: $botName,
            );
        }

        try {
            return AvailableTelegramBot::query()->firstOrCreate([
                'bot_name' => $botName,
            ]);
        } catch (QueryException $exception) {
            throw new BotException(
                message: 'Available Telegram bot could not be created.',
                botName: $botName,
                previous: $exception,
            );
        }
    }

    /**
     * Получить локально доступных Telegram-ботов.
     *
     * @return Collection<int, AvailableTelegramBot>
     */
    public function getAvailable(): Collection
    {
        try {
            return AvailableTelegramBot::query()
                ->orderBy('id')
                ->get();
        } catch (QueryException $exception) {
            throw new BotException(
                message: 'Available Telegram bots could not be loaded.',
                previous: $exception,
            );
        }
    }

    /**
     * Получить локального Telegram-бота по UUID.
     */
    public function getAvailableByUuid(string $uuid): AvailableTelegramBot
    {
        if (trim($uuid) === '') {
            throw new BotException(
                message: 'Telegram bot UUID cannot be empty.',
                botUuid: $uuid,
            );
        }

        try {
            $bot = AvailableTelegramBot::query()
                ->where('uuid', $uuid)
                ->first();
        } catch (QueryException $exception) {
            throw new BotException(
                message: 'Available Telegram bot could not be loaded.',
                botUuid: $uuid,
                previous: $exception,
            );
        }

        if ($bot === null) {
            throw new BotException(
                message: 'Available Telegram bot was not found.',
                botUuid: $uuid,
            );
        }

        return $bot;
    }

    /**
     * Найти Telegram-бота в API по имени и статусу.
     */
    public function find(string $botName, ?string $status = null): object
    {
        $botName = str()->lower($botName);

        try {
            $bot = $this->getAll()->first(
                fn (object $bot): bool => is_string($bot->username ?? null)
                    && str()->lower($bot->username) === $botName
                    && ($status === null || ($bot->status ?? null) === $status),
            );
        } catch (TeleggaApiException $exception) {
            throw new BotException(
                message: $exception->getMessage(),
                botName: $botName,
                previous: $exception,
            );
        }

        if (! is_object($bot) || ! is_string($bot->bot_id ?? null) || trim($bot->bot_id) === '') {
            throw new BotException(
                message: $status === 'active'
                    ? 'Active Telegram bot is not available in Telegga.'
                    : 'Telegram bot is not available in Telegga.',
                botName: $botName,
            );
        }

        return $bot;
    }

    /**
     * Удалить локально доступного Telegram-бота.
     */
    public function delete(string $uuid): void
    {
        $bot = $this->getAvailableByUuid(uuid: $uuid);

        try {
            if ($bot->connections()->exists()) {
                throw new BotException(
                    message: 'Telegram bot is used by connections and cannot be deleted.',
                    botName: $bot->bot_name,
                    botUuid: $bot->uuid,
                );
            }

            $bot->delete();
        } catch (BotException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BotException(
                message: 'Available Telegram bot could not be deleted.',
                botName: $bot->bot_name,
                botUuid: $bot->uuid,
                previous: $exception,
            );
        }
    }

    /**
     * Проверить имя Telegram-бота.
     */
    private function validateName(string $botName): string
    {
        $botName = str()->lower(trim($botName));

        if (preg_match('/^[A-Za-z0-9_]+$/', $botName) !== 1) {
            throw new BotException(
                message: 'Telegram bot name may contain only letters, numbers, and underscores.',
                botName: $botName,
            );
        }

        return $botName;
    }
}
