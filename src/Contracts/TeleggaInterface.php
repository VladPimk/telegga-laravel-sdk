<?php

declare(strict_types=1);

namespace Telegga\Laravel\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Telegga\Laravel\BroadcastAudience;
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
     * Create a user connection.
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
     * Resend an existing connection.
     *
     * @param  array<string, mixed>  $meta
     */
    public function retryConnection(
        string $uuid,
        array $meta = [],
        ?string $groupId = null,
    ): ConnectionData;

    /**
     * Get a connected user.
     */
    public function getConnection(string $uuid): UserData;

    /**
     * Get a list of Telegga connections.
     */
    public function getConnections(
        ?string $email = null,
        ?string $telegramBotUuid = null,
        ?string $status = null,
        ?string $cursor = null,
    ): UserPageData;

    /**
     * Update a connected user.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateConnection(string $uuid, array $data): UserData;

    /**
     * Delete a connected user.
     */
    public function deleteConnection(string $uuid): void;

    /**
     * Generate a new connection code.
     */
    public function regenerateConnectionCode(string $uuid): ConnectionData;

    /**
     * Unlink a connected user from the bot.
     */
    public function unlinkConnection(string $uuid): void;

    /**
     * Create a group for the connection bot.
     */
    public function createGroup(
        string $uuid,
        string $name,
        ?string $description = null,
    ): GroupData;

    /**
     * Get groups for the connection bot.
     */
    public function getGroups(string $uuid, ?string $cursor = null): GroupPageData;

    /**
     * Get a group.
     */
    public function getGroup(string $groupId): GroupData;

    /**
     * Update a group.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateGroup(string $groupId, array $data): GroupData;

    /**
     * Delete a group.
     */
    public function deleteGroup(string $groupId): void;

    /**
     * Add a connection to a group through the user endpoint.
     */
    public function addConnectionToGroup(string $uuid, string $groupId): UserGroupMembershipData;

    /**
     * Remove a connection from a group through the user endpoint.
     */
    public function removeConnectionFromGroup(string $uuid, string $groupId): void;

    /**
     * Add connections to a group through the group endpoint.
     *
     * @param  array<int, string>  $uuids
     */
    public function addGroupMembers(string $groupId, array $uuids): GroupMembersAddedData;

    /**
     * Remove a connection from a group through the group endpoint.
     */
    public function removeGroupMember(string $groupId, string $uuid): void;

    /**
     * Start a broadcast for an explicit audience using the connection only to resolve the bot.
     *
     * @param  array<string, mixed>  $data
     */
    public function startBroadcast(
        string $viaConnectionUuid,
        string $type,
        ?BroadcastAudience $audience = null,
        array $data = [],
    ): BroadcastCreatedData;

    /**
     * Get broadcast progress.
     */
    public function getBroadcast(string $broadcastId): BroadcastData;

    /**
     * Cancel a broadcast.
     */
    public function cancelBroadcast(string $broadcastId): BroadcastCancellationData;

    /**
     * Send a message.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendMessage(
        string $uuid,
        string $type,
        array $data = [],
    ): QueuedMessageData;

    /**
     * Get a message by its identifier.
     */
    public function getMessage(string $messageId): MessageData;

    /**
     * Get user message history.
     */
    public function getMessages(
        string $uuid,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?string $status = null,
        ?string $cursor = null,
    ): MessagePageData;

    /**
     * Upload a media file.
     */
    public function uploadMedia(string $contents, string $filename): MediaData;

    /**
     * Get media file metadata.
     */
    public function getMedia(string $mediaId): MediaData;

    /**
     * Get a list of available bots.
     *
     * @return Collection<int, BotData>
     */
    public function getBots(): Collection;

    /**
     * Add an available Telegram bot.
     */
    public function addTelegramBot(string $botName): AvailableTelegramBot;

    /**
     * Get locally available Telegram bots.
     *
     * @return Collection<int, AvailableTelegramBot>
     */
    public function getAvailableBots(): Collection;

    /**
     * Delete a locally available Telegram bot.
     */
    public function deleteTelegramBot(string $uuid): void;
}
