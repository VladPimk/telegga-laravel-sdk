# Telegga Laravel SDK

Laravel package for integrating applications with the Telegga API.

> This package was developed for internal Par Soft projects and is publicly available under the MIT license.

## Requirements

- PHP 8.3 or later.
- Laravel 12 or 13.

## Installation

Install the package through Composer:

```bash
composer require telegga/laravel-sdk
```

Laravel automatically registers the package service provider.

Package migrations are registered automatically and run together with the application migrations:

```bash
php artisan migrate
```

The package uses its bundled configuration by default. Publish the configuration file only when application-level customization is required:

```bash
php artisan vendor:publish --tag=telegga-config
```

Configure the Telegga API key and a project-generated token for incoming webhooks:

```dotenv
TELEGGA_API_KEY=tg_live_XXXXXXXXXXXXXXXX
TELEGGA_WEBHOOK_TOKEN=random-secret-string
TELEGGA_TIMEOUT=15
TELEGGA_CONNECT_TIMEOUT=5
```

The API base URL defaults to `https://api.telegga.net/api/v1` and must use HTTPS. `TELEGGA_TIMEOUT` limits the total request time, while `TELEGGA_CONNECT_TIMEOUT` limits the time spent establishing a connection.

Set the same `TELEGGA_WEBHOOK_TOKEN` value as the webhook bearer token in the Telegga admin panel.

## Available Telegram bots

Before creating connections, register a bot that is available to the Telegga service:

```php
$bot = $telegga->addTelegramBot(
    botName: 'auctiongate_notification_bot',
);
```

The package accepts and stores the username without the `@` prefix, matching the format returned by the API. During validation through `GET /bots`, the returned `username` is compared with the local name using an exact, case-sensitive match. The package does not store `bot_id` or any other bot data returned by the API. The model `uuid` is generated locally.

Retrieving locally registered bots does not send an API request:

```php
$bots = $telegga->getAvailableBots();
```

Delete an unused bot by its local UUID:

```php
$telegga->deleteTelegramBot(uuid: $bot->uuid);
```

Deletion is rejected when the bot is associated with at least one connection.

## Creating a connection

A connection may exist independently of an application user:

```php
$result = $telegga->createConnection(
    name: 'John',
    telegramBotUuid: $bot->uuid,
    email: 'john@example.com',
    meta: [
        'locale' => 'en',
    ],
    groupId: $groupId,
);
```

Pass the application user ID when the connection should be associated with an existing user:

```php
$result = $telegga->createConnection(
    name: 'John',
    telegramBotUuid: $bot->uuid,
    email: 'john@example.com',
    userId: 42,
);
```

When `link_status` is `pending`, the returned object exposes `link_url`, `link_code`, and `expires_at`. If the user is already connected, Telegga returns `link_status: active` without issuing a new code, so these fields may be absent.

```php
if (($result->link_status ?? null) === 'pending') {
    $linkUrl = $result->link_url ?? null;
    $expiresAt = $result->expires_at ?? null;
}
```

The `meta` and `groupId` parameters are optional. The package sends them as `meta` and `group_id` in the `POST /users` request. These values are not stored locally.

## Retrying a connection

A retry is performed only through an explicit call and uses the existing local UUID:

```php
$result = $telegga->retryConnection(
    uuid: $uuid,
    meta: [
        'locale' => 'en',
    ],
    groupId: $groupId,
);
```

The package does not perform automatic retries. If `meta` or `groupId` were used during the first attempt, pass them again when explicitly retrying the connection.

When a local record was created before the request failed, its UUID is available from the exception:

```php
try {
    $result = $telegga->createConnection(
        name: 'John',
        telegramBotUuid: $bot->uuid,
        email: 'john@example.com',
    );
} catch (\Telegga\Laravel\Exceptions\ConnectionException $exception) {
    $uuid = $exception->connectionUuid;
}
```

## Managing connections

All connection operations accept the UUID of the local record. The package compares the `bot_username` returned by Telegga with the local `bot_name` using an exact, case-sensitive match. Both values use the username without the `@` prefix. Internal Telegga user and bot identifiers are not stored locally.

Retrieve a user together with links and groups:

```php
$connection = $telegga->getConnection(uuid: $uuid);
```

Request a paginated list of Telegga users with optional filters:

```php
$page = $telegga->getConnections(
    email: 'john@example.com',
    telegramBotUuid: $bot->uuid,
    status: 'active',
    cursor: $cursor,
);

foreach ($page->data as $connection) {
    $externalId = $connection->external_id;
}

$nextCursor = $page->next_cursor;
```

The package resolves the local bot UUID to the internal `bot_id`. The `data` field is returned as a collection of the original API objects, while a missing `next_cursor` is normalized to `null`.

Update the display name, email, or status:

```php
$connection = $telegga->updateConnection(
    uuid: $uuid,
    data: [
        'display_name' => 'John Smith',
        'email' => 'new@example.com',
        'status' => 'disabled',
    ],
);
```

After a successful API response, `display_name` and `email` are synchronized with the local `name` and `email` fields. An empty `email` string clears the local value. The user status is not stored locally.

Explicitly generate a new code for an existing link:

```php
$result = $telegga->regenerateConnectionCode(uuid: $uuid);
```

Unlinking a user from the bot preserves the local record and sets `is_connected` to `false`:

```php
$telegga->unlinkConnection(uuid: $uuid);
```

Full deletion removes the user from Telegga first and deletes the local record only after a successful API response:

```php
$telegga->deleteConnection(uuid: $uuid);
```

## Sending messages

All message types are sent through one method. The package resolves the active link for the selected bot and adds `external_id`, `bot_id`, and `type` to the request:

```php
$result = $telegga->sendMessage(
    uuid: $connectionUuid,
    type: 'text',
    data: [
        'text' => 'Order <b>#1234</b> has been shipped',
        'parse_mode' => 'HTML',
        'buttons' => [
            [
                [
                    'text' => 'Track order',
                    'url' => 'https://example.com/track/1234',
                ],
            ],
        ],
        'disable_web_page_preview' => true,
        'disable_notification' => true,
    ],
);
```

Send media through the same method after uploading the file:

```php
$result = $telegga->sendMessage(
    uuid: $connectionUuid,
    type: 'photo',
    data: [
        'media_id' => $mediaId,
        'text' => 'Photo caption',
    ],
);
```

For `location`, pass `latitude` and `longitude` in `data`. For `contact`, pass `phone_number`, `first_name`, and an optional `last_name`.

The method supports `text`, `photo`, `video`, `document`, `audio`, `voice`, `animation`, `sticker`, `location`, and `contact`. The `data` payload is not restricted by a rigid DTO, so new fields and types can be used without updating the package. Values for `external_id`, `bot_id`, and `type` supplied in `data` are always replaced with values resolved by the package, while `user_id` is removed. The recipient is determined exclusively by the local connection UUID.

The method returns the original API response object with `message_id`, `status`, and `created_at`. Messages and their statuses are not stored locally.

## Message status

Request the delivery status using the `message_id` returned when the message was queued:

```php
$message = $telegga->getMessage(
    messageId: $messageId,
);
```

The method returns the original API response object with the status, attempt count, delivery timestamps, and `delivery_attempts`.

## User message history

Message history is always requested through a local connection UUID. The package finds the Telegga user by the local `external_id`, resolves the internal `user_id`, and always passes that identifier to `GET /messages`:

```php
$page = $telegga->getMessages(
    uuid: $connectionUuid,
    status: 'sent',
    from: new DateTimeImmutable('2026-07-01T00:00:00+03:00'),
    to: new DateTimeImmutable('2026-07-30T23:59:59+03:00'),
    cursor: $cursor,
);

foreach ($page->data as $message) {
    $messageId = $message->message_id;
}

$nextCursor = $page->next_cursor;
```

The `from` and `to` parameters are required, ensuring that every history request has an explicit date range. The `status` and `cursor` parameters are optional. Dates are sent to the API in RFC 3339 format.

The `data` field is returned as a `Collection` of objects without a rigid DTO, keeping new API fields available to the application. `next_cursor` contains the next page cursor or `null`. The public interface does not support retrieving the full service message history without specifying a local connection.

## Media files

Upload a file as multipart data from a readable local path:

```php
$media = $telegga->uploadMedia(
    path: storage_path('app/photo.jpg'),
);

$mediaId = $media->media_id;
```

Request metadata for an uploaded file using its `media_id`:

```php
$metadata = $telegga->getMedia(
    mediaId: $mediaId,
);
```

Both methods return the original API response objects without rigid DTOs. The package does not determine the MIME type or enforce size limits itself. File contents, supported types, and limits are validated by the Telegga API. Neither the file nor its `media_id` is stored locally.

## Groups

A group is created for the bot associated with a local connection. The package resolves `bot_id` automatically:

```php
$group = $telegga->createGroup(
    uuid: $connectionUuid,
    name: 'VIP',
    description: 'VIP customers',
);

$groups = $telegga->getGroups(uuid: $connectionUuid);
```

Retrieving, updating, and deleting a group uses the `group_id` returned by the API:

```php
$group = $telegga->getGroup(groupId: $groupId);

$group = $telegga->updateGroup(
    groupId: $groupId,
    data: [
        'name' => 'Premium',
        'description' => 'Premium customers',
    ],
);

$telegga->deleteGroup(groupId: $groupId);
```

Manage membership for one connection through the user routes:

```php
$result = $telegga->addConnectionToGroup(
    uuid: $connectionUuid,
    groupId: $groupId,
);

$telegga->removeConnectionFromGroup(
    uuid: $connectionUuid,
    groupId: $groupId,
);
```

Group member routes accept local UUIDs, which the package resolves to internal Telegga `user_id` values:

```php
$result = $telegga->addGroupMembers(
    groupId: $groupId,
    uuids: [$firstUuid, $secondUuid],
);

$telegga->removeGroupMember(
    groupId: $groupId,
    uuid: $firstUuid,
);
```

Duplicate UUIDs are removed before sending the request. The API accepts up to 10,000 members per request, but the package first performs a Telegga user lookup for every unique local UUID. For large datasets, send connections in separate batches while respecting the API limit. The package does not perform automatic retries.

Groups and memberships are not stored locally. Objects and collections are returned without rigid DTOs.

## Broadcasts

Start a broadcast to all connected users of a bot using a local connection UUID:

```php
$broadcast = $telegga->startBroadcast(
    uuid: $connectionUuid,
    type: 'text',
    data: [
        'text' => 'Special offer!',
    ],
);
```

To limit recipients to group members, pass `groupId`:

```php
$broadcast = $telegga->startBroadcast(
    uuid: $connectionUuid,
    type: 'photo',
    data: [
        'media_id' => $mediaId,
        'text' => 'New special offer',
    ],
    groupId: $groupId,
);
```

Message fields are passed through the open `data` payload, as in `sendMessage()`. Values for `external_id`, `user_id`, `bot_id`, `group_id`, and `type` supplied in `data` are removed or replaced with parameters resolved by the package.

Request progress or cancel a broadcast using its `broadcast_id`:

```php
$broadcast = $telegga->getBroadcast(
    broadcastId: $broadcastId,
);

$result = $telegga->cancelBroadcast(
    broadcastId: $broadcastId,
);
```

Broadcasts and their progress are not stored locally. All methods return the original API response objects without rigid DTOs.

## Incoming webhooks

The package automatically registers this route:

```text
POST /webhooks/v1/telegram/connect-account
```

Set the application base URL in the Telegga admin panel. Telegga appends the webhook path automatically.

Every request must contain the project token:

```http
Authorization: Bearer <TELEGGA_WEBHOOK_TOKEN>
```

An empty, missing, or invalid token returns `401`. Tokens are compared securely using `hash_equals`.

The `user.linked` event finds the local record by matching `external_id` with `telegram_connected_users.uuid`. The returned `bot_username` is also matched directly against the associated local bot name. Both values use the username without the `@` prefix. The package then sets `is_connected` to `true`. Repeated event delivery is safe and returns the same successful result.

Successful `user.linked` and `test` events return `200` with JSON containing `success`, the event type, and a result message. A `user.linked` response also contains `external_id`, `bot_username`, and the resulting connection status. The `event_id` field is optional and is included in the response when provided.

An invalid payload or unsupported event type returns `400`, an invalid token returns `401`, and a missing local connection or bot mismatch returns `404`. A local database error is converted into a JSON `500` response so Telegga can retry delivery according to its policy. Lookup and processing errors are written to the Laravel log with `external_id`, `bot_username`, and the provided `event_id`, without storing the bearer token.

## Status

The package provides an HTTP client, local management of available bots, a connection model with an explicitly selected bot, connection creation and management, explicit retry of failed requests, all supported message types, message status lookup, user message history, media uploads, groups, member management, broadcasts, and incoming webhooks.

## License

The package is open-sourced software licensed under the MIT license.
