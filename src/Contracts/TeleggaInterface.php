<?php

declare(strict_types=1);

namespace Telegga\Laravel\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;

interface TeleggaInterface
{
    /**
     * Создать подключение пользователя.
     */
    public function createConnection(
        string $name,
        ?string $email = null,
        ?int $userId = null,
    ): object;

    /**
     * Повторно отправить существующее подключение.
     */
    public function retryConnection(string $uuid): object;

    /**
     * Отправить текстовое сообщение.
     *
     * @param  array<int, array<int, array{text: string, url: string}>>  $buttons
     */
    public function sendText(
        string $uuid,
        string $text,
        ?string $parseMode = null,
        array $buttons = [],
        bool $disableWebPagePreview = false,
        bool $disableNotification = false,
    ): object;

    /**
     * Получить сообщение по идентификатору.
     */
    public function getMessage(string $messageId): object;

    /**
     * Получить историю сообщений пользователя.
     */
    public function getMessages(
        string $uuid,
        ?string $status = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        ?string $cursor = null,
    ): object;

    /**
     * Получить список доступных ботов.
     *
     * @return Collection<int, object>
     */
    public function getBots(): Collection;
}
