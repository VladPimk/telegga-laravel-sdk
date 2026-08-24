<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Exceptions\BotException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TelegramConnectedUser;

it('creates the available bots table and generates a local UUID', function (): void {
    $providedUuid = Str::uuid()->toString();
    $bot = AvailableTelegramBot::query()->create([
        'uuid' => $providedUuid,
        'bot_name' => 'mybot',
        'display_name' => 'My Bot',
    ]);

    expect(Schema::hasColumns('available_telegram_bots', [
        'id',
        'uuid',
        'bot_name',
        'display_name',
        'created_at',
        'updated_at',
        'deleted_at',
    ]))->toBeTrue()
        ->and($bot->uuid)
        ->not->toBe($providedUuid)
        ->and(Str::isUuid($bot->uuid, 7))
        ->toBeTrue()
        ->and($bot->bot_name)
        ->toBe('mybot')
        ->and($bot->display_name)
        ->toBe('My Bot')
        ->and($bot->created_at)
        ->not->toBeNull()
        ->and($bot->updated_at)
        ->not->toBeNull();

    $indexes = collect(Schema::getIndexes('available_telegram_bots'));

    expect($indexes->contains(
        fn (array $index): bool => $index['columns'] === ['uuid']
            && $index['unique'] === false,
    ))->toBeTrue()
        ->and($indexes->contains(
            fn (array $index): bool => $index['columns'] === ['bot_name', 'deleted_at']
                && $index['unique'] === true,
        ))->toBeTrue();
});

it('allows a local bot without a display name', function (): void {
    $bot = AvailableTelegramBot::query()->create([
        'bot_name' => 'mybot',
    ]);

    expect($bot->display_name)
        ->toBeNull()
        ->and($bot->refresh()->display_name)
        ->toBeNull();
});

it('adds a local bot after validating the API list', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [
                [
                    'bot_id' => 'remote-bot-id',
                    'username' => 'mybot',
                    'display_name' => 'My Bot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    $bot = app(TeleggaInterface::class)->addTelegramBot(botName: 'mybot');

    expect($bot->bot_name)
        ->toBe('mybot')
        ->and($bot->display_name)
        ->toBe('My Bot')
        ->and(Str::isUuid($bot->uuid))
        ->toBeTrue()
        ->and($bot->uuid)
        ->not->toBe('remote-bot-id');

    Http::assertSentCount(1);
});

it('stores a null display name when the API omits it', function (): void {
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

    expect($bot->display_name)
        ->toBeNull()
        ->and($bot->refresh()->display_name)
        ->toBeNull();

    Http::assertSentCount(1);
});

it('refreshes a cached API list before adding a local bot', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'bot_id' => 'old-bot-id',
                        'username' => 'old_bot',
                        'status' => 'active',
                    ],
                ],
            ])
            ->push([
                'data' => [
                    [
                        'bot_id' => 'new-bot-id',
                        'username' => 'mybot',
                        'status' => 'active',
                    ],
                ],
            ]),
    ]);

    app(TeleggaInterface::class)->getBots();

    $bot = app(TeleggaInterface::class)->addTelegramBot(botName: 'mybot');

    expect($bot->bot_name)
        ->toBe('mybot')
        ->and(app(TeleggaInterface::class)->getBots()->first()->bot_id)
        ->toBe('new-bot-id');

    Http::assertSentCount(2);
});

it('returns an existing local bot on repeated addition', function (): void {
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

it('updates the display name when adding an existing local bot', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'bot_id' => 'remote-bot-id',
                        'username' => 'mybot',
                        'display_name' => 'Old Bot Name',
                        'status' => 'active',
                    ],
                ],
            ])
            ->push([
                'data' => [
                    [
                        'bot_id' => 'remote-bot-id',
                        'username' => 'mybot',
                        'display_name' => 'New Bot Name',
                        'status' => 'active',
                    ],
                ],
            ]),
    ]);

    $first = app(TeleggaInterface::class)->addTelegramBot(botName: 'mybot');
    $uuid = $first->uuid;
    $second = app(TeleggaInterface::class)->addTelegramBot(botName: 'mybot');

    expect($second->is($first))
        ->toBeTrue()
        ->and($second->uuid)
        ->toBe($uuid)
        ->and($second->display_name)
        ->toBe('New Bot Name')
        ->and($second->refresh()->display_name)
        ->toBe('New Bot Name')
        ->and(AvailableTelegramBot::query()->count())
        ->toBe(1);

    Http::assertSentCount(2);
});

it('rejects an API username with an extra character', function (string $apiUsername): void {
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

    $this->fail('Expected a BotException.');
})->with([
    'username with an extra at sign' => '@mybot',
]);

it('matches and stores a bot name in lowercase', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [
                [
                    'bot_id' => 'remote-bot-id',
                    'username' => 'MyBot',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    $bot = app(TeleggaInterface::class)->addTelegramBot(botName: 'MYBOT');

    expect($bot->bot_name)
        ->toBe('mybot')
        ->and($bot->refresh()->bot_name)
        ->toBe('mybot');
});

it('rejects an invalid bot name', function (string $botName): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->addTelegramBot(botName: $botName);
    } catch (BotException) {
        expect(AvailableTelegramBot::query()->doesntExist())
            ->toBeTrue();

        Http::assertNothingSent();

        return;
    }

    $this->fail('Expected a BotException.');
})->with([
    'empty string' => '',
    'with an at sign' => '@mybot',
    'only an at sign' => '@',
    'with a forbidden character' => 'my-bot',
]);

it('does not create a local record for a bot missing from the API', function (): void {
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

    $this->fail('Expected a BotException.');
});

it('wraps an invalid API response when adding a bot', function (): void {
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

    $this->fail('Expected a BotException.');
});

it('gets locally available bots without an API request', function (): void {
    $bot = AvailableTelegramBot::query()->create([
        'bot_name' => 'mybot',
    ]);
    Http::preventStrayRequests();

    $bots = app(TeleggaInterface::class)->getAvailableBots();

    expect($bots)
        ->toHaveCount(1)
        ->and($bots->first()->is($bot))
        ->toBeTrue();

    Http::assertNothingSent();
});

it('relates an available bot to connections', function (): void {
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

it('deletes an unused local bot', function (): void {
    $bot = AvailableTelegramBot::query()->create([
        'bot_name' => 'mybot',
    ]);

    app(TeleggaInterface::class)->deleteTelegramBot(uuid: $bot->uuid);

    expect(AvailableTelegramBot::query()->doesntExist())
        ->toBeTrue()
        ->and(AvailableTelegramBot::withTrashed()->find($bot->id)?->trashed())
        ->toBeTrue();
});

it('invalidates the cached API list when deleting a local bot', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'bot_id' => 'first-bot-id',
                        'username' => 'mybot',
                        'status' => 'active',
                    ],
                ],
            ])
            ->push([
                'data' => [
                    [
                        'bot_id' => 'second-bot-id',
                        'username' => 'mybot',
                        'status' => 'active',
                    ],
                ],
            ]),
    ]);
    $bot = AvailableTelegramBot::query()->create([
        'bot_name' => 'mybot',
    ]);

    app(TeleggaInterface::class)->getBots();
    app(TeleggaInterface::class)->deleteTelegramBot(uuid: $bot->uuid);

    $bots = app(TeleggaInterface::class)->getBots();

    expect($bots->first()->bot_id)
        ->toBe('second-bot-id');

    Http::assertSentCount(2);
});

it('creates a new local bot after soft-deleting a bot with the same name', function (): void {
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

it('rejects deletion of an unknown local bot', function (): void {
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

    $this->fail('Expected a BotException.');
});

it('does not delete a bot used by a connection', function (): void {
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

    $this->fail('Expected a BotException.');
});
