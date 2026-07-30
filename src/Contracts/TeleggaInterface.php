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
     * Получить подключённого пользователя.
     */
    public function getConnection(string $uuid): object;

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
        ?string $status = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        ?string $cursor = null,
    ): object;

    /**
     * Загрузить медиафайл.
     */
    public function uploadMedia(string $path): object;

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
}
