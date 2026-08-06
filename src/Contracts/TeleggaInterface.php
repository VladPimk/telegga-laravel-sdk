<?php

declare(strict_types=1);

namespace Telegga\Laravel\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Telegga\Laravel\Dto\BotData;
use Telegga\Laravel\Dto\BroadcastCancellationData;
use Telegga\Laravel\Dto\BroadcastCreatedData;
use Telegga\Laravel\Dto\BroadcastData;
use Telegga\Laravel\Dto\ConnectionData;
use Telegga\Laravel\Dto\GroupData;
use Telegga\Laravel\Dto\GroupMembersAddedData;
use Telegga\Laravel\Dto\GroupPageData;
use Telegga\Laravel\Dto\MediaData;
use Telegga\Laravel\Dto\MessageData;
use Telegga\Laravel\Dto\MessagePageData;
use Telegga\Laravel\Dto\QueuedMessageData;
use Telegga\Laravel\Dto\UserData;
use Telegga\Laravel\Dto\UserGroupMembershipData;
use Telegga\Laravel\Dto\UserPageData;
use Telegga\Laravel\Models\AvailableTelegramBot;

interface TeleggaInterface
{
    /**
     * Создать подключение пользователя.
     *
     * @param  array<string, mixed>  $meta
     */
    public function createConnection(
        string $name,
        string $telegramBotUuid,
        ?string $email = null,
        ?int $userId = null,
        array $meta = [],
        ?string $groupId = null,
    ): ConnectionData;

    /**
     * Повторно отправить существующее подключение.
     *
     * @param  array<string, mixed>  $meta
     */
    public function retryConnection(
        string $uuid,
        array $meta = [],
        ?string $groupId = null,
    ): ConnectionData;

    /**
     * Получить подключённого пользователя.
     */
    public function getConnection(string $uuid): UserData;

    /**
     * Получить список подключений Telegga.
     */
    public function getConnections(
        ?string $email = null,
        ?string $telegramBotUuid = null,
        ?string $status = null,
        ?string $cursor = null,
    ): UserPageData;

    /**
     * Обновить подключённого пользователя.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateConnection(string $uuid, array $data): UserData;

    /**
     * Удалить подключённого пользователя.
     */
    public function deleteConnection(string $uuid): void;

    /**
     * Выпустить новый код подключения.
     */
    public function regenerateConnectionCode(string $uuid): ConnectionData;

    /**
     * Отвязать подключённого пользователя от бота.
     */
    public function unlinkConnection(string $uuid): void;

    /**
     * Создать группу для бота подключения.
     */
    public function createGroup(
        string $uuid,
        string $name,
        ?string $description = null,
    ): GroupData;

    /**
     * Получить группы бота подключения.
     */
    public function getGroups(string $uuid, ?string $cursor = null): GroupPageData;

    /**
     * Получить группу.
     */
    public function getGroup(string $groupId): GroupData;

    /**
     * Обновить группу.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateGroup(string $groupId, array $data): GroupData;

    /**
     * Удалить группу.
     */
    public function deleteGroup(string $groupId): void;

    /**
     * Добавить подключение в группу через маршрут пользователя.
     */
    public function addConnectionToGroup(string $uuid, string $groupId): UserGroupMembershipData;

    /**
     * Удалить подключение из группы через маршрут пользователя.
     */
    public function removeConnectionFromGroup(string $uuid, string $groupId): void;

    /**
     * Добавить подключения в группу через групповой маршрут.
     *
     * @param  array<int, string>  $uuids
     */
    public function addGroupMembers(string $groupId, array $uuids): GroupMembersAddedData;

    /**
     * Удалить подключение из группы через групповой маршрут.
     */
    public function removeGroupMember(string $groupId, string $uuid): void;

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
    ): BroadcastCreatedData;

    /**
     * Получить прогресс рассылки.
     */
    public function getBroadcast(string $broadcastId): BroadcastData;

    /**
     * Отменить рассылку.
     */
    public function cancelBroadcast(string $broadcastId): BroadcastCancellationData;

    /**
     * Отправить сообщение.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendMessage(
        string $uuid,
        string $type,
        array $data = [],
    ): QueuedMessageData;

    /**
     * Получить сообщение по идентификатору.
     */
    public function getMessage(string $messageId): MessageData;

    /**
     * Получить историю сообщений пользователя.
     */
    public function getMessages(
        string $uuid,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?string $status = null,
        ?string $cursor = null,
    ): MessagePageData;

    /**
     * Загрузить медиафайл.
     */
    public function uploadMedia(string $contents, string $filename): MediaData;

    /**
     * Получить метаданные медиафайла.
     */
    public function getMedia(string $mediaId): MediaData;

    /**
     * Получить список доступных ботов.
     *
     * @return Collection<int, BotData>
     */
    public function getBots(): Collection;

    /**
     * Добавить доступного Telegram-бота.
     */
    public function addTelegramBot(string $botName): AvailableTelegramBot;

    /**
     * Получить локально доступных Telegram-ботов.
     *
     * @return Collection<int, AvailableTelegramBot>
     */
    public function getAvailableBots(): Collection;

    /**
     * Удалить локально доступного Telegram-бота.
     */
    public function deleteTelegramBot(string $uuid): void;
}
