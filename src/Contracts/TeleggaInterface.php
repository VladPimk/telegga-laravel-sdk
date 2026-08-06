<?php

declare(strict_types=1);

namespace Telegga\Laravel\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;
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
    ): object;

    /**
     * Повторно отправить существующее подключение.
     *
     * @param  array<string, mixed>  $meta
     */
    public function retryConnection(
        string $uuid,
        array $meta = [],
        ?string $groupId = null,
    ): object;

    /**
     * Получить подключённого пользователя.
     */
    public function getConnection(string $uuid): object;

    /**
     * Получить список подключений Telegga.
     */
    public function getConnections(
        ?string $email = null,
        ?string $telegramBotUuid = null,
        ?string $status = null,
        ?string $cursor = null,
    ): object;

    /**
     * Обновить подключённого пользователя.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateConnection(string $uuid, array $data): object;

    /**
     * Удалить подключённого пользователя.
     */
    public function deleteConnection(string $uuid): void;

    /**
     * Выпустить новый код подключения.
     */
    public function regenerateConnectionCode(string $uuid): object;

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
    ): object;

    /**
     * Получить группы бота подключения.
     *
     * @return Collection<int, object>
     */
    public function getGroups(string $uuid): Collection;

    /**
     * Получить группу.
     */
    public function getGroup(string $groupId): object;

    /**
     * Обновить группу.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateGroup(string $groupId, array $data): object;

    /**
     * Удалить группу.
     */
    public function deleteGroup(string $groupId): void;

    /**
     * Добавить подключение в группу через маршрут пользователя.
     */
    public function addConnectionToGroup(string $uuid, string $groupId): object;

    /**
     * Удалить подключение из группы через маршрут пользователя.
     */
    public function removeConnectionFromGroup(string $uuid, string $groupId): void;

    /**
     * Добавить подключения в группу через групповой маршрут.
     *
     * @param  array<int, string>  $uuids
     */
    public function addGroupMembers(string $groupId, array $uuids): object;

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
    ): object;

    /**
     * Получить прогресс рассылки.
     */
    public function getBroadcast(string $broadcastId): object;

    /**
     * Отменить рассылку.
     */
    public function cancelBroadcast(string $broadcastId): object;

    /**
     * Отправить сообщение.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendMessage(
        string $uuid,
        string $type,
        array $data = [],
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
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?string $status = null,
        ?string $cursor = null,
    ): object;

    /**
     * Загрузить медиафайл.
     */
    public function uploadMedia(string $contents, string $filename): object;

    /**
     * Получить метаданные медиафайла.
     */
    public function getMedia(string $mediaId): object;

    /**
     * Получить список доступных ботов.
     *
     * @return Collection<int, object>
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
