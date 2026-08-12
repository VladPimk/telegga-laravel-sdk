<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Exceptions\BotException;
use Telegga\Laravel\Models\AvailableTelegramBot;

it('synchronizes active bots without removing existing local records', function (): void {
    $log = Log::spy();
    $existingBot = AvailableTelegramBot::query()->create([
        'bot_name' => 'existing_bot',
    ]);
    $existingUuid = $existingBot->uuid;
    AvailableTelegramBot::query()->create([
        'bot_name' => 'local_only_bot',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [
                ['bot_id' => 'bot-1', 'username' => 'Existing_Bot', 'status' => 'active'],
                ['bot_id' => 'bot-2', 'username' => 'new_bot', 'status' => 'active'],
                ['bot_id' => 'bot-3', 'username' => 'inactive_bot', 'status' => 'inactive'],
            ],
        ]),
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->artisan('telegga:bots:sync')
        ->expectsOutput('Telegram bots synchronized: received 3, active 2, created 1, existing 1.')
        ->assertExitCode(Command::SUCCESS);

    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    expect(AvailableTelegramBot::query()->orderBy('bot_name')->pluck('bot_name')->all())
        ->toBe(['existing_bot', 'local_only_bot', 'new_bot'])
        ->and($existingBot->refresh()->uuid)
        ->toBe($existingUuid)
        ->and(Str::isUuid(
            AvailableTelegramBot::query()->where('bot_name', 'new_bot')->sole()->uuid,
            7,
        ))
        ->toBeTrue();

    $this->assertCount(1, $queries->filter(
        fn (array $query): bool => str_starts_with(strtolower($query['query']), 'insert into')
            && str_contains(strtolower($query['query']), 'available_telegram_bots'),
    ));

    $this->receivedCall(spy: $log, method: 'info')
        ->once()
        ->withArgs(
            fn (string $message, array $context): bool => $message === 'Telegga bot synchronization completed.'
                && $context['status'] === 'success'
                && $context['received_bots'] === 3
                && $context['active_bots'] === 2
                && $context['created_bots'] === 1
                && $context['existing_bots'] === 1,
        );

    Http::assertSentCount(1);
});

it('refreshes the cached bot list before synchronization', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::sequence()
            ->push([
                'data' => [
                    ['bot_id' => 'bot-1', 'username' => 'cached_bot', 'status' => 'active'],
                ],
            ])
            ->push([
                'data' => [
                    ['bot_id' => 'bot-2', 'username' => 'current_bot', 'status' => 'active'],
                ],
            ]),
    ]);

    app(TeleggaInterface::class)->getBots();

    $this->artisan('telegga:bots:sync')
        ->expectsOutput('Telegram bots synchronized: received 1, active 1, created 1, existing 0.')
        ->assertExitCode(Command::SUCCESS);

    expect(AvailableTelegramBot::query()->pluck('bot_name')->all())
        ->toBe(['current_bot']);

    Http::assertSentCount(2);
});

it('can run repeatedly without creating duplicate bots', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [
                ['bot_id' => 'bot-1', 'username' => 'first_bot', 'status' => 'active'],
                ['bot_id' => 'bot-2', 'username' => 'second_bot', 'status' => 'active'],
            ],
        ]),
    ]);

    $this->artisan('telegga:bots:sync')
        ->expectsOutput('Telegram bots synchronized: received 2, active 2, created 2, existing 0.')
        ->assertExitCode(Command::SUCCESS);

    $this->artisan('telegga:bots:sync')
        ->expectsOutput('Telegram bots synchronized: received 2, active 2, created 0, existing 2.')
        ->assertExitCode(Command::SUCCESS);

    expect(AvailableTelegramBot::query()->count())
        ->toBe(2);
});

it('creates a new local record for an active soft-deleted bot', function (): void {
    $deletedBot = AvailableTelegramBot::query()->create([
        'bot_name' => 'restored_bot',
    ]);
    $deletedBot->delete();

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [
                ['bot_id' => 'bot-1', 'username' => 'restored_bot', 'status' => 'active'],
            ],
        ]),
    ]);

    $this->artisan('telegga:bots:sync')
        ->expectsOutput('Telegram bots synchronized: received 1, active 1, created 1, existing 0.')
        ->assertExitCode(Command::SUCCESS);

    $newBot = AvailableTelegramBot::query()->sole();

    expect($newBot->uuid)
        ->not->toBe($deletedBot->uuid)
        ->and(AvailableTelegramBot::withTrashed()->count())
        ->toBe(2);
});

it('returns a failure and preserves local records when the API request fails', function (): void {
    $log = Log::spy();
    Exceptions::fake();
    AvailableTelegramBot::query()->create([
        'bot_name' => 'existing_bot',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'error' => [
                'code' => 'unauthorized',
                'message' => 'Unauthorized.',
            ],
        ], 401),
    ]);

    $this->artisan('telegga:bots:sync')
        ->expectsOutput('Telegga bots could not be synchronized.')
        ->assertExitCode(Command::FAILURE);

    expect(AvailableTelegramBot::query()->pluck('bot_name')->all())
        ->toBe(['existing_bot']);

    $this->receivedCall(spy: $log, method: 'error')
        ->once()
        ->withArgs(
            fn (string $message, array $context): bool => $message === 'Telegga bot synchronization failed.'
                && $context['status'] === 'failed'
                && $context['error_code'] === 'synchronization_failed'
                && $context['exception']['class'] === BotException::class,
        );

    Exceptions::assertReported(BotException::class);
});

it('returns a failure when local synchronization fails', function (): void {
    Exceptions::fake();

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/bots' => Http::response([
            'data' => [
                ['bot_id' => 'bot-1', 'username' => 'first_bot', 'status' => 'active'],
                ['bot_id' => 'bot-2', 'username' => 'second_bot', 'status' => 'active'],
            ],
        ]),
    ]);
    Schema::drop('available_telegram_bots');

    $this->artisan('telegga:bots:sync')
        ->expectsOutput('Telegga bots could not be synchronized.')
        ->assertExitCode(Command::FAILURE);

    Exceptions::assertReported(BotException::class);
});
