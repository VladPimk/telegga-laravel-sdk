<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Exceptions\GroupException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TelegramConnectedUser;

beforeEach(function (): void {
    Schema::enableForeignKeyConstraints();

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $botMigration = require __DIR__.'/../../database/migrations/2026_07_31_000001_create_available_telegram_bots_table.php';
    $botMigration->up();

    $connectionMigration = require __DIR__.'/../../database/migrations/2026_07_31_000002_create_telegram_connected_users_table.php';
    $connectionMigration->up();

    $this->telegramBot = AvailableTelegramBot::query()->create(['bot_name' => 'mybot']);
});

afterEach(function (): void {
    Schema::dropIfExists('telegram_connected_users');
    Schema::dropIfExists('available_telegram_bots');
    Schema::dropIfExists('users');
});

it('создаёт группу для бота локального подключения', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
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
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
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

    expect($group)
        ->toBeInstanceOf(stdClass::class)
        ->and($group->group_id)
        ->toBe('group-1')
        ->and($group->new_api_field)
        ->toBe('new-value');

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

it('возвращает коллекцию групп бота локального подключения', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
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
        ]),
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [
                [
                    'bot_id' => 'bot-active',
                    'bot_username' => 'mybot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    $groups = app(TeleggaInterface::class)->getGroups(
        uuid: $connection->uuid,
    );

    expect($groups)
        ->toBeInstanceOf(Collection::class)
        ->and($groups)
        ->toHaveCount(1)
        ->and($groups->first())
        ->toBeInstanceOf(stdClass::class)
        ->and($groups->first()->new_api_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.telegga.net/api/v1/groups?bot_id=bot-active';
    });
});

it('получает обновляет и удаляет группу', function (): void {
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
        ->and($group->new_api_field)
        ->toBe('new-value')
        ->and($updated->name)
        ->toBe('Premium');

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

it('управляет членством через маршруты пользователя', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
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
        ->toBeFalse()
        ->and($result->new_api_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/users/telegga-user-1/groups'
            && $request->data() === ['group_id' => 'group-1'];
    });

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'DELETE'
            && $request->url() === 'https://api.telegga.net/api/v1/users/telegga-user-1/groups/group-1';
    });

    Http::assertSentCount(4);
});

it('преобразует локальные uuid при массовом добавлении участников', function (): void {
    $first = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);
    $second = TelegramConnectedUser::query()->create([
        'name' => 'Пётр',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/groups/group-1/members' => Http::response([
            'added' => 2,
            'new_api_field' => 'new-value',
        ]),
        "api.telegga.net/api/v1/users?external_id={$first->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $first->uuid,
        ]),
        "api.telegga.net/api/v1/users?external_id={$second->uuid}" => Http::response([
            'user_id' => 'telegga-user-2',
            'external_id' => $second->uuid,
        ]),
    ]);

    $result = app(TeleggaInterface::class)->addGroupMembers(
        groupId: 'group-1',
        uuids: [$first->uuid, $second->uuid, $first->uuid],
    );

    expect($result->added)
        ->toBe(2)
        ->and($result->new_api_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/groups/group-1/members'
            && $request->data() === [
                'user_ids' => [
                    'telegga-user-1',
                    'telegga-user-2',
                ],
            ];
    });

    Http::assertSentCount(3);
});

it('удаляет участника через групповой маршрут', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/groups/group-1/members/telegga-user-1' => Http::response(
            body: null,
            status: 204,
        ),
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
        ]),
    ]);

    app(TeleggaInterface::class)->removeGroupMember(
        groupId: 'group-1',
        uuid: $connection->uuid,
    );

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'DELETE'
            && $request->url() === 'https://api.telegga.net/api/v1/groups/group-1/members/telegga-user-1';
    });
});

it('отклоняет некорректные параметры группы до api запроса', function (
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

    test()->fail('Ожидалось исключение GroupException.');
})->with([
    'пустое имя' => [
        fn (TeleggaInterface $telegga) => $telegga->createGroup(
            uuid: 'connection-uuid',
            name: '   ',
        ),
        'Group name cannot be empty.',
    ],
    'пустой идентификатор' => [
        fn (TeleggaInterface $telegga) => $telegga->getGroup(groupId: '   '),
        'Group identifier cannot be empty.',
    ],
    'пустое обновление' => [
        fn (TeleggaInterface $telegga) => $telegga->updateGroup(
            groupId: 'group-1',
            data: [],
        ),
        'Group update data cannot be empty.',
    ],
    'пустой список участников' => [
        fn (TeleggaInterface $telegga) => $telegga->addGroupMembers(
            groupId: 'group-1',
            uuids: [],
        ),
        'Group members cannot be empty.',
    ],
    'превышен лимит участников' => [
        fn (TeleggaInterface $telegga) => $telegga->addGroupMembers(
            groupId: 'group-1',
            uuids: array_map(
                callback: fn (int $index): string => "uuid-{$index}",
                array: range(start: 1, end: 10001),
            ),
        ),
        'Group members cannot exceed 10000 users.',
    ],
]);

it('скрывает ошибку api в исключении группы', function (): void {
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
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('not_found')
            ->and($exception->getPrevious()?->status)
            ->toBe(404);

        return;
    }

    test()->fail('Ожидалось исключение GroupException.');
});

it('отклоняет некорректный ответ списка групп', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/groups*' => Http::response([
            'data' => 'not-an-array',
        ]),
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
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
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('invalid_response');

        return;
    }

    test()->fail('Ожидалось исключение GroupException.');
});
