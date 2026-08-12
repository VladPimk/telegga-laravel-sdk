<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Dto\GroupData;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\GroupException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TelegramConnectedUser;

beforeEach(function (): void {
    $this->telegramBot = AvailableTelegramBot::query()->create(['bot_name' => 'mybot']);
});

it('creates a group for the local connection bot', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/groups' => Http::response([
            'group_id' => 'group-1',
            'bot_id' => 'bot-pending',
            'name' => 'VIP',
            'description' => 'VIP-клиенты',
            'new_api_field' => 'new-value',
        ], 201),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'status' => 'active',
            'links' => [
                [
                    'bot_id' => 'bot-pending',
                    'bot_username' => 'mybot',
                    'status' => 'pending',
                ],
            ],
        ]),
    ]);

    $group = app(TeleggaInterface::class)->createGroup(
        uuid: $connection->uuid,
        name: 'VIP',
        description: 'VIP-клиенты',
    );

    expect($group->group_id)
        ->toBe('group-1');

    $this->assertSame('new-value', $group->raw()->new_api_field);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/groups'
            && $request->data() === [
                'bot_id' => 'bot-pending',
                'name' => 'VIP',
                'description' => 'VIP-клиенты',
            ];
    });
});

it('returns a group page for the local connection bot', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/groups*' => Http::response([
            'data' => [
                [
                    'group_id' => 'group-1',
                    'name' => 'VIP',
                    'new_api_field' => 'new-value',
                ],
            ],
            'next_cursor' => 'next-page',
            'new_page_field' => 'new-page-value',
        ]),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'status' => 'active',
            'links' => [
                [
                    'bot_id' => 'bot-active',
                    'bot_username' => 'mybot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    $page = app(TeleggaInterface::class)->getGroups(
        uuid: $connection->uuid,
        cursor: ' current-page ',
    );

    expect($page->data)
        ->toHaveCount(1)
        ->and($page->data->first())
        ->toBeInstanceOf(GroupData::class)
        ->and($page->next_cursor)
        ->toBe('next-page');

    $this->assertSame('new-value', $page->data->first()->raw()->new_api_field);
    $this->assertSame('new-page-value', $page->raw()->new_page_field);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.telegga.net/api/v1/groups?bot_id=bot-active&cursor=current-page';
    });
});

it('gets updates and deletes a group', function (): void {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response([
                'group_id' => 'group-1',
                'name' => 'VIP',
                'members_count' => 10,
                'new_api_field' => 'new-value',
            ]);
        }

        if ($request->method() === 'PUT') {
            return Http::response([
                'group_id' => 'group-1',
                'name' => 'Premium',
                'description' => 'Premium-клиенты',
            ]);
        }

        return Http::response(body: null, status: 204);
    });

    $group = app(TeleggaInterface::class)->getGroup(groupId: 'group-1');
    $updated = app(TeleggaInterface::class)->updateGroup(
        groupId: 'group-1',
        data: [
            'name' => 'Premium',
            'description' => 'Premium-клиенты',
        ],
    );
    app(TeleggaInterface::class)->deleteGroup(groupId: 'group-1');

    expect($group->members_count)
        ->toBe(10)
        ->and($updated->name)
        ->toBe('Premium');

    $this->assertSame('new-value', $group->raw()->new_api_field);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'PUT'
            && $request->url() === 'https://api.telegga.net/api/v1/groups/group-1'
            && $request->data() === [
                'name' => 'Premium',
                'description' => 'Premium-клиенты',
            ];
    });

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'DELETE'
            && $request->url() === 'https://api.telegga.net/api/v1/groups/group-1';
    });

    Http::assertSentCount(3);
});

it('manages membership through user endpoints', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($connection) {
        if ($request->method() === 'POST') {
            return Http::response([
                'group_id' => 'group-1',
                'user_id' => 'telegga-user-1',
                'added' => false,
                'new_api_field' => 'new-value',
            ]);
        }

        if ($request->method() === 'DELETE') {
            return Http::response(body: null, status: 204);
        }

        return Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
        ]);
    });

    $result = app(TeleggaInterface::class)->addConnectionToGroup(
        uuid: $connection->uuid,
        groupId: 'group-1',
    );
    app(TeleggaInterface::class)->removeConnectionFromGroup(
        uuid: $connection->uuid,
        groupId: 'group-1',
    );

    expect($result->added)
        ->toBeFalse();

    $this->assertSame('new-value', $result->raw()->new_api_field);

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'POST'
            && $request->url() === "https://api.telegga.net/api/v1/users/{$connection->uuid}/groups"
            && $request->data() === ['group_id' => 'group-1'];
    });

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'DELETE'
            && $request->url() === "https://api.telegga.net/api/v1/users/{$connection->uuid}/groups/group-1";
    });

    Http::assertSentCount(2);
});

it('sends local UUIDs as external_ids in one bulk member request', function (): void {
    $first = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);
    $second = TelegramConnectedUser::query()->create([
        'name' => 'Пётр',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);
    $unselected = TelegramConnectedUser::query()->create([
        'name' => 'Анна',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/groups/group-1/members' => Http::response([
            'added' => 2,
            'not_found' => [$second->uuid],
            'new_api_field' => 'new-value',
        ]),
    ]);

    $result = app(TeleggaInterface::class)->addGroupMembers(
        groupId: 'group-1',
        uuids: [$first->uuid, $second->uuid, $first->uuid],
    );

    expect($result->added)
        ->toBe(2)
        ->and($result->not_found->all())
        ->toBe([$second->uuid]);

    $this->assertSame('new-value', $result->raw()->new_api_field);

    Http::assertSent(function (Request $request) use ($first, $second, $unselected): bool {
        $data = $request->data();

        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/groups/group-1/members'
            && $data === [
                'external_ids' => [
                    $first->uuid,
                    $second->uuid,
                ],
            ]
            && ! in_array($unselected->uuid, $data['external_ids'], true);
    });

    Http::assertSentCount(1);
});

it('accepts a bulk member response without not_found', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/groups/group-1/members' => Http::response([
            'added' => 1,
        ]),
    ]);

    $result = app(TeleggaInterface::class)->addGroupMembers(
        groupId: 'group-1',
        uuids: [$connection->uuid],
    );

    expect($result->added)
        ->toBe(1)
        ->and($result->not_found)
        ->toBeEmpty();

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/groups/group-1/members'
            && $request->data() === ['external_ids' => [$connection->uuid]];
    });

    Http::assertSentCount(1);
});

it('does not add members when a local connection is missing', function (): void {
    $uuid = str()->uuid()->toString();

    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->addGroupMembers(
            groupId: 'group-1',
            uuids: [$uuid],
        );
    } catch (ConnectionException $exception) {
        expect($exception->getMessage())
            ->toBe('Telegga connection was not found.')
            ->and($exception->connectionUuid)
            ->toBe($uuid);

        Http::assertNothingSent();

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('does not add members when a local connection is not created in Telegga', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->addGroupMembers(
            groupId: 'group-1',
            uuids: [$connection->uuid],
        );
    } catch (ConnectionException $exception) {
        expect($exception->getMessage())
            ->toBe('Telegga connection is not created.')
            ->and($exception->connectionUuid)
            ->toBe($connection->uuid);

        Http::assertNothingSent();

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('removes a member through the group endpoint', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/groups/group-1/members/{$connection->uuid}" => Http::response(
            body: null,
            status: 204,
        ),
    ]);

    app(TeleggaInterface::class)->removeGroupMember(
        groupId: 'group-1',
        uuid: $connection->uuid,
    );

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'DELETE'
            && $request->url() === "https://api.telegga.net/api/v1/groups/group-1/members/{$connection->uuid}";
    });

    Http::assertSentCount(1);
});

it('rejects invalid group parameters before an API request', function (
    Closure $action,
    string $message,
): void {
    Http::preventStrayRequests();

    try {
        $action(app(TeleggaInterface::class));
    } catch (GroupException $exception) {
        expect($exception->getMessage())
            ->toBe($message);

        Http::assertNothingSent();

        return;
    }

    $this->fail('Expected a GroupException.');
})->with([
    'empty name' => [
        fn (TeleggaInterface $telegga) => $telegga->createGroup(
            uuid: 'connection-uuid',
            name: '   ',
        ),
        'Group name cannot be empty.',
    ],
    'empty identifier' => [
        fn (TeleggaInterface $telegga) => $telegga->getGroup(groupId: '   '),
        'Group identifier cannot be empty.',
    ],
    'empty update' => [
        fn (TeleggaInterface $telegga) => $telegga->updateGroup(
            groupId: 'group-1',
            data: [],
        ),
        'Group update data cannot be empty.',
    ],
    'empty member list' => [
        fn (TeleggaInterface $telegga) => $telegga->addGroupMembers(
            groupId: 'group-1',
            uuids: [],
        ),
        'Group members cannot be empty.',
    ],
    'member limit exceeded' => [
        fn (TeleggaInterface $telegga) => $telegga->addGroupMembers(
            groupId: 'group-1',
            uuids: array_map(
                fn (int $index): string => "uuid-{$index}",
                range(1, 10001),
            ),
        ),
        'Group members cannot exceed 10000 users.',
    ],
]);

it('wraps an API error in a group exception', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/groups/group-1' => Http::response([
            'error' => [
                'code' => 'not_found',
                'message' => 'Group was not found.',
            ],
        ], 404),
    ]);

    try {
        app(TeleggaInterface::class)->getGroup(groupId: 'group-1');
    } catch (GroupException $exception) {
        expect($exception->groupId)
            ->toBe('group-1')
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($this->previousApiException(exception: $exception)->apiCode)
            ->toBe('not_found')
            ->and($this->previousApiException(exception: $exception)->status)
            ->toBe(404);

        return;
    }

    $this->fail('Expected a GroupException.');
});

it('rejects an invalid group list response', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/groups*' => Http::response([
            'data' => 'not-an-array',
        ]),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'status' => 'active',
            'links' => [
                [
                    'bot_id' => 'bot-active',
                    'bot_username' => 'mybot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    try {
        app(TeleggaInterface::class)->getGroups(
            uuid: $connection->uuid,
        );
    } catch (GroupException $exception) {
        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($this->previousApiException(exception: $exception)->apiCode)
            ->toBe('invalid_response');

        return;
    }

    $this->fail('Expected a GroupException.');
});

it('treats a group as deleted when a retry confirms it is absent from the API', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/groups/group-1' => Http::sequence()
            ->push(['error' => ['code' => 'internal', 'message' => 'Temporary error.']], 503)
            ->push(['error' => ['code' => 'not_found', 'message' => 'Group was not found.']], 404),
    ]);

    app(TeleggaInterface::class)->deleteGroup(groupId: 'group-1');

    Http::assertSentCount(2);
});

it('does not hide a missing group in the first API response', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/groups/group-1' => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'Group was not found.'],
        ], 404),
    ]);

    try {
        app(TeleggaInterface::class)->deleteGroup(groupId: 'group-1');
    } catch (GroupException $exception) {
        expect($exception->apiCode())
            ->toBe('not_found')
            ->and($exception->attempts())
            ->toBe(1)
            ->and($exception->wasRetried())
            ->toBeFalse();

        Http::assertSentCount(1);

        return;
    }

    $this->fail('Expected a GroupException.');
});
