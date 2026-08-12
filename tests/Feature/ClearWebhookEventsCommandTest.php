<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TeleggaWebhookEvent;
use Telegga\Laravel\Models\TelegramConnectedUser;

/**
 * Create an event record with the specified timestamps.
 */
function createWebhookEventForCleanup(
    int $connectionId,
    string $eventId,
    Carbon $firstSeenAt,
    Carbon $createdAt,
): void {
    TeleggaWebhookEvent::query()->insert([
        'uuid' => str()->uuid()->toString(),
        'telegram_connected_user_id' => $connectionId,
        'event_id' => $eventId,
        'event' => 'user.linked',
        'attempts' => 1,
        'first_seen_at' => $firstSeenAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-05 12:00:00');

    $telegramBot = AvailableTelegramBot::query()->create(['bot_name' => 'mybot']);
    $this->connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'status' => 'active',
        'available_telegram_bot_id' => $telegramBot->id,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('deletes only events older than ninety days by default', function (): void {
    $log = Log::spy();
    createWebhookEventForCleanup(
        connectionId: $this->connection->id,
        eventId: 'old-event',
        firstSeenAt: now()->subDays(91),
        createdAt: now()->subDays(91),
    );
    createWebhookEventForCleanup(
        connectionId: $this->connection->id,
        eventId: 'boundary-event',
        firstSeenAt: now()->subDays(90),
        createdAt: now()->subDays(90),
    );
    createWebhookEventForCleanup(
        connectionId: $this->connection->id,
        eventId: 'recent-event',
        firstSeenAt: now()->subDays(89),
        createdAt: now()->subDays(89),
    );
    createWebhookEventForCleanup(
        connectionId: $this->connection->id,
        eventId: 'old-first-seen-event',
        firstSeenAt: now()->subDays(120),
        createdAt: now()->subDay(),
    );

    $this->artisan('telegga:webhook-events:clear')
        ->expectsOutput('Deleted 1 Telegga webhook event records older than 90 days.')
        ->assertExitCode(Command::SUCCESS);

    expect(TeleggaWebhookEvent::query()->pluck('event_id')->sort()->values()->all())
        ->toBe(['boundary-event', 'old-first-seen-event', 'recent-event']);

    $this->receivedCall(spy: $log, method: 'info')
        ->once()
        ->withArgs(
            fn (string $message, array $context): bool => $message === 'Telegga webhook event cleanup completed.'
                && $context['status'] === 'success'
                && $context['days'] === 90
                && $context['deleted_records'] === 1,
        );
});

it('uses the provided number of days', function (): void {
    createWebhookEventForCleanup(
        connectionId: $this->connection->id,
        eventId: 'old-event',
        firstSeenAt: now()->subDays(31),
        createdAt: now()->subDays(31),
    );
    createWebhookEventForCleanup(
        connectionId: $this->connection->id,
        eventId: 'recent-event',
        firstSeenAt: now()->subDays(29),
        createdAt: now()->subDays(29),
    );

    $this->artisan('telegga:webhook-events:clear', [
        'days' => 30,
    ])
        ->expectsOutput('Deleted 1 Telegga webhook event records older than 30 days.')
        ->assertExitCode(Command::SUCCESS);

    expect(TeleggaWebhookEvent::query()->pluck('event_id')->all())
        ->toBe(['recent-event']);
});

it('deletes a large event log in chunks of one thousand records', function (): void {
    collect(range(1, 1001))
        ->chunk(500)
        ->each(function ($numbers): void {
            TeleggaWebhookEvent::query()->insert(
                $numbers->map(fn (int $number): array => [
                    'uuid' => str()->uuid()->toString(),
                    'telegram_connected_user_id' => $this->connection->id,
                    'event_id' => "old-event-{$number}",
                    'event' => 'user.linked',
                    'attempts' => 1,
                    'first_seen_at' => now()->subDays(91),
                    'created_at' => now()->subDays(91),
                    'updated_at' => now()->subDays(91),
                ])->all(),
            );
        });

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->artisan('telegga:webhook-events:clear')
        ->expectsOutput('Deleted 1001 Telegga webhook event records older than 90 days.')
        ->assertExitCode(Command::SUCCESS);

    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    expect(TeleggaWebhookEvent::query()->doesntExist())->toBeTrue();
    $this->assertCount(2, $queries->filter(
        fn (array $query): bool => str_starts_with(strtolower($query['query']), 'delete from')
            && str_contains(strtolower($query['query']), 'telegga_webhook_events'),
    ));
});

it('rejects an invalid day count without deleting events', function (string $days): void {
    $log = Log::spy();
    createWebhookEventForCleanup(
        connectionId: $this->connection->id,
        eventId: 'old-event',
        firstSeenAt: now()->subYear(),
        createdAt: now()->subYear(),
    );

    $this->artisan('telegga:webhook-events:clear', [
        'days' => $days,
    ])
        ->expectsOutput('The days argument must be a positive integer.')
        ->assertExitCode(Command::FAILURE);

    expect(TeleggaWebhookEvent::query()->count())
        ->toBe(1);

    $this->receivedCall(spy: $log, method: 'warning')
        ->once()
        ->withArgs(
            fn (string $message, array $context): bool => $message === 'Telegga webhook event cleanup was rejected.'
                && $context['status'] === 'failed'
                && $context['days'] === $days
                && $context['deleted_records'] === 0
                && $context['error_code'] === 'invalid_days',
        );
})->with([
    'zero' => '0',
    'negative number' => '-1',
    'non-numeric value' => 'invalid',
    'fractional number' => '1.5',
]);

it('logs a database error and returns a failure code', function (): void {
    Schema::drop('telegga_webhook_events');
    $log = Log::spy();
    Exceptions::fake();

    $this->artisan('telegga:webhook-events:clear')
        ->expectsOutput('Telegga webhook event records could not be deleted.')
        ->assertExitCode(Command::FAILURE);

    $this->receivedCall(spy: $log, method: 'error')
        ->once()
        ->withArgs(
            fn (string $message, array $context): bool => $message === 'Telegga webhook event cleanup failed.'
                && $context['status'] === 'failed'
                && $context['days'] === 90
                && $context['deleted_records'] === 0
                && $context['error_code'] === 'database_error'
                && $context['exception']['class'] === QueryException::class
                && is_string($context['exception']['sql'])
                && ! array_key_exists('message', $context['exception']),
        );

    Exceptions::assertReported(QueryException::class);
});
