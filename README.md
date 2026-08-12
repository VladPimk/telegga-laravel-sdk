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

By default, an optional connection `user_id` belongs to `App\Models\User` through the `users` table. Applications with a different user model or table must publish the configuration and change both values before running package migrations:

```php
'user_model' => App\Domain\Accounts\Models\User::class,
'users_table' => 'account_users',
```

The configured model and table must represent the same application user entity. The package currently expects its primary key to be an unsigned big integer named `id`. Changing either setting after the package migration has run requires an application migration that updates the existing foreign key.

Configure the Telegga API key and a project-generated token for incoming webhooks:

```dotenv
TELEGGA_API_KEY=tg_live_XXXXXXXXXXXXXXXX
TELEGGA_WEBHOOK_TOKEN=replace-with-a-random-64-character-secret
TELEGGA_WEBHOOK_PREVIOUS_TOKEN=
TELEGGA_WEBHOOKS_ENABLED=true
TELEGGA_WEBHOOKS_PREFIX=webhooks/v1/telegram
TELEGGA_TIMEOUT=15
TELEGGA_CONNECT_TIMEOUT=5
TELEGGA_RETRY_TIMES=3
TELEGGA_RETRY_SLEEP_MS=200
```

The API base URL defaults to `https://api.telegga.net/api/v1` and must use HTTPS. `TELEGGA_TIMEOUT` limits the total request time, while `TELEGGA_CONNECT_TIMEOUT` limits the time spent establishing a connection. `TELEGGA_RETRY_TIMES` is the total number of attempts, including the first request. `TELEGGA_RETRY_SLEEP_MS` is the base delay used for linear backoff.

Generate a strong webhook token with `str()->random(64)` and set the same `TELEGGA_WEBHOOK_TOKEN` value as the webhook bearer token in the Telegga admin panel. `TELEGGA_WEBHOOK_PREVIOUS_TOKEN` is optional and should remain empty outside a token rotation.

## Upgrading from 1.x

Version 2.0 requires PHP 8.3 or later and Laravel 12 or 13. Update the dependency and run the package migrations:

```bash
composer require telegga/laravel-sdk:^2.0
php artisan migrate
```

The 1.x line was distributed for development before the first Packagist publication. Version 2.0 amends its original package migrations. An application that installed 1.x directly from GitHub must either recreate the package tables in a development environment or provide application-owned upgrade migrations that preserve its existing data before deploying 2.0.

Review these public API changes before upgrading:

- successful JSON responses are returned as typed DTOs whose properties match the Telegga `snake_case` fields;
- `uploadMedia()` accepts `contents` and `filename` instead of a filesystem path;
- `getMessages()` requires explicit `from` and `to` values;
- `getGroups()` returns `GroupPageData` with `data` and `next_cursor`;
- `startBroadcast()` requires an explicit `BroadcastAudience`;
- `user.linked` webhooks require the documented `event_id` and are persisted for idempotent processing;
- the webhook route accepts JSON payloads only.

If the package configuration was published previously, compare it with the current [`config/telegga.php`](config/telegga.php), especially the retry, webhook route, token rotation, application user model, and application users table settings. Existing application overrides are preserved, so newly introduced settings otherwise use their package defaults.

## API response DTOs

Public methods return typed response DTOs instead of unstructured objects. DTO property names intentionally match the Telegga JSON fields in `snake_case`, so the package contract remains transparent:

```php
$message = $telegga->getMessage(messageId: $messageId);

$status = $message->status;
$telegramMessageId = $message->telegram_message_id;
```

Required documented fields are validated by route-specific response mappers. A successful HTTP response with a missing required field or an invalid field type throws `TeleggaApiException` with the `invalid_response` API code. Optional fields are exposed as nullable properties.

Nested response arrays are returned as typed Laravel collections. For example, `UserData::$links` contains `UserLinkData` objects and `MessageData::$delivery_attempts` contains `DeliveryAttemptData` objects. Paginated responses use `UserPageData`, `MessagePageData`, or `GroupPageData`, with a typed `data` collection and nullable `next_cursor`.

Every DTO retains the original API object. Newly added API fields remain available without an immediate package update:

```php
$newValue = $message->raw()->new_api_field ?? null;
```

Request payloads remain open arrays where the API supports different message or broadcast fields. DTO mapping applies only to responses.

## Available Telegram bots

Before creating connections, register a bot that is available to the Telegga service:

```php
$bot = $telegga->addTelegramBot(
    botName: 'auctiongate_notification_bot',
);
```

The package accepts and stores the username without the `@` prefix, matching the format returned by the API. Local and API usernames are converted to lowercase before comparison, and the local value is stored in lowercase. The package does not store `bot_id` or any other bot data returned by the API. The model `uuid` is generated locally.

The remote `GET /bots` response is cached through the application's Laravel cache store for 10 minutes. The cache contains only the validated raw response array; package DTOs and collections are created by `BotResponseMapper` after every cache read and are never serialized into the cache. This keeps cached data independent of PHP class changes during rolling deployments. The versioned cache key is scoped by a SHA-256 hash of the API base URL and API key, so bot lists from different Telegga services are not mixed and the API key is not exposed in the key. API errors and invalid responses are not cached. Changes to bot access or status can therefore take up to 10 minutes to become visible to ordinary reads. `addTelegramBot()` clears the cache before validating the requested bot, and a successful `deleteTelegramBot()` invalidates it for the next read.

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

When `link_status` is `pending`, the returned `ConnectionData` exposes `link_url`, `link_code`, and `expires_at`. If the user is already connected, Telegga returns `link_status: active` without issuing a new code, so these nullable fields may be `null`.

```php
if (($result->link_status ?? null) === 'pending') {
    $linkUrl = $result->link_url ?? null;
    $expiresAt = $result->expires_at ?? null;
}
```

The `meta` and `groupId` parameters are optional. The package sends them as `meta` and `group_id` in the `POST /users` request. These values are not stored locally.

The local `TelegramConnectedUser` model stores two independent enum-cast fields. `status` describes the Telegga user and uses `not_created`, `active`, or `disabled`. `link_status` describes only the selected bot link and uses `pending`, `active`, `blocked`, `revoked`, or `null`. A `null` value means that remote creation has not been confirmed or the latest exact API response contains no link for the selected local bot. A disabled user may therefore retain an active bot link, and an active user may have a revoked or blocked link.

When Telegga issues a connection code, the package stores its `link_url` and RFC 3339 expiration time locally as `link_url` and `link_expires_at`. The expiration is cast to an immutable date-time object. Use `$connection->hasValidLink()` to verify that the local record is not deleted, the remote user exists, and the link is present, still pending, and has not expired. Exact user lookups preserve an existing pending link because Telegga only returns the URL in create and regenerate-code responses. The package clears the stored URL and expiration when the selected link becomes active, blocked, revoked, missing, or the remote user is not found.

## Automatic HTTP retries

The package automatically retries transport failures and HTTP `408`, `429`, and `5xx` responses only for operations that are safe to repeat. By default, a request is attempted up to three times with delays of 200 ms and 400 ms. A numeric `Retry-After` header of up to five seconds on a `429` response takes precedence when it requires a longer delay.

Retry delays are synchronous: the current PHP process waits before sending the next attempt. If a numeric `Retry-After` value exceeds five seconds, the package does not wait or retry and throws the mapped `TeleggaApiException` with the original `retryAfter` value. A non-numeric `Retry-After` value is ignored and the configured linear delay is used. The package does not add jitter.

All `GET` requests are retried. The following modifying operations are explicitly marked as idempotent and are also retried:

- creating or updating a user through `POST /users`;
- updating or deleting a user;
- unlinking a user;
- adding a user to a group;
- updating or deleting a group;
- bulk-adding group members.

Message sending, broadcasts, broadcast cancellation, media uploads, group creation, connection-code regeneration, and membership removal are never retried automatically because the API does not provide an idempotency key or an equally strong replay guarantee for those operations.

If a retried user or group deletion ends with `404 not_found`, the package treats the remote object as already deleted and completes the local operation. If a retried unlink ends with `409 user_not_linked`, the local `link_status` is set to `revoked`. The same `404` or `409` received on the first attempt remains an exception, so invalid identifiers are not silently accepted.

For group deletion, this means that a direct `404 not_found` is reported as an error, while `503` followed by `404 not_found` is accepted as a completed deletion. The API does not provide an idempotency key that would allow the package to distinguish a lost successful response from an object that was already absent.

## Retrying a connection explicitly

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

This method is separate from HTTP retries. It repeats the business operation for a local connection whose `status` is still `not_created`. After `POST /users`, the package performs an exact user lookup and stores the confirmed user `status` and selected bot `link_status` independently. If confirmation fails, the local state remains retryable. If `meta` or `groupId` were used during the first business attempt, pass them again.

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

All connection operations accept the UUID of the local record. For user routes that accept either identifier, the package sends this UUID directly as Telegga's `external_id` without first resolving the internal `user_id`. The package compares the `bot_username` returned by Telegga with the local `bot_name` after converting both values to lowercase. Both values use the username without the `@` prefix. Internal Telegga user and bot identifiers are not stored locally.

When an exact Telegga user lookup returns `404 not_found`, the package synchronizes the local record to `status: not_created` and `link_status: null` before returning `ConnectionException`. Other API errors leave the local statuses unchanged. The local UUID can then be passed to `retryConnection()` to recreate the missing remote user.

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

The package resolves the local bot UUID to the internal `bot_id`. The method returns `UserPageData`; its `data` field is a collection of `UserData` objects, while a missing `next_cursor` is normalized to `null`.

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

After a successful API response, `display_name`, `email`, and the Telegga user `status` are synchronized locally. An empty `email` string clears the local value. Updating the user status does not overwrite the independent bot `link_status`.

Explicitly generate a new code for an existing link:

```php
$result = $telegga->regenerateConnectionCode(uuid: $uuid);
```

Unlinking a user from the bot preserves the local record and user `status`, and sets `link_status` to `revoked`:

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

The method returns `QueuedMessageData` with `message_id`, `status`, and nullable `created_at`. Messages and their statuses are not stored locally.

## Message status

Request the delivery status using the `message_id` returned when the message was queued:

```php
$message = $telegga->getMessage(
    messageId: $messageId,
);
```

The method returns `MessageData` with the status, attempt count, delivery timestamps, and a collection of `DeliveryAttemptData` objects in `delivery_attempts`.

## User message history

Message history is always requested through a local connection UUID. After checking the local connection, the package passes this UUID directly to `GET /messages` as the supported external value of `user_id`, without an additional Telegga user lookup:

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

The method returns `MessagePageData`. Its `data` field is a `Collection` of `MessageData` objects, and `next_cursor` contains the next page cursor or `null`. Unknown API fields remain available through each DTO's `raw()` method. The public interface does not support retrieving the full service message history without specifying a local connection.

## Media files

Upload a file by passing its binary contents and filename. The package sends the contents directly to Telegga as multipart data and never receives or resolves a project filesystem path:

```php
$media = $telegga->uploadMedia(
    contents: $uploadedFile->getContent(),
    filename: $uploadedFile->getClientOriginalName(),
);

$mediaId = $media->media_id;
```

Request metadata for an uploaded file using its `media_id`:

```php
$metadata = $telegga->getMedia(
    mediaId: $mediaId,
);
```

Both methods return `MediaData`. Empty contents, empty filenames, and payloads larger than the API-wide 50 MB limit are rejected before an HTTP request is sent. Telegga determines the media type from its contents and applies the type-specific limit, including the 10 MB photo limit. Neither the file, its path, nor its `media_id` is stored locally.

## Groups

A group is created for the bot associated with a local connection. The package resolves `bot_id` automatically:

```php
$group = $telegga->createGroup(
    uuid: $connectionUuid,
    name: 'VIP',
    description: 'VIP customers',
);

$page = $telegga->getGroups(
    uuid: $connectionUuid,
    cursor: $cursor,
);

foreach ($page->data as $group) {
    $groupId = $group->group_id;
}

$nextCursor = $page->next_cursor;
```

`cursor` is optional. The method returns `GroupPageData`; pass its `next_cursor` value to retrieve the next page. A missing or empty `next_cursor` is normalized to `null`.

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

Group member routes accept local UUIDs and pass them directly to Telegga as supported `external_id` values:

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

`addGroupMembers()` processes only the UUIDs explicitly passed to the method; it never selects every local connection. Duplicate UUIDs are removed, and the requested connections are checked with one local database query before any HTTP request. The package then sends one `POST /groups/{id}/members` request with the UUIDs in `external_ids`. The API accepts up to 10,000 identifiers per request.

Membership additions are state-idempotent, but their response counters describe the final attempt. If the first request added members and its response was lost, the repeated request can return `added: false` for one member or `added: 0` for a bulk operation because the requested memberships already exist. The resulting group membership is correct, but the returned counter cannot reconstruct changes made by the lost attempt.

`GroupMembersAddedData::$not_found` is a collection of `external_id` values for which Telegga did not find a user. An empty collection means that every requested identifier was resolved.

Groups and memberships are not stored locally. Group responses use `GroupData`, single membership additions use `UserGroupMembershipData`, and bulk additions use `GroupMembersAddedData`.

## Broadcasts

Import the explicit broadcast audience value object:

```php
use Telegga\Laravel\BroadcastAudience;
```

Start a broadcast to all connected users of a bot using a local connection UUID:

```php
$broadcast = $telegga->startBroadcast(
    viaConnectionUuid: $connectionUuid,
    type: 'text',
    audience: BroadcastAudience::allLinkedUsers(),
    data: [
        'text' => 'Special offer!',
    ],
);
```

To limit recipients to group members, select a group audience:

```php
$broadcast = $telegga->startBroadcast(
    viaConnectionUuid: $connectionUuid,
    type: 'photo',
    audience: BroadcastAudience::group(groupId: $groupId),
    data: [
        'media_id' => $mediaId,
        'text' => 'New special offer',
    ],
);
```

The audience must always be selected explicitly. Omitting it throws `BroadcastException` before any API request. The connection UUID is used only to resolve the bot and is not a broadcast recipient.

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

Broadcasts and their progress are not stored locally. Starting, reading, and cancelling broadcasts return `BroadcastCreatedData`, `BroadcastData`, and `BroadcastCancellationData` respectively.

## Incoming webhooks

The package registers this route by default:

```text
POST /webhooks/v1/telegram/connect-account
```

Set the application base URL in the Telegga admin panel. Telegga appends the webhook path automatically.

Set `TELEGGA_WEBHOOKS_ENABLED=false` to disable route registration for applications that only use outgoing API requests. The `TELEGGA_WEBHOOKS_PREFIX` value changes the route prefix and defaults to `webhooks/v1/telegram`. When changing it, make sure that the webhook address configured in Telegga resolves to the resulting route.

The route uses `throttle:60,1` before token validation by default. Publish `config/telegga.php` to replace the rate limit or append application middleware in `telegga.webhooks.middleware`. `VerifyWebhookToken` is always appended by the package and cannot be removed through configuration.

Every request must contain the project token:

```http
Authorization: Bearer <TELEGGA_WEBHOOK_TOKEN>
```

The package accepts either a string or an array in `telegga.webhook_token`. The default configuration builds the array from `TELEGGA_WEBHOOK_TOKEN` and the optional `TELEGGA_WEBHOOK_PREVIOUS_TOKEN`. Empty and non-string entries are ignored, and every valid configured value is compared securely using `hash_equals`. An empty, missing, or invalid token returns `401`. Rejected tokens are logged at `warning` level with the request path and source IP, but token values are never logged. Requests rejected by the rate limiter return `429` before token validation.

To rotate a webhook token without losing events, deploy the new token as `TELEGGA_WEBHOOK_TOKEN` while retaining the old value in `TELEGGA_WEBHOOK_PREVIOUS_TOKEN`. Clear and rebuild the application configuration cache, then change the bearer token in the Telegga admin panel. Both values remain valid during the transition. After all application instances and Telegga use the new token, remove `TELEGGA_WEBHOOK_PREVIOUS_TOKEN` and rebuild the configuration cache again. A compromised token should not be retained for a transition period.

The `user.linked` event requires every field documented by Telegga, including `event_id`. It finds the local record by matching `external_id` with `telegram_connected_users.uuid`, including soft-deleted records for accurate diagnostics, and loads its assigned local bot, including a soft-deleted bot. The received and local bot usernames are converted to lowercase before comparison. Both values use the username without the `@` prefix. Processing stops at the first failed check.

After the connection and event identity checks succeed, the package stores the event in `telegga_webhook_events` and relates it to the local connection before validating the assigned bot. This preserves retryable bot validation failures and processing failures as unprocessed events. `attempts` counts deliveries of the same accepted `event_id`, while `first_seen_at` records its first delivery. The locally generated event `uuid` is separate from Telegga's globally unique `event_id`.

When bot validation succeeds, the authenticated `user.linked` event is treated as authoritative confirmation that the Telegga user exists and the selected bot link is active. A database transaction sets `link_status` to `active`, changes `status` from `not_created` to `active` when necessary, and records `processed_at`. An existing `disabled` user status is preserved because user activation and bot linking are independent API states. A later delivery can finish an event that was stored but not processed.

Repeated delivery of a processed `event_id` for the same connection increments `attempts`, returns `200`, and does not load the bot or update the connection again. An unprocessed stored event can complete on a later delivery. Reusing an `event_id` for another connection or event type returns `409 event_id_conflict`. The database unique constraint on `event_id` and row locking prevent concurrent delivery from applying the same event twice.

Successful `user.linked` and `test` events return `200` with JSON containing `success`, the event type, and a result message. A `user.linked` response also contains its required `event_id`, `external_id`, `bot_username`, and the resulting independent `status` and `link_status`. The `test` event has no `event_id` and is not stored.

Webhook processing uses the following error codes:

| HTTP status | Error code | Meaning |
| --- | --- | --- |
| `400` | `invalid_request` | Required event data is missing or invalid. |
| `400` | `unsupported_event` | The event type is not supported. |
| `401` | `unauthorized` | The webhook token is missing or invalid. |
| `404` | `connection_not_found` | No local connection exists for the provided `external_id`. |
| `404` | `connection_deleted` | The local connection was soft-deleted. |
| `409` | `event_id_conflict` | The `event_id` is already assigned to another connection or event type. |
| `503` | `bot_not_found` | The bot assigned to the connection no longer exists locally; Telegga should retry. |
| `503` | `bot_deleted` | The bot assigned to the connection was soft-deleted; Telegga should retry. |
| `503` | `bot_mismatch` | The webhook contains a different bot username; Telegga should retry. |
| `200` | `retry_window_expired` | A bot validation failure remained unresolved for six hours and the event was acknowledged without applying it. |
| `500` | `internal` | A local database operation failed. |

Missing and deleted connections return terminal `404` responses because retrying cannot recreate a local connection. A conflicting `event_id` returns terminal `409`. Missing, deleted, or mismatched bots return `503` so Telegga can retry after a recoverable local configuration problem or bot rename. These deliveries are stored with `processed_at = null`, and each delivery increments `attempts`.

The retry window is measured from the documented `linked_at` value. If a bot validation failure remains unresolved for six hours, the package returns HTTP `200` with `success: false`, the `retry_window_expired` error code, and the original failure code in `error.details.failure_code`. This acknowledgement stops further Telegga retries without incorrectly marking the connection or event as processed. The exhausted failure is logged at `error` level. Other expected request failures are logged at `warning` level. Database and processing failures return `500` and are logged at `error` level so Telegga can retry delivery according to its policy. Logs contain the event identifiers, normalized bot usernames, and error codes, but never contain the bearer token.

### Clearing the webhook event log

Delete webhook event records whose `created_at` value is older than 90 days:

```bash
php artisan telegga:webhook-events:clear
```

Pass a positive number of days to use a different retention period:

```bash
php artisan telegga:webhook-events:clear 30
```

Records exactly on the retention boundary are preserved. Matching records are deleted in batches of up to 1,000. The command reports the total number of deleted records and rejects zero, negative, fractional, and non-numeric values. Successful execution is logged at `info` level with the retention period and deletion count. Invalid arguments are logged at `warning` level, and database failures are logged at `error` level with the number of records deleted before the failure.

## Development

Run all local quality checks with one command:

```bash
composer check
```

This checks formatting with Pint, analyses `src` with Larastan at level 6, and runs the Pest test suite. GitHub Actions repeats these checks for every push to `main` and `hotfix/**`, every pull request targeting `main`, and every manual workflow run. The test matrix covers PHP 8.3 and 8.4 with Laravel 12 and 13. A separate MySQL 8.4 job complements the default SQLite test run.

## Status

The package provides an HTTP client, local management of available bots, a connection model with an explicitly selected bot, connection creation and management, explicit retry of failed requests, all supported message types, message status lookup, user message history, media uploads, groups, member management, broadcasts, and incoming webhooks.

## License

The package is open-sourced software licensed under the MIT license.
