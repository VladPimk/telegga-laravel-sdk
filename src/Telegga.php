<?php

declare(strict_types=1);

namespace Telegga\Laravel;

use Illuminate\Support\Collection;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Services\BotService;
use Telegga\Laravel\Services\ConnectionService;
use Telegga\Laravel\Services\MessageService;

final class Telegga implements TeleggaInterface
{
    /**
     * Создать сервис Telegga.
     */
    public function __construct(
        private readonly ConnectionService $connections,
        private readonly BotService $bots,
        private readonly MessageService $messages,
    ) {}

    /**
     * Создать подключение пользователя.
     */
    public function createConnection(
        string $name,
        ?string $email = null,
        ?int $userId = null,
    ): object {
        return $this->connections->create(
            name: $name,
            email: $email,
            userId: $userId,
        );
    }

    /**
     * Повторно отправить существующее подключение.
     */
    public function retryConnection(string $uuid): object
    {
        return $this->connections->retry(uuid: $uuid);
    }

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
    ): object {
        return $this->messages->sendText(
            uuid: $uuid,
            text: $text,
            parseMode: $parseMode,
            buttons: $buttons,
            disableWebPagePreview: $disableWebPagePreview,
            disableNotification: $disableNotification,
        );
    }

    /**
     * Получить сообщение по идентификатору.
     */
    public function getMessage(string $messageId): object
    {
        return $this->messages->get(messageId: $messageId);
    }

    /**
     * Получить список доступных ботов.
     *
     * @return Collection<int, object>
     */
    public function getBots(): Collection
    {
        return $this->bots->getAll();
    }
}
