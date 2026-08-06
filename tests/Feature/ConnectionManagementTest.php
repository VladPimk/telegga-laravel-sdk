<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Dto\UserData;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TelegramConnectedUser;

beforeEach(function (): void {
    $this->telegramBot = AvailableTelegramBot::query()->create(['bot_name' => 'mybot']);
});

it('получает пользователя Telegga по uuid локального подключения', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'display_name' => 'Иван',
            'links' => [],
            'groups' => [],
            'new_api_field' => 'new-value',
        ]),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
        ]),
    ]);

    $user = app(TeleggaInterface::class)->getConnection(
        uuid: $connection->uuid,
    );

    expect($user)
        ->toBeInstanceOf(UserData::class)
        ->and($user->user_id)
        ->toBe('telegga-user-1')
        ->and($user->raw()->new_api_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'GET'
            && $request->url() === "https://api.telegga.net/api/v1/users/{$connection->uuid}";
    });

    Http::assertSentCount(1);
});

it('обновляет пользователя Telegga и локальные имя и email', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'email' => 'ivan@example.com',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
        'is_connected' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'display_name' => 'Иван Петров',
            'email' => 'new@example.com',
            'status' => 'disabled',
            'new_api_field' => 'new-value',
        ]),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
        ]),
    ]);

    $user = app(TeleggaInterface::class)->updateConnection(
        uuid: $connection->uuid,
        data: [
            'display_name' => 'Иван Петров',
            'email' => 'new@example.com',
            'status' => 'disabled',
        ],
    );

    $connection->refresh();

    expect($user->raw()->new_api_field)
        ->toBe('new-value')
        ->and($connection->name)
        ->toBe('Иван Петров')
        ->and($connection->email)
        ->toBe('new@example.com')
        ->and($connection->is_connected)
        ->toBeTrue();

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'PATCH'
            && $request->url() === "https://api.telegga.net/api/v1/users/{$connection->uuid}"
            && $request->data() === [
                'display_name' => 'Иван Петров',
                'email' => 'new@example.com',
                'status' => 'disabled',
            ];
    });
});

it('очищает локальный email после успешной очистки в Telegga', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'email' => 'ivan@example.com',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'email' => '',
        ]),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
        ]),
    ]);

    app(TeleggaInterface::class)->updateConnection(
        uuid: $connection->uuid,
        data: ['email' => ''],
    );

    expect($connection->refresh()->email)
        ->toBeNull();
});

it('сохраняет локальный email при обновлении только имени', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'email' => 'ivan@example.com',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'display_name' => 'Иван Петров',
            'email' => null,
        ]),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
        ]),
    ]);

    app(TeleggaInterface::class)->updateConnection(
        uuid: $connection->uuid,
        data: ['display_name' => 'Иван Петров'],
    );

    $connection->refresh();

    expect($connection->name)
        ->toBe('Иван Петров')
        ->and($connection->email)
        ->toBe('ivan@example.com');
});

it('отклоняет невалидный тип email в ответе Telegga', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'email' => 'ivan@example.com',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'email' => ['invalid'],
        ]),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
        ]),
    ]);

    try {
        app(TeleggaInterface::class)->updateConnection(
            uuid: $connection->uuid,
            data: ['email' => 'new@example.com'],
        );
    } catch (ConnectionException $exception) {
        expect($exception->getMessage())
            ->toBe('Telegga returned an invalid user update response: optional string field "email" is invalid.')
            ->and($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('invalid_response');

        return;
    }

    test()->fail('Expected ConnectionException.');
});

it('не отправляет пустое обновление подключения', function (): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->updateConnection(
            uuid: 'connection-uuid',
            data: [],
        );
    } catch (ConnectionException $exception) {
        expect($exception->getMessage())
            ->toBe('Connection update data cannot be empty.')
            ->and($exception->connectionUuid)
            ->toBe('connection-uuid');

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение ConnectionException.');
});

it('останавливает операцию при отсутствии user id в ответе Telegga', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}" => Http::response([
            'external_id' => $connection->uuid,
        ]),
    ]);

    try {
        app(TeleggaInterface::class)->getConnection(
            uuid: $connection->uuid,
        );
    } catch (ConnectionException $exception) {
        expect($exception->getMessage())
            ->toBe('Telegga returned an invalid user response: required string field "user_id" is missing or invalid.')
            ->and($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('invalid_response');

        Http::assertSentCount(1);

        return;
    }

    test()->fail('Ожидалось исключение ConnectionException.');
});

it('выпускает новый код через bot id ожидающей привязки', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}/regenerate-code" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'link_status' => 'pending',
            'link_code' => 'NEWCODE1',
            'link_url' => 'https://t.me/mybot?start=NEWCODE1',
        ]),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
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

    $result = app(TeleggaInterface::class)->regenerateConnectionCode(
        uuid: $connection->uuid,
    );

    expect($result->link_code)
        ->toBe('NEWCODE1');

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'POST'
            && $request->url() === "https://api.telegga.net/api/v1/users/{$connection->uuid}/regenerate-code"
            && $request->data() === ['bot_id' => 'bot-pending'];
    });
});

it('не выпускает код без привязки пользователя к боту', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [],
        ]),
    ]);

    try {
        app(TeleggaInterface::class)->regenerateConnectionCode(
            uuid: $connection->uuid,
        );
    } catch (ConnectionException $exception) {
        expect($exception->getMessage())
            ->toBe('Telegga connection has no bot link.')
            ->and($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeNull();

        Http::assertSentCount(1);

        return;
    }

    test()->fail('Ожидалось исключение ConnectionException.');
});

it('отвязывает пользователя и сбрасывает локальный статус подключения', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
        'is_connected' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}/link*" => Http::response(
            body: null,
            status: 204,
        ),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
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

    app(TeleggaInterface::class)->unlinkConnection(
        uuid: $connection->uuid,
    );

    expect($connection->refresh()->is_connected)
        ->toBeFalse();

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'DELETE'
            && $request->url() === "https://api.telegga.net/api/v1/users/{$connection->uuid}/link?bot_id=bot-active";
    });
});

it('удаляет локальную запись только после удаления пользователя Telegga', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
        'is_connected' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}" => Http::response(
            body: null,
            status: 204,
        ),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
        ]),
    ]);

    app(TeleggaInterface::class)->deleteConnection(
        uuid: $connection->uuid,
    );

    expect(TelegramConnectedUser::query()
        ->where('uuid', $connection->uuid)
        ->exists())
        ->toBeFalse()
        ->and(TelegramConnectedUser::withTrashed()->find($connection->id)?->trashed())
        ->toBeTrue();

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'DELETE'
            && $request->url() === "https://api.telegga.net/api/v1/users/{$connection->uuid}";
    });
});

it('сохраняет локальную запись при ошибке удаления в api', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
        'is_connected' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}" => Http::response([
            'error' => [
                'code' => 'internal',
                'message' => 'Internal error.',
            ],
        ], 500),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
        ]),
    ]);

    try {
        app(TeleggaInterface::class)->deleteConnection(
            uuid: $connection->uuid,
        );
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('internal')
            ->and(TelegramConnectedUser::query()
                ->where('uuid', $connection->uuid)
                ->exists())
            ->toBeTrue();

        return;
    }

    test()->fail('Ожидалось исключение ConnectionException.');
});

it('сбрасывает состояние и пишет критический лог при сбое локального удаления', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
        'is_connected' => true,
    ]);

    TelegramConnectedUser::deleting(
        fn (TelegramConnectedUser $model): never => throw new RuntimeException(
            message: 'Local connection deletion failed.',
        ),
    );
    Log::spy();
    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}" => Http::response(
            body: null,
            status: 204,
        ),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
        ]),
    ]);

    try {
        app(TeleggaInterface::class)->deleteConnection(
            uuid: $connection->uuid,
        );
    } catch (ConnectionException $exception) {
        $connection->refresh();

        expect($exception->getMessage())
            ->toBe('Local Telegga connection could not be deleted after remote deletion.')
            ->and($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(RuntimeException::class)
            ->and($connection->is_created)
            ->toBeFalse()
            ->and($connection->is_connected)
            ->toBeFalse();

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(function (string $message, array $context) use ($connection): bool {
                return $message === 'Telegga connection orphaned: remote user deleted, local record kept.'
                    && $context['connection_uuid'] === $connection->uuid
                    && $context['state_synchronized'] === true
                    && $context['deletion_exception'] instanceof RuntimeException
                    && $context['state_exception'] === null;
            });

        return;
    } finally {
        TelegramConnectedUser::flushEventListeners();
        TelegramConnectedUser::clearBootedModels();
    }

    test()->fail('Ожидалось исключение ConnectionException.');
});

it('завершает локальное удаление если повтор подтвердил отсутствие пользователя в api', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
        'is_connected' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}" => Http::sequence()
            ->push(['error' => ['code' => 'internal', 'message' => 'Temporary error.']], 503)
            ->push(['error' => ['code' => 'not_found', 'message' => 'User was not found.']], 404),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
        ]),
    ]);

    app(TeleggaInterface::class)->deleteConnection(uuid: $connection->uuid);

    expect(TelegramConnectedUser::withTrashed()->find($connection->id)?->trashed())
        ->toBeTrue();

    Http::assertSentCount(2);
});

it('сбрасывает локальный статус если повтор подтвердил отсутствие привязки в api', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
        'is_connected' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}/link*" => Http::sequence()
            ->push(['error' => ['code' => 'internal', 'message' => 'Temporary error.']], 503)
            ->push(['error' => ['code' => 'user_not_linked', 'message' => 'User is not linked.']], 409),
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
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

    app(TeleggaInterface::class)->unlinkConnection(uuid: $connection->uuid);

    expect($connection->refresh()->is_connected)->toBeFalse();

    Http::assertSentCount(3);
});
