<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Telegga\Laravel\Dto\UserData;
use Telegga\Laravel\Dto\UserLinkData;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TelegramConnectedUser;
use Telegga\Laravel\Resolvers\ConnectionContextResolver;

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

it('разрешает контекст подключения через активную привязку Telegga', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'status' => 'active',
            'links' => [
                [
                    'bot_id' => 'other-active-bot',
                    'bot_username' => 'otherbot',
                    'status' => 'active',
                ],
                [
                    'bot_id' => 'bot-active',
                    'bot_username' => 'mybot',
                    'status' => 'active',
                    'new_api_field' => 'new-value',
                ],
            ],
            'new_user_field' => 'new-value',
        ]),
    ]);

    $context = app(ConnectionContextResolver::class)->resolve(
        uuid: $connection->uuid,
    );

    expect($context)
        ->toBeInstanceOf(stdClass::class)
        ->and($context->connection)
        ->toBeInstanceOf(TelegramConnectedUser::class)
        ->and($context->connection->is($connection))
        ->toBeTrue()
        ->and($context->user)
        ->toBeInstanceOf(UserData::class)
        ->and($context->user->user_id)
        ->toBe('telegga-user-1')
        ->and($context->user->raw()->new_user_field)
        ->toBe('new-value')
        ->and($context->link)
        ->toBeInstanceOf(UserLinkData::class)
        ->and($context->link->bot_id)
        ->toBe('bot-active')
        ->and($context->link->raw()->new_api_field)
        ->toBe('new-value')
        ->and($connection->getAttributes())
        ->not->toHaveKeys(['bot_id', 'telegga_user_id']);

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'GET'
            && $request->url() === "https://api.telegga.net/api/v1/users?external_id={$connection->uuid}";
    });
});

it('сопоставляет имя бота без учёта регистра', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [
                [
                    'bot_id' => 'bot-active',
                    'bot_username' => 'MyBot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    $context = app(ConnectionContextResolver::class)->resolve(
        uuid: $connection->uuid,
    );

    expect($context->link->bot_id)
        ->toBe('bot-active')
        ->and($context->link->bot_username)
        ->toBe('MyBot');
});

it('разрешает пользователя Telegga без активной привязки к боту', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [
                [
                    'bot_id' => 'bot-revoked',
                    'bot_username' => 'mybot',
                    'status' => 'revoked',
                ],
            ],
        ]),
    ]);

    $context = app(ConnectionContextResolver::class)->resolveUser(
        uuid: $connection->uuid,
    );

    expect($context)
        ->toBeInstanceOf(stdClass::class)
        ->and($context->connection->is($connection))
        ->toBeTrue()
        ->and($context->user)
        ->toBeInstanceOf(UserData::class)
        ->and($context->user->user_id)
        ->toBe('telegga-user-1')
        ->and($context)
        ->not->toHaveProperty('link');
});

it('отдаёт приоритет активной привязке при разрешении любой привязки к боту', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [
                [
                    'bot_id' => 'bot-revoked',
                    'bot_username' => 'mybot',
                    'status' => 'revoked',
                ],
                [
                    'bot_id' => 'bot-active',
                    'bot_username' => 'mybot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    $context = app(ConnectionContextResolver::class)->resolveBot(
        uuid: $connection->uuid,
    );

    expect($context->link->bot_id)
        ->toBe('bot-active');
});

it('не обращается к api для неизвестного локального подключения', function (): void {
    $uuid = Str::uuid()->toString();

    Http::preventStrayRequests();

    try {
        app(ConnectionContextResolver::class)->resolve(uuid: $uuid);
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBe($uuid)
            ->and($exception->getPrevious())
            ->toBeNull();

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение ConnectionException.');
});

it('не обращается к api для ещё не созданного подключения', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();

    try {
        app(ConnectionContextResolver::class)->resolve(
            uuid: $connection->uuid,
        );
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeNull();

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение ConnectionException.');
});

it('скрывает ошибку api при поиске пользователя Telegga', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response([
            'error' => [
                'code' => 'not_found',
                'message' => 'User was not found.',
            ],
        ], 404),
    ]);

    try {
        app(ConnectionContextResolver::class)->resolve(
            uuid: $connection->uuid,
        );
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('not_found');

        return;
    }

    test()->fail('Ожидалось исключение ConnectionException.');
});

it('отклоняет пользователя Telegga без активной привязки к боту', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [
                [
                    'bot_id' => 'bot-revoked',
                    'bot_username' => 'mybot',
                    'status' => 'revoked',
                ],
            ],
        ]),
    ]);

    try {
        app(ConnectionContextResolver::class)->resolve(
            uuid: $connection->uuid,
        );
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBe($connection->uuid)
            ->and($exception->getPrevious())
            ->toBeNull();

        return;
    }

    test()->fail('Ожидалось исключение ConnectionException.');
});

it('не принимает привязку при неполном совпадении имени бота', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [
                [
                    'bot_id' => 'bot-active',
                    'bot_username' => '@mybot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    try {
        app(ConnectionContextResolver::class)->resolve(
            uuid: $connection->uuid,
        );
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBe($connection->uuid);

        return;
    }

    test()->fail('Ожидалось исключение ConnectionException.');
});

it('скрывает ошибку базы данных при поиске локального подключения', function (): void {
    $uuid = Str::uuid()->toString();

    Schema::drop('telegram_connected_users');
    Http::preventStrayRequests();

    try {
        app(ConnectionContextResolver::class)->resolve(uuid: $uuid);
    } catch (ConnectionException $exception) {
        expect($exception->connectionUuid)
            ->toBe($uuid)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(QueryException::class);

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение ConnectionException.');
});

it('скрывает ошибку базы данных при пакетном поиске подключений', function (): void {
    $uuid = Str::uuid()->toString();

    Schema::drop('telegram_connected_users');
    Http::preventStrayRequests();

    try {
        app(ConnectionContextResolver::class)->resolveConnections(uuids: [$uuid]);
    } catch (ConnectionException $exception) {
        expect($exception->getMessage())
            ->toBe('Local Telegga connections could not be loaded.')
            ->and($exception->getPrevious())
            ->toBeInstanceOf(QueryException::class);

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение ConnectionException.');
});
