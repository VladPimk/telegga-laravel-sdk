<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Exceptions\BotException;
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
});

afterEach(function (): void {
    Schema::dropIfExists('telegram_connected_users');
    Schema::dropIfExists('available_telegram_bots');
    Schema::dropIfExists('users');
});

it('создаёт таблицу доступных ботов и генерирует локальный uuid', function (): void {
    $providedUuid = Str::uuid()->toString();
    $bot = AvailableTelegramBot::query()->create([
        'uuid' => $providedUuid,
        'bot_name' => 'mybot',
    ]);

    expect(Schema::hasColumns('available_telegram_bots', [
        'id',
        'uuid',
        'bot_name',
        'created_at',
        'updated_at',
        'deleted_at',
    ]))->toBeTrue()
        ->and($bot->getKey())
        ->toBeInt()
        ->and($bot->uuid)
        ->toBeString()
        ->not->toBe($providedUuid)
        ->and(Str::isUuid($bot->uuid, 7))
        ->toBeTrue()
        ->and($bot->bot_name)
        ->toBe('mybot')
        ->and($bot->created_at)
        ->not->toBeNull()
        ->and($bot->updated_at)
        ->not->toBeNull();

    $indexes = collect(Schema::getIndexes('available_telegram_bots'));

    expect($indexes->contains(
        fn (array $index): bool => $index['columns'] === ['uuid']
            && $index['unique'] === false,
    ))->toBeTrue();
});

it('добавляет локального бота после проверки списка api', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [
                [
                    'bot_id' => 'remote-bot-id',
                    'username' => 'mybot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    $bot = app(TeleggaInterface::class)->addTelegramBot(botName: 'mybot');

    expect($bot)
        ->toBeInstanceOf(AvailableTelegramBot::class)
        ->and($bot->bot_name)
        ->toBe('mybot')
        ->and(Str::isUuid($bot->uuid))
        ->toBeTrue()
        ->and($bot->uuid)
        ->not->toBe('remote-bot-id');

    Http::assertSentCount(1);
});

it('повторно возвращает существующего локального бота', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [
                [
                    'bot_id' => 'remote-bot-id',
                    'username' => 'mybot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    $first = app(TeleggaInterface::class)->addTelegramBot(botName: 'mybot');
    $second = app(TeleggaInterface::class)->addTelegramBot(botName: 'mybot');

    expect($second->is($first))
        ->toBeTrue()
        ->and(AvailableTelegramBot::query()->count())
        ->toBe(1);
});

it('требует точного соответствия username из api локальному имени', function (string $apiUsername): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [
                [
                    'bot_id' => 'remote-bot-id',
                    'username' => $apiUsername,
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    try {
        app(TeleggaInterface::class)->addTelegramBot(botName: 'mybot');
    } catch (BotException $exception) {
        expect($exception->botName)
            ->toBe('mybot')
            ->and(AvailableTelegramBot::query()->doesntExist())
            ->toBeTrue();

        return;
    }

    test()->fail('Ожидалось исключение BotException.');
})->with([
    'username с лишним символом @' => '@mybot',
    'username в другом регистре' => 'MyBot',
]);

it('отклоняет некорректное имя бота', function (string $botName): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->addTelegramBot(botName: $botName);
    } catch (BotException) {
        expect(AvailableTelegramBot::query()->doesntExist())
            ->toBeTrue();

        Http::assertNothingSent();

        return;
    }

    test()->fail('Ожидалось исключение BotException.');
})->with([
    'пустая строка' => '',
    'с символом @' => '@mybot',
    'только символ @' => '@',
    'с недопустимым символом' => 'my-bot',
]);

it('не создаёт локальную запись для отсутствующего в api бота', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [],
        ]),
    ]);

    try {
        app(TeleggaInterface::class)->addTelegramBot(botName: 'mybot');
    } catch (BotException $exception) {
        expect($exception->botName)
            ->toBe('mybot')
            ->and(AvailableTelegramBot::query()->doesntExist())
            ->toBeTrue();

        return;
    }

    test()->fail('Ожидалось исключение BotException.');
});

it('скрывает некорректный ответ api при добавлении бота', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response('not-json'),
    ]);

    try {
        app(TeleggaInterface::class)->addTelegramBot(botName: 'mybot');
    } catch (BotException $exception) {
        expect($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and(AvailableTelegramBot::query()->doesntExist())
            ->toBeTrue();

        return;
    }

    test()->fail('Ожидалось исключение BotException.');
});

it('получает локально доступных ботов без запроса api', function (): void {
    $bot = AvailableTelegramBot::query()->create([
        'bot_name' => 'mybot',
    ]);
    Http::preventStrayRequests();

    $bots = app(TeleggaInterface::class)->getAvailableBots();

    expect($bots)
        ->toBeInstanceOf(Collection::class)
        ->and($bots)
        ->toHaveCount(1)
        ->and($bots->first()->is($bot))
        ->toBeTrue();

    Http::assertNothingSent();
});

it('связывает доступного бота с подключениями', function (): void {
    $bot = AvailableTelegramBot::query()->create([
        'bot_name' => 'mybot',
    ]);
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'available_telegram_bot_id' => $bot->id,
    ]);

    expect($connection->telegramBot->is($bot))
        ->toBeTrue()
        ->and($bot->connections->first()->is($connection))
        ->toBeTrue();
});

it('удаляет неиспользуемого локального бота', function (): void {
    $bot = AvailableTelegramBot::query()->create([
        'bot_name' => 'mybot',
    ]);

    app(TeleggaInterface::class)->deleteTelegramBot(uuid: $bot->uuid);

    expect(AvailableTelegramBot::query()->doesntExist())
        ->toBeTrue()
        ->and(AvailableTelegramBot::withTrashed()->find($bot->id)?->trashed())
        ->toBeTrue();
});

it('создаёт нового локального бота после мягкого удаления бота с таким же именем', function (): void {
    $bot = AvailableTelegramBot::query()->create([
        'bot_name' => 'mybot',
    ]);
    $bot->delete();

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [
                [
                    'bot_id' => 'remote-bot-id',
                    'username' => 'mybot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    $newBot = app(TeleggaInterface::class)->addTelegramBot(botName: 'mybot');

    expect($newBot->is($bot))
        ->toBeFalse()
        ->and($newBot->uuid)
        ->not->toBe($bot->uuid)
        ->and(AvailableTelegramBot::query()->count())
        ->toBe(1)
        ->and(AvailableTelegramBot::withTrashed()->count())
        ->toBe(2);
});

it('отклоняет удаление неизвестного локального бота', function (): void {
    $botUuid = Str::uuid()->toString();

    try {
        app(TeleggaInterface::class)->deleteTelegramBot(uuid: $botUuid);
    } catch (BotException $exception) {
        expect($exception->botUuid)
            ->toBe($botUuid)
            ->and(AvailableTelegramBot::query()->doesntExist())
            ->toBeTrue();

        return;
    }

    test()->fail('Ожидалось исключение BotException.');
});

it('не удаляет бота используемого подключением', function (): void {
    $bot = AvailableTelegramBot::query()->create([
        'bot_name' => 'mybot',
    ]);
    TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'available_telegram_bot_id' => $bot->id,
    ]);

    try {
        app(TeleggaInterface::class)->deleteTelegramBot(uuid: $bot->uuid);
    } catch (BotException $exception) {
        expect($exception->botUuid)
            ->toBe($bot->uuid)
            ->and(AvailableTelegramBot::query()->count())
            ->toBe(1);

        return;
    }

    test()->fail('Ожидалось исключение BotException.');
});
