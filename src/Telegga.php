<?php

declare(strict_types=1);

namespace Telegga\Laravel;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Telegga\Laravel\Contracts\TeleggaInterface;
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
use Telegga\Laravel\Services\BotService;
use Telegga\Laravel\Services\BroadcastService;
use Telegga\Laravel\Services\ConnectionService;
use Telegga\Laravel\Services\GroupService;
use Telegga\Laravel\Services\MediaService;
use Telegga\Laravel\Services\MessageService;

final class Telegga implements TeleggaInterface
{
    /**
     * Create the Telegga service.
     */
    public function __construct(
        private readonly ConnectionService $connections,
        private readonly BotService $bots,
        private readonly MessageService $messages,
        private readonly MediaService $media,
        private readonly GroupService $groups,
        private readonly BroadcastService $broadcasts,
    ) {}

    /** {@inheritDoc} */
    public function createConnection(
        string $name,
        string $telegramBotUuid,
        ?string $email = null,
        ?int $userId = null,
        array $meta = [],
        ?string $groupId = null,
    ): ConnectionData {
        return $this->connections->create(
            name: $name,
            telegramBotUuid: $telegramBotUuid,
            email: $email,
            userId: $userId,
            meta: $meta,
            groupId: $groupId,
        );
    }

    /** {@inheritDoc} */
    public function retryConnection(
        string $uuid,
        array $meta = [],
        ?string $groupId = null,
    ): ConnectionData {
        return $this->connections->retry(
            uuid: $uuid,
            meta: $meta,
            groupId: $groupId,
        );
    }

    /** {@inheritDoc} */
    public function getConnection(string $uuid): UserData
    {
        return $this->connections->get(uuid: $uuid);
    }

    /** {@inheritDoc} */
    public function getConnections(
        ?string $email = null,
        ?string $telegramBotUuid = null,
        ?string $status = null,
        ?string $cursor = null,
    ): UserPageData {
        return $this->connections->getAll(
            email: $email,
            telegramBotUuid: $telegramBotUuid,
            status: $status,
            cursor: $cursor,
        );
    }

    /** {@inheritDoc} */
    public function updateConnection(string $uuid, array $data): UserData
    {
        return $this->connections->update(
            uuid: $uuid,
            data: $data,
        );
    }

    /** {@inheritDoc} */
    public function deleteConnection(string $uuid): void
    {
        $this->connections->delete(uuid: $uuid);
    }

    /** {@inheritDoc} */
    public function regenerateConnectionCode(string $uuid): ConnectionData
    {
        return $this->connections->regenerateCode(uuid: $uuid);
    }

    /** {@inheritDoc} */
    public function unlinkConnection(string $uuid): void
    {
        $this->connections->unlink(uuid: $uuid);
    }

    /** {@inheritDoc} */
    public function createGroup(
        string $uuid,
        string $name,
        ?string $description = null,
    ): GroupData {
        return $this->groups->create(
            uuid: $uuid,
            name: $name,
            description: $description,
        );
    }

    /** {@inheritDoc} */
    public function getGroups(string $uuid, ?string $cursor = null): GroupPageData
    {
        return $this->groups->getAll(
            uuid: $uuid,
            cursor: $cursor,
        );
    }

    /** {@inheritDoc} */
    public function getGroup(string $groupId): GroupData
    {
        return $this->groups->get(groupId: $groupId);
    }

    /** {@inheritDoc} */
    public function updateGroup(string $groupId, array $data): GroupData
    {
        return $this->groups->update(
            groupId: $groupId,
            data: $data,
        );
    }

    /** {@inheritDoc} */
    public function deleteGroup(string $groupId): void
    {
        $this->groups->delete(groupId: $groupId);
    }

    /** {@inheritDoc} */
    public function addConnectionToGroup(string $uuid, string $groupId): UserGroupMembershipData
    {
        return $this->groups->addConnection(
            uuid: $uuid,
            groupId: $groupId,
        );
    }

    /** {@inheritDoc} */
    public function removeConnectionFromGroup(string $uuid, string $groupId): void
    {
        $this->groups->removeConnection(
            uuid: $uuid,
            groupId: $groupId,
        );
    }

    /** {@inheritDoc} */
    public function addGroupMembers(string $groupId, array $uuids): GroupMembersAddedData
    {
        return $this->groups->addMembers(
            groupId: $groupId,
            uuids: $uuids,
        );
    }

    /** {@inheritDoc} */
    public function removeGroupMember(string $groupId, string $uuid): void
    {
        $this->groups->removeMember(
            groupId: $groupId,
            uuid: $uuid,
        );
    }

    /** {@inheritDoc} */
    public function startBroadcast(
        string $viaConnectionUuid,
        string $type,
        ?BroadcastAudience $audience = null,
        array $data = [],
    ): BroadcastCreatedData {
        return $this->broadcasts->start(
            viaConnectionUuid: $viaConnectionUuid,
            type: $type,
            audience: $audience,
            data: $data,
        );
    }

    /** {@inheritDoc} */
    public function getBroadcast(string $broadcastId): BroadcastData
    {
        return $this->broadcasts->get(broadcastId: $broadcastId);
    }

    /** {@inheritDoc} */
    public function cancelBroadcast(string $broadcastId): BroadcastCancellationData
    {
        return $this->broadcasts->cancel(broadcastId: $broadcastId);
    }

    /** {@inheritDoc} */
    public function sendMessage(
        string $uuid,
        string $type,
        array $data = [],
    ): QueuedMessageData {
        return $this->messages->send(
            uuid: $uuid,
            type: $type,
            data: $data,
        );
    }

    /** {@inheritDoc} */
    public function getMessage(string $messageId): MessageData
    {
        return $this->messages->get(messageId: $messageId);
    }

    /** {@inheritDoc} */
    public function getMessages(
        string $uuid,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?string $status = null,
        ?string $cursor = null,
    ): MessagePageData {
        return $this->messages->getHistory(
            uuid: $uuid,
            status: $status,
            from: $from,
            to: $to,
            cursor: $cursor,
        );
    }

    /** {@inheritDoc} */
    public function uploadMedia(string $contents, string $filename): MediaData
    {
        return $this->media->upload(
            contents: $contents,
            filename: $filename,
        );
    }

    /** {@inheritDoc} */
    public function getMedia(string $mediaId): MediaData
    {
        return $this->media->get(mediaId: $mediaId);
    }

    /** {@inheritDoc} */
    public function getBots(): Collection
    {
        return $this->bots->getAll();
    }

    /** {@inheritDoc} */
    public function addTelegramBot(string $botName): AvailableTelegramBot
    {
        return $this->bots->add(botName: $botName);
    }

    /** {@inheritDoc} */
    public function getAvailableBots(): Collection
    {
        return $this->bots->getAvailable();
    }

    /** {@inheritDoc} */
    public function deleteTelegramBot(string $uuid): void
    {
        $this->bots->delete(uuid: $uuid);
    }
}
