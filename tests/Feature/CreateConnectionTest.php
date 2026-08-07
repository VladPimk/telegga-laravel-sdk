<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Dto\ConnectionData;
use Telegga\Laravel\Exceptions\BotException;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TelegramConnectedUser;

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

    expect($result)
        ->toBeInstanceOf(ConnectionData::class)
        ->and($result->external_id)
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
        ->and($connection->is_created)
        ->toBeTrue()
        ->and($connection->is_connected)
        ->toBeFalse();

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
        ->and($connection->is_created)
        ->toBeTrue()
        ->and($connection->is_connected)
        ->toBeFalse()
        ->and(TelegramConnectedUser::query()->count())
        ->toBe(1)
        ->and($userRequests)
        ->toHaveCount(2)
        ->and($userRequests[0][0]->data())
        ->toBe($userRequests[1][0]->data());

    Http::assertSentCount(3);
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
            ->and($connection->is_created)
            ->toBeFalse()
            ->and($connection->is_connected)
            ->toBeFalse();

        Http::assertSentCount(4);

        return;
    }

    test()->fail('Expected a ConnectionException.');
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
        ->and($connection->is_created)
        ->toBeTrue()
        ->and($connection->is_connected)
        ->toBeTrue();

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

    test()->fail('Expected a ConnectionException.');
});

it('does not retry an already created connection', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
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

    test()->fail('Expected a ConnectionException.');
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
            ->and($connection->is_created)
            ->toBeFalse();

        Http::assertSentCount(1);

        return;
    }

    test()->fail('Expected a ConnectionException.');
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
            ->and($connection->is_created)
            ->toBeFalse();

        Http::assertSentCount(1);

        return;
    }

    test()->fail('Expected a ConnectionException.');
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

    test()->fail('Expected a ConnectionException.');
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

    test()->fail('Expected a ConnectionException.');
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

    test()->fail('Expected a ConnectionException.');
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

    test()->fail('Expected a ConnectionException.');
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

    test()->fail('Expected a ConnectionException.');
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
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('invalid_response')
            ->and($connection->is_created)
            ->toBeFalse();

        Http::assertSentCount(2);

        return;
    }

    test()->fail('Expected a ConnectionException.');
});
