<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\TelegramConnectedUser;
use Telegga\Laravel\Resolvers\ConnectionContextResolver;

beforeEach(function (): void {
    Schema::enableForeignKeyConstraints();

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $migration = require __DIR__.'/../../database/migrations/create_telegram_connected_users_table.php';

    $migration->up();
});

afterEach(function (): void {
    Schema::dropIfExists('telegram_connected_users');
    Schema::dropIfExists('users');
});

it('разрешает контекст подключения через активную привязку Telegga', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'status' => 'active',
            'links' => [
                [
                    'bot_id' => 'bot-revoked',
                    'status' => 'revoked',
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
        ->toBeInstanceOf(stdClass::class)
        ->and($context->user->user_id)
        ->toBe('telegga-user-1')
        ->and($context->user->new_user_field)
        ->toBe('new-value')
        ->and($context->link)
        ->toBeInstanceOf(stdClass::class)
        ->and($context->link->bot_id)
        ->toBe('bot-active')
        ->and($context->link->new_api_field)
        ->toBe('new-value')
        ->and($connection->getAttributes())
        ->not->toHaveKeys(['bot_id', 'telegga_user_id']);

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'GET'
            && $request->url() === "https://api.telegga.net/api/v1/users?external_id={$connection->uuid}";
    });
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
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => $connection->uuid,
            'links' => [
                [
                    'bot_id' => 'bot-revoked',
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
