<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Telegga\Laravel\Dto\DeliveryAttemptData;
use Telegga\Laravel\Dto\GroupPageData;
use Telegga\Laravel\Dto\MessageData;
use Telegga\Laravel\Dto\UserData;
use Telegga\Laravel\Dto\UserGroupData;
use Telegga\Laravel\Dto\UserLinkData;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Mappers\BotResponseMapper;
use Telegga\Laravel\Mappers\GroupResponseMapper;
use Telegga\Laravel\Mappers\MessageResponseMapper;
use Telegga\Laravel\Mappers\UserResponseMapper;

it('preserves unknown response fields in the raw DTO object', function (): void {
    $response = (object) [
        'data' => [
            (object) [
                'bot_id' => 'bot-1',
                'username' => 'mybot',
                'display_name' => 'My Bot',
                'status' => 'active',
                'new_api_field' => 'new-value',
            ],
        ],
    ];

    $bots = app(BotResponseMapper::class)->fromList(response: $response);

    expect($bots)
        ->toBeInstanceOf(Collection::class)
        ->and($bots)->toHaveCount(1)
        ->and($bots->first()?->bot_id)->toBe('bot-1')
        ->and($bots->first()?->raw()->new_api_field)->toBe('new-value');
});

it('maps missing optional connection fields to null', function (): void {
    $response = (object) [
        'user_id' => 'telegga-user-1',
        'external_id' => 'connection-uuid',
        'link_status' => 'active',
    ];

    $connection = app(UserResponseMapper::class)->fromCreate(response: $response);

    expect($connection->link_code)
        ->toBeNull()
        ->and($connection->link_url)->toBeNull()
        ->and($connection->expires_at)->toBeNull()
        ->and($connection->group_id)->toBeNull()
        ->and($connection->raw())->toBe($response);
});

it('maps nested user links and groups to DTOs', function (): void {
    $response = (object) [
        'user_id' => 'telegga-user-1',
        'external_id' => 'connection-uuid',
        'links' => [
            (object) [
                'bot_id' => 'bot-1',
                'bot_username' => 'mybot',
                'status' => 'active',
                'linked_at' => '2026-07-20T15:35:00+01:00',
            ],
        ],
        'groups' => [
            (object) [
                'group_id' => 'group-1',
                'name' => 'VIP',
                'bot_id' => 'bot-1',
            ],
        ],
    ];

    $user = app(UserResponseMapper::class)->fromGet(response: $response);

    expect($user)
        ->toBeInstanceOf(UserData::class)
        ->and($user->links)->toBeInstanceOf(Collection::class)
        ->and($user->links->first())->toBeInstanceOf(UserLinkData::class)
        ->and($user->links->first()?->bot_username)->toBe('mybot')
        ->and($user->groups)->toBeInstanceOf(Collection::class)
        ->and($user->groups->first())->toBeInstanceOf(UserGroupData::class)
        ->and($user->groups->first()?->name)->toBe('VIP');
});

it('maps message delivery attempts to nested DTOs', function (): void {
    $response = (object) [
        'message_id' => 'message-1',
        'status' => 'sent',
        'delivery_attempts' => [
            (object) [
                'at' => '2026-07-20T15:35:00+01:00',
                'ok' => true,
                'latency_ms' => 42,
            ],
        ],
    ];

    $message = app(MessageResponseMapper::class)->fromGet(response: $response);

    expect($message)
        ->toBeInstanceOf(MessageData::class)
        ->and($message->delivery_attempts)->toBeInstanceOf(Collection::class)
        ->and($message->delivery_attempts->first())->toBeInstanceOf(DeliveryAttemptData::class)
        ->and($message->delivery_attempts->first()?->latency_ms)->toBe(42);
});

it('normalizes an empty group page cursor to null', function (): void {
    $response = (object) [
        'data' => [],
        'next_cursor' => '',
    ];

    $page = app(GroupResponseMapper::class)->fromList(response: $response);

    expect($page)
        ->toBeInstanceOf(GroupPageData::class)
        ->and($page->data)->toBeInstanceOf(Collection::class)
        ->and($page->data)->toBeEmpty()
        ->and($page->next_cursor)->toBeNull()
        ->and($page->raw())->toBe($response);
});

it('rejects an invalid group page cursor', function (): void {
    expect(fn () => app(GroupResponseMapper::class)->fromList(response: (object) [
        'data' => [],
        'next_cursor' => ['invalid'],
    ]))->toThrow(
        TeleggaApiException::class,
        'Telegga returned an invalid group list response: optional string field "next_cursor" is invalid.',
    );
});

it('rejects a non-string external id in a group member result', function (): void {
    expect(fn () => app(GroupResponseMapper::class)->fromAddMembers(response: (object) [
        'added' => 1,
        'not_found' => [123],
    ]))->toThrow(
        TeleggaApiException::class,
        'Telegga returned an invalid group members response: optional string array field "not_found" is invalid.',
    );
});

it('maps an absent or null not_found field to an empty collection', function (object $response): void {
    $result = app(GroupResponseMapper::class)->fromAddMembers(response: $response);

    expect($result->added)
        ->toBe(2)
        ->and($result->not_found)
        ->toBeInstanceOf(Collection::class)
        ->toBeEmpty()
        ->and($result->raw())
        ->toBe($response);
})->with([
    'absent field' => [(object) ['added' => 2]],
    'null field' => [(object) ['added' => 2, 'not_found' => null]],
]);

it('rejects a response without a required field', function (): void {
    try {
        app(MessageResponseMapper::class)->fromSend(response: (object) [
            'status' => 'queued',
        ]);
    } catch (TeleggaApiException $exception) {
        expect($exception->getMessage())
            ->toBe('Telegga returned an invalid message creation response: required string field "message_id" is missing or invalid.')
            ->and($exception->status)->toBe(0)
            ->and($exception->apiCode)->toBe('invalid_response');

        return;
    }

    test()->fail('Expected a TeleggaApiException.');
});

it('rejects an invalid optional field type', function (): void {
    expect(fn () => app(UserResponseMapper::class)->fromGet(response: (object) [
        'user_id' => 'telegga-user-1',
        'external_id' => 'connection-uuid',
        'email' => ['invalid'],
    ]))->toThrow(
        TeleggaApiException::class,
        'Telegga returned an invalid user response: optional string field "email" is invalid.',
    );
});
