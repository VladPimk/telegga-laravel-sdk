<?php

declare(strict_types=1);

namespace Telegga\Laravel;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Services\BotService;
use Telegga\Laravel\Services\BroadcastService;
use Telegga\Laravel\Services\ConnectionService;
use Telegga\Laravel\Services\GroupService;
use Telegga\Laravel\Services\MediaService;
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
        private readonly MediaService $media,
        private readonly GroupService $groups,
        private readonly BroadcastService $broadcasts,
    ) {}

    /**
     * Создать подключение пользователя.
     */
    public function createConnection(
        string $name,
        string $telegramBotUuid,
        ?string $email = null,
        ?int $userId = null,
    ): object {
        return $this->connections->create(
            name: $name,
            telegramBotUuid: $telegramBotUuid,
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
     * Получить подключённого пользователя.
     */
    public function getConnection(string $uuid): object
    {
        return $this->connections->get(uuid: $uuid);
    }

    /**
     * Обновить подключённого пользователя.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateConnection(string $uuid, array $data): object
    {
        return $this->connections->update(
            uuid: $uuid,
            data: $data,
        );
    }

    /**
     * Удалить подключённого пользователя.
     */
    public function deleteConnection(string $uuid): void
    {
        $this->connections->delete(uuid: $uuid);
    }

    /**
     * Выпустить новый код подключения.
     */
    public function regenerateConnectionCode(string $uuid): object
    {
        return $this->connections->regenerateCode(uuid: $uuid);
    }

    /**
     * Отвязать подключённого пользователя от бота.
     */
    public function unlinkConnection(string $uuid): void
    {
        $this->connections->unlink(uuid: $uuid);
    }

    /**
     * Создать группу для бота подключения.
     */
    public function createGroup(
        string $uuid,
        string $name,
        ?string $description = null,
    ): object {
        return $this->groups->create(
            uuid: $uuid,
            name: $name,
            description: $description,
        );
    }

    /**
     * Получить группы бота подключения.
     *
     * @return Collection<int, object>
     */
    public function getGroups(string $uuid): Collection
    {
        return $this->groups->getAll(uuid: $uuid);
    }

    /**
     * Получить группу.
     */
    public function getGroup(string $groupId): object
    {
        return $this->groups->get(groupId: $groupId);
    }

    /**
     * Обновить группу.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateGroup(string $groupId, array $data): object
    {
        return $this->groups->update(
            groupId: $groupId,
            data: $data,
        );
    }

    /**
     * Удалить группу.
     */
    public function deleteGroup(string $groupId): void
    {
        $this->groups->delete(groupId: $groupId);
    }

    /**
     * Добавить подключение в группу через маршрут пользователя.
     */
    public function addConnectionToGroup(string $uuid, string $groupId): object
    {
        return $this->groups->addConnection(
            uuid: $uuid,
            groupId: $groupId,
        );
    }

    /**
     * Удалить подключение из группы через маршрут пользователя.
     */
    public function removeConnectionFromGroup(string $uuid, string $groupId): void
    {
        $this->groups->removeConnection(
            uuid: $uuid,
            groupId: $groupId,
        );
    }

    /**
     * Добавить подключения в группу через групповой маршрут.
     *
     * @param  array<int, string>  $uuids
     */
    public function addGroupMembers(string $groupId, array $uuids): object
    {
        return $this->groups->addMembers(
            groupId: $groupId,
            uuids: $uuids,
        );
    }

    /**
     * Удалить подключение из группы через групповой маршрут.
     */
    public function removeGroupMember(string $groupId, string $uuid): void
    {
        $this->groups->removeMember(
            groupId: $groupId,
            uuid: $uuid,
        );
    }

    /**
     * Запустить рассылку.
     *
     * @param  array<string, mixed>  $data
     */
    public function startBroadcast(
        string $uuid,
        string $type,
        array $data = [],
        ?string $groupId = null,
    ): object {
        return $this->broadcasts->start(
            uuid: $uuid,
            type: $type,
            data: $data,
            groupId: $groupId,
        );
    }

    /**
     * Получить прогресс рассылки.
     */
    public function getBroadcast(string $broadcastId): object
    {
        return $this->broadcasts->get(broadcastId: $broadcastId);
    }

    /**
     * Отменить рассылку.
     */
    public function cancelBroadcast(string $broadcastId): object
    {
        return $this->broadcasts->cancel(broadcastId: $broadcastId);
    }

    /**
     * Отправить сообщение.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendMessage(
        string $uuid,
        string $type,
        array $data = [],
    ): object {
        return $this->messages->send(
            uuid: $uuid,
            type: $type,
            data: $data,
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
     * Получить историю сообщений пользователя.
     */
    public function getMessages(
        string $uuid,
        ?string $status = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        ?string $cursor = null,
    ): object {
        return $this->messages->getHistory(
            uuid: $uuid,
            status: $status,
            from: $from,
            to: $to,
            cursor: $cursor,
        );
    }

    /**
     * Загрузить медиафайл.
     */
    public function uploadMedia(string $path): object
    {
        return $this->media->upload(path: $path);
    }

    /**
     * Получить метаданные медиафайла.
     */
    public function getMedia(string $mediaId): object
    {
        return $this->media->get(mediaId: $mediaId);
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

    /**
     * Добавить доступного Telegram-бота.
     */
    public function addTelegramBot(string $botName): AvailableTelegramBot
    {
        return $this->bots->add(botName: $botName);
    }

    /**
     * Получить локально доступных Telegram-ботов.
     *
     * @return Collection<int, AvailableTelegramBot>
     */
    public function getAvailableBots(): Collection
    {
        return $this->bots->getAvailable();
    }

    /**
     * Удалить локально доступного Telegram-бота.
     */
    public function deleteTelegramBot(string $uuid): void
    {
        $this->bots->delete(uuid: $uuid);
    }
}
