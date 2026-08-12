<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TelegramConnectedUser;

it('gets all locally stored connections without sending API requests', function (): void {
    $telegramBot = AvailableTelegramBot::query()->create([
        'bot_name' => 'mybot',
    ]);
    $firstConnection = TelegramConnectedUser::query()->create([
        'name' => 'First user',
        'available_telegram_bot_id' => $telegramBot->id,
    ]);
    $secondConnection = TelegramConnectedUser::query()->create([
        'name' => 'Second user',
        'available_telegram_bot_id' => $telegramBot->id,
    ]);

    Http::preventStrayRequests();

    $connections = app(TeleggaInterface::class)->getLocalConnections();

    expect($connections)
        ->toHaveCount(2)
        ->and($connections->pluck('id')->all())
        ->toBe([$secondConnection->id, $firstConnection->id])
        ->and($connections->first())
        ->toBeInstanceOf(TelegramConnectedUser::class)
        ->and($connections->first()->relationLoaded('telegramBot'))
        ->toBeTrue();

    Http::assertNothingSent();
});

it('filters local connections by application user ID and excludes deleted records', function (): void {
    $telegramBot = AvailableTelegramBot::query()->create([
        'bot_name' => 'mybot',
    ]);
    $firstUser = User::query()->create(['name' => 'First owner']);
    $secondUser = User::query()->create(['name' => 'Second owner']);
    $expectedConnection = TelegramConnectedUser::query()->create([
        'name' => 'Expected connection',
        'user_id' => $firstUser->id,
        'available_telegram_bot_id' => $telegramBot->id,
    ]);
    $deletedConnection = TelegramConnectedUser::query()->create([
        'name' => 'Deleted connection',
        'user_id' => $firstUser->id,
        'available_telegram_bot_id' => $telegramBot->id,
    ]);
    TelegramConnectedUser::query()->create([
        'name' => 'Other connection',
        'user_id' => $secondUser->id,
        'available_telegram_bot_id' => $telegramBot->id,
    ]);
    $deletedConnection->delete();

    Http::preventStrayRequests();

    $connections = app(TeleggaInterface::class)->getLocalConnections(
        userId: $firstUser->id,
    );

    expect($connections)
        ->toHaveCount(1)
        ->and($connections->first()->is($expectedConnection))
        ->toBeTrue();

    Http::assertNothingSent();
});

it('wraps a local connection query failure', function (): void {
    $this->dropConnectionTable();

    try {
        app(TeleggaInterface::class)->getLocalConnections();
    } catch (ConnectionException $exception) {
        expect($exception->getMessage())
            ->toBe('Local Telegga connections could not be loaded.')
            ->and($exception->getPrevious())
            ->toBeInstanceOf(QueryException::class);

        return;
    }

    $this->fail('Expected a ConnectionException.');
});
