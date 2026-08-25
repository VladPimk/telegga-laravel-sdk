<?php

declare(strict_types=1);

namespace Telegga\Laravel\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Telegga\Laravel\Dto\BotData;
use Telegga\Laravel\Dto\BotSyncData;
use Telegga\Laravel\Exceptions\BotException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Http\TeleggaClient;
use Telegga\Laravel\Mappers\BotResponseMapper;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Throwable;

final class BotService
{
    private const int CACHE_VERSION = 2;

    private const int CACHE_TTL_SECONDS = 600;

    private readonly string $cacheKey;

    /**
     * Create the bot service.
     */
    public function __construct(
        private readonly TeleggaClient $client,
        private readonly Repository $cache,
        private readonly BotResponseMapper $mapper,
    ) {
        $scope = implode('|', [
            (string) config(key: 'telegga.base_url'),
            (string) config(key: 'telegga.api_key'),
        ]);

        $this->cacheKey = sprintf(
            'telegga:bots:v%d:%s',
            self::CACHE_VERSION,
            hash('sha256', $scope),
        );
    }

    /**
     * Get a list of available bots.
     *
     * @return Collection<int, BotData>
     */
    public function getAll(): Collection
    {
        $response = $this->cache->remember(
            $this->cacheKey,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->fetchAll(),
        );

        return $this->mapper->fromArray(response: $response);
    }

    /**
     * Fetch the current list of available bots from the API.
     *
     * @return array<mixed>
     */
    private function fetchAll(): array
    {
        return $this->mapper->validatedArray(
            response: $this->client->get(uri: 'bots')->json(),
        );
    }

    /**
     * Add an available Telegram bot.
     */
    public function add(string $botName): AvailableTelegramBot
    {
        $botName = $this->validateName(botName: $botName);

        try {
            $this->cache->forget($this->cacheKey);

            $remoteBot = $this->getAll()->first(
                fn (BotData $bot): bool => str()->lower($bot->username) === $botName,
            );
        } catch (TeleggaApiException $exception) {
            throw new BotException(
                message: $exception->getMessage(),
                botName: $botName,
                previous: $exception,
            );
        }

        if (! $remoteBot instanceof BotData) {
            throw new BotException(
                message: 'Telegram bot is not available in Telegga.',
                botName: $botName,
            );
        }

        try {
            $localBot = AvailableTelegramBot::query()->firstOrCreate(
                ['bot_name' => $botName],
                ['display_name' => $remoteBot->display_name],
            );

            if (! $localBot->wasRecentlyCreated && $localBot->display_name !== $remoteBot->display_name) {
                $localBot->update([
                    'display_name' => $remoteBot->display_name,
                ]);
            }

            return $localBot;
        } catch (QueryException $exception) {
            throw new BotException(
                message: 'Available Telegram bot could not be saved.',
                botName: $botName,
                previous: $exception,
            );
        }
    }

    /**
     * Get locally available Telegram bots.
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
     * Synchronize active API bots with locally available bots.
     */
    public function sync(): BotSyncData
    {
        try {
            $this->cache->forget($this->cacheKey);
            $bots = $this->getAll();
        } catch (Throwable $exception) {
            throw new BotException(
                message: 'Available Telegram bots could not be loaded from Telegga.',
                previous: $exception,
            );
        }

        /** @var Collection<string, BotData> $activeBots */
        $activeBots = $bots
            ->filter(fn (BotData $bot): bool => $bot->status === 'active')
            ->keyBy(fn (BotData $bot): string => $this->validateName(botName: $bot->username));
        $botNames = $activeBots->keys();

        try {
            return DB::transaction(function () use ($bots, $activeBots, $botNames): BotSyncData {
                /** @var Collection<string, AvailableTelegramBot> $existingBots */
                $existingBots = AvailableTelegramBot::query()
                    ->whereIn('bot_name', $botNames)
                    ->get()
                    ->keyBy('bot_name');
                $missingBots = $activeBots->reject(
                    fn (BotData $bot, string $botName): bool => $existingBots->has($botName),
                );
                $timestamp = now();

                if ($missingBots->isNotEmpty()) {
                    AvailableTelegramBot::query()->insert(
                        $missingBots
                            ->map(fn (BotData $bot, string $botName): array => [
                                'uuid' => str()->uuid7()->toString(),
                                'bot_name' => $botName,
                                'display_name' => $bot->display_name,
                                'created_at' => $timestamp,
                                'updated_at' => $timestamp,
                            ])
                            ->all(),
                    );
                }

                foreach ($existingBots as $botName => $localBot) {
                    $displayName = $activeBots->get($botName)?->display_name;

                    if ($localBot->display_name !== $displayName) {
                        $localBot->update([
                            'display_name' => $displayName,
                        ]);
                    }
                }

                return new BotSyncData(
                    received: $bots->count(),
                    active: $activeBots->count(),
                    created: $missingBots->count(),
                    existing: $existingBots->count(),
                );
            });
        } catch (Throwable $exception) {
            throw new BotException(
                message: 'Available Telegram bots could not be synchronized locally.',
                previous: $exception,
            );
        }
    }

    /**
     * Get a local Telegram bot by UUID.
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
     * Find a Telegram bot in the API by name and status.
     */
    public function find(string $botName, ?string $status = null): BotData
    {
        $botName = str()->lower($botName);

        try {
            $bot = $this->getAll()->first(
                fn (BotData $bot): bool => str()->lower($bot->username) === $botName
                    && ($status === null || $bot->status === $status),
            );
        } catch (TeleggaApiException $exception) {
            throw new BotException(
                message: $exception->getMessage(),
                botName: $botName,
                previous: $exception,
            );
        }

        if (! $bot instanceof BotData) {
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
     * Delete a locally available Telegram bot.
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

            $this->cache->forget($this->cacheKey);
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
     * Validate a Telegram bot name.
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
