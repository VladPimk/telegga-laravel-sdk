<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TelegramConnectedUser;
use Telegga\Laravel\TelegramLinkStatus;
use Telegga\Laravel\TelegramUserStatus;

beforeEach(function (): void {
    $this->telegramBot = AvailableTelegramBot::query()->create(['bot_name' => 'mybot']);
});

it('gets a Telegga user by local connection UUID', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'status' => 'active',
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

    expect($user->user_id)
        ->toBe('telegga-user-1');

    $this->assertSame('new-value', $user->raw()->new_api_field);

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'GET'
            && $request->url() === "https://api.telegga.net/api/v1/users/{$connection->uuid}";
    });

    Http::assertSentCount(1);
});

it('marks a local connection as not created when the Telegga user is missing', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'link_status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}" => Http::response([
            'error' => [
                'code' => 'not_found',
                'message' => 'User was not found.',
            ],
        ], 404),
    ]);

    try {
        app(TeleggaInterface::class)->getConnection(uuid: $connection->uuid);
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($connection->refresh()->status)
            ->toBe(TelegramUserStatus::NotCreated)
            ->and($connection->link_status)
            ->toBeNull();

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('updates a Telegga user and local name and email', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'email' => 'ivan@example.com',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
        'link_status' => 'active',
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

    $this->assertSame('new-value', $user->raw()->new_api_field);

    expect($connection->name)
        ->toBe('Иван Петров')
        ->and($connection->email)
        ->toBe('new@example.com')
        ->and($connection->status)
        ->toBe(TelegramUserStatus::Disabled)
        ->and($connection->link_status)
        ->toBe(TelegramLinkStatus::Active);

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

it('clears the local email after successfully clearing it in Telegga', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'email' => 'ivan@example.com',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'email' => '',
            'status' => 'active',
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

it('preserves the local email when updating only the name', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'email' => 'ivan@example.com',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'status' => 'active',
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

it('rejects an invalid email type in a Telegga response', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'email' => 'ivan@example.com',
        'status' => 'active',
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
            ->and($this->previousApiException(exception: $exception)->apiCode)
            ->toBe('invalid_response');

        return;
    }

    $this->fail('Expected ConnectionException.');
});

it('does not send an empty connection update', function (): void {
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

    $this->fail('Expected a ConnectionException.');
});

it('stops the operation when user_id is missing from the Telegga response', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
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
            ->and($this->previousApiException(exception: $exception)->apiCode)
            ->toBe('invalid_response');

        Http::assertSentCount(1);

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('generates a new code through the bot_id of a pending link', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
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

it('does not generate a code without a user-to-bot link', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'status' => 'active',
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

    $this->fail('Expected a ConnectionException.');
});

it('unlinks a user and resets the local connection status', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
        'link_status' => 'active',
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

    app(TeleggaInterface::class)->unlinkConnection(
        uuid: $connection->uuid,
    );

    expect($connection->refresh()->link_status)
        ->toBe(TelegramLinkStatus::Revoked);

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'DELETE'
            && $request->url() === "https://api.telegga.net/api/v1/users/{$connection->uuid}/link?bot_id=bot-active";
    });
});

it('deletes the local record only after deleting the Telegga user', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
        'link_status' => 'active',
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

it('preserves the local record when API deletion fails', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
        'link_status' => 'active',
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
            ->and($this->previousApiException(exception: $exception)->apiCode)
            ->toBe('internal')
            ->and(TelegramConnectedUser::query()
                ->where('uuid', $connection->uuid)
                ->exists())
            ->toBeTrue();

        return;
    }

    $this->fail('Expected a ConnectionException.');
});

it('resets state and writes a critical log when local deletion fails', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
        'link_status' => 'active',
    ]);

    TelegramConnectedUser::deleting(
        fn (TelegramConnectedUser $model): never => throw new RuntimeException(
            message: 'Local connection deletion failed.',
        ),
    );
    $log = Log::spy();
    Exceptions::fake();
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
            ->and($connection->status)
            ->toBe(TelegramUserStatus::NotCreated)
            ->and($connection->link_status)
            ->toBeNull();

        $this->receivedCall(spy: $log, method: 'critical')
            ->once()
            ->withArgs(function (string $message, array $context) use ($connection): bool {
                return $message === 'Telegga connection orphaned: remote user deleted, local record kept.'
                    && $context['connection_uuid'] === $connection->uuid
                    && $context['state_synchronized'] === true
                    && $context['deletion_exception']['class'] === RuntimeException::class
                    && ! array_key_exists('message', $context['deletion_exception'])
                    && $context['state_exception'] === null;
            });

        Exceptions::assertReported(
            fn (RuntimeException $reported): bool => $reported->getMessage() === 'Local connection deletion failed.',
        );
        Exceptions::assertReportedCount(1);

        return;
    } finally {
        TelegramConnectedUser::flushEventListeners();
        TelegramConnectedUser::clearBootedModels();
    }

    $this->fail('Expected a ConnectionException.');
});

it('completes local deletion when a retry confirms the API user is absent', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
        'link_status' => 'active',
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

it('resets local status when a retry confirms the API link is absent', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
        'link_status' => 'active',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users/{$connection->uuid}/link*" => Http::sequence()
            ->push(['error' => ['code' => 'internal', 'message' => 'Temporary error.']], 503)
            ->push(['error' => ['code' => 'user_not_linked', 'message' => 'User is not linked.']], 409),
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

    app(TeleggaInterface::class)->unlinkConnection(uuid: $connection->uuid);

    expect($connection->refresh()->link_status)->toBe(TelegramLinkStatus::Revoked);

    Http::assertSentCount(3);
});

it('keeps a disabled user disabled when regenerating a bot-link code', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'disabled',
        'link_status' => 'revoked',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'status' => 'disabled',
            'links' => [
                [
                    'bot_id' => 'bot-revoked',
                    'bot_username' => 'mybot',
                    'status' => 'revoked',
                ],
            ],
        ]),
        "api.telegga.net/api/v1/users/{$connection->uuid}/regenerate-code" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'link_status' => 'pending',
            'link_code' => 'NEWCODE1',
            'link_url' => 'https://t.me/mybot?start=NEWCODE1',
        ]),
    ]);

    app(TeleggaInterface::class)->regenerateConnectionCode(uuid: $connection->uuid);
    $connection->refresh();

    expect($connection->status)
        ->toBe(TelegramUserStatus::Disabled)
        ->and($connection->link_status)
        ->toBe(TelegramLinkStatus::Pending);
});

it('keeps a disabled user disabled when unlinking its bot', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'disabled',
        'link_status' => 'active',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/users?external_id={$connection->uuid}" => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'status' => 'disabled',
            'links' => [
                [
                    'bot_id' => 'bot-active',
                    'bot_username' => 'mybot',
                    'status' => 'active',
                ],
            ],
        ]),
        "api.telegga.net/api/v1/users/{$connection->uuid}/link*" => Http::response(
            body: null,
            status: 204,
        ),
    ]);

    app(TeleggaInterface::class)->unlinkConnection(uuid: $connection->uuid);
    $connection->refresh();

    expect($connection->status)
        ->toBe(TelegramUserStatus::Disabled)
        ->and($connection->link_status)
        ->toBe(TelegramLinkStatus::Revoked);
});
