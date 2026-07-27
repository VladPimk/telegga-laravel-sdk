<?php

declare(strict_types=1);

namespace Telegga\Laravel\Data;

use UnexpectedValueException;

final readonly class BotData
{
    /**
     * Создать данные бота.
     */
    public function __construct(
        public string $botId,
        public string $username,
        public string $displayName,
        public string $status,
    ) {}

    /**
     * Создать объект из ответа API.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            botId: self::requiredString(data: $data, key: 'bot_id'),
            username: self::requiredString(data: $data, key: 'username'),
            displayName: self::requiredString(data: $data, key: 'display_name'),
            status: self::requiredString(data: $data, key: 'status'),
        );
    }

    /**
     * Получить обязательное строковое значение.
     *
     * @param  array<string, mixed>  $data
     */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException(
                message: "Telegga bot response field [{$key}] is invalid.",
            );
        }

        return $value;
    }
}
