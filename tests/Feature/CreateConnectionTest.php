<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Exceptions\BotException;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TelegramConnectedUser;
use Telegga\Laravel\TelegramLinkStatus;
use Telegga\Laravel\TelegramUserStatus;

beforeEach(function (): void {
    Model::preventLazyLoading();

    $this->telegramBot = AvailableTelegramBot::query()->create(['bot_name' => 'mybot']);
});

afterEach(function (): void {
    Model::preventLazyLoading(false);
});

it('creates an independent connection through the selected active bot', function (): void {
    $selectedBot = AvailableTelegramBot::query()->create(['bot_name' => 'second_bot']);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [
                [
                    'bot_id' => 'bot-1',
                    'username' => 'mybot',
                    'status' => 'inactive',
                ],
                [
                    'bot_id' => 'bot-2',
                    'username' => 'second_bot',
                    'status' => 'active',
                ],
                [
                    'bot_id' => 'bot-3',
                    'username' => 'third_bot',
                    'status' => 'active',
                ],
            ],
        ]),
        'api.telegga.net/api/v1/users' => function (Request $request) {
            return Http::response(
                body: [
                    'user_id' => 'telegga-user-1',
                    'external_id' => $request['external_id'],
                    'link_status' => 'pending',
                    'link_code' => '6U828WSH',
                    'link_url' => 'https://t.me/second_bot?start=6U828WSH',
                    'expires_at' => '2099-07-23T15:33:15+01:00',
                ],
                status: 201,
            );
        },
    ]);

    $result = app(TeleggaInterface::class)->createConnection(
        name: 'Иван',
        telegramBotUuid: $selectedBot->uuid,
        email: 'ivan@example.com',
        meta: ['locale' => 'ru'],
        groupId: 'group-1',
    );
    $connection = TelegramConnectedUser::query()->sole();

    expect($result->external_id)
        ->toBe($connection->uuid)
        ->and($result->link_url)
        ->toBe('https://t.me/second_bot?start=6U828WSH')
        ->and($connection->name)
        ->toBe('Иван')
        ->and($connection->email)
        ->toBe('ivan@example.com')
        ->and($connection->user_id)
        ->toBeNull()
        ->and($connection->telegramBot->is($selectedBot))
        ->toBeTrue()
        ->and($connection->status)
        ->toBe(TelegramUserStatus::Active)
        ->and($connection->link_status)
        ->toBe(TelegramLinkStatus::Pending)
        ->and($connection->link_url)
        ->toBe('https://t.me/second_bot?start=6U828WSH')
        ->and($connection->link_expires_at?->getTimestamp())
        ->toBe(strtotime('2099-07-23T15:33:15+01:00'))
        ->and($connection->hasValidLink())
        ->toBeTrue();

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/users'
            && $request->data() === [
                'external_id' => $connection->uuid,
                'bot_id' => 'bot-2',
                'display_name' => 'Иван',
                'email' => 'ivan@example.com',
                'meta' => ['locale' => 'ru'],
                'group_id' => 'group-1',
            ];
    });
});

it('stores an optional application user identifier', function (): void {
    $userId = Schema::getConnection()
        ->table('users')
        ->insertGetId([
            'name' => 'Иван',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [['bot_id' => 'bot-1', 'username' => 'mybot', 'status' => 'active']],
        ]),
        'api.telegga.net/api/v1/users' => function (Request $request) {
            return Http::response([
                'user_id' => 'telegga-user-1',
                'external_id' => $request['external_id'],
                'link_status' => 'pending',
            ], 201);
        },
    ]);

    app(TeleggaInterface::class)->createConnection(
        name: 'Иван',
        telegramBotUuid: $this->telegramBot->uuid,
        userId: $userId,
    );

    expect(TelegramConnectedUser::query()->sole()->user_id)
        ->toBe($userId);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/users'
            && ! array_key_exists('email', $request->data());
    });
});

it('retries user creation after a temporary API error', function (): void {
    $userRequestAttempt = 0;

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [['bot_id' => 'bot-1', 'username' => 'mybot', 'status' => 'active']],
        ]),
        'api.telegga.net/api/v1/users' => function (Request $request) use (&$userRequestAttempt) {
            $userRequestAttempt++;

            if ($userRequestAttempt === 1) {
                return Http::response([
                    'error' => [
                        'code' => 'internal',
                        'message' => 'Temporary error.',
                    ],
                ], 503);
            }

            return Http::response([
                'user_id' => 'telegga-user-1',
                'external_id' => $request->data()['external_id'],
                'link_status' => 'pending',
                'link_code' => '6U828WSH',
                'link_url' => 'https://t.me/mybot?start=6U828WSH',
                'expires_at' => '2099-07-23T15:33:15+01:00',
            ], 201);
        },
    ]);

    $result = app(TeleggaInterface::class)->createConnection(
        name: 'Иван',
        telegramBotUuid: $this->telegramBot->uuid,
        email: 'ivan@example.com',
    );
    $connection = TelegramConnectedUser::query()->sole();
    $userRequests = Http::recorded(
        callback: fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/users',
    )->values();

    expect($result->user_id)
        ->toBe('telegga-user-1')
        ->and($connection->status)
        ->toBe(TelegramUserStatus::Active)
        ->and($connection->link_status)
        ->toBe(TelegramLinkStatus::Pending)
        ->and($connection->link_url)
        ->toBe('https://t.me/mybot?start=6U828WSH')
        ->and($connection->hasValidLink())
        ->toBeTrue()
        ->and(TelegramConnectedUser::query()->count())
        ->toBe(1);

    $this->assertCount(2, $userRequests);
    expect($userRequests[0][0]->data())
        ->toBe($userRequests[1][0]->data());

    Http::assertSentCount(3);
});

it('stores an issued link after retrying and confirming a pending connection', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [['bot_id' => 'bot-1', 'username' => 'mybot', 'status' => 'active']],
        ]),
        'api.telegga.net/api/v1/users' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'link_status' => 'pending',
            'link_code' => 'RETRY001',
            'link_url' => 'https://t.me/mybot?start=RETRY001',
            'expires_at' => '2099-07-23T15:33:15+01:00',
        ], 201),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'status' => 'active',
            'links' => [
                [
                    'bot_id' => 'bot-1',
                    'bot_username' => 'mybot',
                    'status' => 'pending',
                ],
            ],
        ]),
    ]);

    app(TeleggaInterface::class)->retryConnection(uuid: $connection->uuid);
    $connection->refresh();

    expect($connection->status)
        ->toBe(TelegramUserStatus::Active)
        ->and($connection->link_status)
        ->toBe(TelegramLinkStatus::Pending)
        ->and($connection->link_url)
        ->toBe('https://t.me/mybot?start=RETRY001')
        ->and($connection->link_expires_at?->getTimestamp())
        ->toBe(strtotime('2099-07-23T15:33:15+01:00'))
        ->and($connection->hasValidLink())
        ->toBeTrue();
});

it('leaves the local record uncreated after an API error', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [['bot_id' => 'bot-1', 'username' => 'mybot', 'status' => 'active']],
        ]),
        'api.telegga.net/api/v1/users' => Http::response([
            'error' => [
                'code' => 'internal',
                'message' => 'Internal error.',
            ],
        ], 500),
    ]);

    try {
        app(TeleggaInterface::class)->createConnection(
            name: 'Иван',
            telegramBotUuid: $this->telegramBot->uuid,
            email: 'ivan@example.com',
        );
    } catch (ConnectionException $exception) {
        $connection = TelegramConnectedUser::query()->sole();

        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($connection->status)
            ->toBe(TelegramUserStatus::NotCreated)
            ->and($connection->link_status)
            ->toBeNull();

        Http::assertSentCount(4);

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('resends an existing connection with the same UUID', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'email' => 'ivan@example.com',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [['bot_id' => 'bot-1', 'username' => 'mybot', 'status' => 'active']],
        ]),
        'api.telegga.net/api/v1/users' => function (Request $request) {
            return Http::response([
                'user_id' => 'telegga-user-1',
                'external_id' => $request['external_id'],
                'link_status' => 'active',
            ], 200);
        },
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'status' => 'active',
            'links' => [
                [
                    'bot_id' => 'bot-1',
                    'bot_username' => 'mybot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    $result = app(TeleggaInterface::class)->retryConnection(
        uuid: $connection->uuid,
        meta: ['locale' => 'ru'],
        groupId: 'group-1',
    );
    $connection->refresh();

    expect($result->external_id)
        ->toBe($connection->uuid)
        ->and(TelegramConnectedUser::query()->count())
        ->toBe(1)
        ->and($connection->status)
        ->toBe(TelegramUserStatus::Active)
        ->and($connection->link_status)
        ->toBe(TelegramLinkStatus::Active);

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/users'
            && $request['external_id'] === $connection->uuid
            && $request['meta'] === ['locale' => 'ru']
            && $request['group_id'] === 'group-1';
    });
});

it('rejects an empty group identifier before creating a local record', function (): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->createConnection(
            name: 'Иван',
            telegramBotUuid: $this->telegramBot->uuid,
            groupId: '   ',
        );
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBeNull()
            ->and(TelegramConnectedUser::query()->doesntExist())
            ->toBeTrue();

        Http::assertNothingSent();

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('does not retry an already created connection', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->retryConnection(
            uuid: $connection->uuid,
        );
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and(TelegramConnectedUser::query()->count())
            ->toBe(1);

        Http::assertNothingSent();

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('preserves the UUID in an exception when the selected active bot is missing', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [
                [
                    'bot_id' => 'bot-1',
                    'username' => 'mybot',
                    'status' => 'inactive',
                ],
                [
                    'bot_id' => 'bot-2',
                    'username' => 'otherbot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    try {
        app(TeleggaInterface::class)->createConnection(
            name: 'Иван',
            telegramBotUuid: $this->telegramBot->uuid,
        );
    } catch (ConnectionException $exception) {
        $connection = TelegramConnectedUser::query()->sole();

        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getMessage())
            ->toBe('Active Telegram bot is not available in Telegga.')
            ->and($connection->status)
            ->toBe(TelegramUserStatus::NotCreated);

        Http::assertSentCount(1);

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('does not create a connection when the active bot name only partially matches', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [
                [
                    'bot_id' => 'bot-1',
                    'username' => '@mybot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    try {
        app(TeleggaInterface::class)->createConnection(
            name: 'Иван',
            telegramBotUuid: $this->telegramBot->uuid,
        );
    } catch (ConnectionException $exception) {
        $connection = TelegramConnectedUser::query()->sole();

        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($connection->status)
            ->toBe(TelegramUserStatus::NotCreated);

        Http::assertSentCount(1);

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('does not create a local record with an empty name', function (): void {
    try {
        app(TeleggaInterface::class)->createConnection(
            name: '   ',
            telegramBotUuid: $this->telegramBot->uuid,
        );
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBeNull()
            ->and(TelegramConnectedUser::query()->doesntExist())
            ->toBeTrue();

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('does not create a connection for an unknown local bot', function (): void {
    $botUuid = Str::uuid()->toString();
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->createConnection(
            name: 'Иван',
            telegramBotUuid: $botUuid,
        );
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBeNull()
            ->and($exception->getPrevious())
            ->toBeInstanceOf(BotException::class)
            ->and(TelegramConnectedUser::query()->doesntExist())
            ->toBeTrue();

        Http::assertNothingSent();

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('wraps a database error when creating a local record', function (): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->createConnection(
            name: 'Иван',
            telegramBotUuid: $this->telegramBot->uuid,
            userId: 999,
        );
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBeNull()
            ->and($exception->getPrevious())
            ->toBeInstanceOf(QueryException::class)
            ->and(TelegramConnectedUser::query()->doesntExist())
            ->toBeTrue();

        Http::assertNothingSent();

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('wraps a database error when looking up a connection', function (): void {
    $uuid = (string) Str::uuid();

    $this->dropConnectionTable();
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->retryConnection(uuid: $uuid);
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBe($uuid)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(QueryException::class);

        Http::assertNothingSent();

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('rejects a retry for an unknown UUID', function (): void {
    $uuid = (string) Str::uuid();

    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->retryConnection(uuid: $uuid);
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBe($uuid)
            ->and($exception->getPrevious())
            ->toBeNull();

        Http::assertNothingSent();

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('rejects a successful API response with invalid JSON', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [['bot_id' => 'bot-1', 'username' => 'mybot', 'status' => 'active']],
        ]),
        'api.telegga.net/api/v1/users' => Http::response(
            body: 'not-json',
            status: 201,
        ),
    ]);

    try {
        app(TeleggaInterface::class)->createConnection(
            name: 'Иван',
            telegramBotUuid: $this->telegramBot->uuid,
        );
    } catch (ConnectionException $exception) {
        $connection = TelegramConnectedUser::query()->sole();

        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($this->previousApiException(exception: $exception)->apiCode)
            ->toBe('invalid_response')
            ->and($connection->status)
            ->toBe(TelegramUserStatus::NotCreated);

        Http::assertSentCount(2);

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('preserves independent user and link statuses when retrying a disabled Telegga user', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [['bot_id' => 'bot-1', 'username' => 'mybot', 'status' => 'active']],
        ]),
        'api.telegga.net/api/v1/users' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'link_status' => 'active',
        ]),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'status' => 'disabled',
            'links' => [
                [
                    'bot_id' => 'bot-1',
                    'bot_username' => 'mybot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    app(TeleggaInterface::class)->retryConnection(uuid: $connection->uuid);
    $connection->refresh();

    expect($connection->status)
        ->toBe(TelegramUserStatus::Disabled)
        ->and($connection->link_status)
        ->toBe(TelegramLinkStatus::Active);

    Http::assertSentCount(3);
});

it('keeps a connection retryable when remote status confirmation fails', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [['bot_id' => 'bot-1', 'username' => 'mybot', 'status' => 'active']],
        ]),
        'api.telegga.net/api/v1/users' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'link_status' => 'pending',
            'link_code' => 'RETRY002',
            'link_url' => 'https://t.me/mybot?start=RETRY002',
            'expires_at' => '2099-07-23T15:33:15+01:00',
        ]),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'error' => [
                'code' => 'internal',
                'message' => 'Internal error.',
            ],
        ], 500),
    ]);

    try {
        app(TeleggaInterface::class)->retryConnection(uuid: $connection->uuid);
    } catch (ConnectionException $exception) {
        $connection->refresh();

        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($connection->status)
            ->toBe(TelegramUserStatus::NotCreated)
            ->and($connection->link_status)
            ->toBeNull()
            ->and($connection->link_url)
            ->toBe('https://t.me/mybot?start=RETRY002')
            ->and($connection->hasValidLink())
            ->toBeFalse();

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('keeps a retryable state when an issued link expiration is invalid', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [['bot_id' => 'bot-1', 'username' => 'mybot', 'status' => 'active']],
        ]),
        'api.telegga.net/api/v1/users' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'link_status' => 'pending',
            'link_code' => 'RETRY003',
            'link_url' => 'https://t.me/mybot?start=RETRY003',
            'expires_at' => 'invalid-expiration',
        ]),
    ]);

    try {
        app(TeleggaInterface::class)->retryConnection(uuid: $connection->uuid);
    } catch (ConnectionException $exception) {
        $connection->refresh();

        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($connection->status)
            ->toBe(TelegramUserStatus::NotCreated)
            ->and($connection->link_status)
            ->toBeNull()
            ->and($connection->link_url)
            ->toBeNull()
            ->and($connection->hasValidLink())
            ->toBeFalse();

        Http::assertSentCount(2);

        return;
    }

    $this->fail('Expected a ConnectionException.');
});
