<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Dto\BroadcastCancellationData;
use Telegga\Laravel\Dto\BroadcastCreatedData;
use Telegga\Laravel\Dto\BroadcastData;
use Telegga\Laravel\Exceptions\BroadcastException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Models\AvailableTelegramBot;
use Telegga\Laravel\Models\TelegramConnectedUser;

beforeEach(function (): void {
    $this->telegramBot = AvailableTelegramBot::query()->create(['bot_name' => 'mybot']);
});

it('starts a broadcast to all users of the connection bot', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/broadcasts' => Http::response([
            'broadcast_id' => 'broadcast-1',
            'status' => 'pending',
            'new_api_field' => 'new-value',
        ], 202),
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

    $broadcast = app(TeleggaInterface::class)->startBroadcast(
        uuid: $connection->uuid,
        type: 'text',
        data: [
            'text' => 'Акция!',
            'bot_id' => 'foreign-bot',
            'group_id' => 'foreign-group',
            'type' => 'photo',
            'external_id' => 'foreign-external-id',
            'user_id' => 'foreign-user-id',
        ],
    );

    expect($broadcast)
        ->toBeInstanceOf(BroadcastCreatedData::class)
        ->and($broadcast->broadcast_id)
        ->toBe('broadcast-1')
        ->and($broadcast->status)
        ->toBe('pending')
        ->and($broadcast->raw()->new_api_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/broadcasts'
            && $request->data() === [
                'text' => 'Акция!',
                'bot_id' => 'bot-pending',
                'type' => 'text',
            ];
    });

    Http::assertSent(function (Request $request) use ($connection): bool {
        return $request->method() === 'GET'
            && $request->url() === "https://api.telegga.net/api/v1/users?external_id={$connection->uuid}";
    });

    Http::assertSentCount(2);
});

it('starts a media broadcast to members of the specified group', function (): void {
    $connection = TelegramConnectedUser::query()->create([
        'name' => 'Иван',
        'is_created' => true,
        'available_telegram_bot_id' => $this->telegramBot->id,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/broadcasts' => Http::response([
            'broadcast_id' => 'broadcast-1',
            'status' => 'pending',
        ], 202),
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

    app(TeleggaInterface::class)->startBroadcast(
        uuid: $connection->uuid,
        type: 'photo',
        data: [
            'media_id' => 'media-photo',
            'text' => 'Новая акция',
            'group_id' => 'foreign-group',
        ],
        groupId: 'group-1',
    );

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/broadcasts'
            && $request->data() === [
                'media_id' => 'media-photo',
                'text' => 'Новая акция',
                'bot_id' => 'bot-active',
                'group_id' => 'group-1',
                'type' => 'photo',
            ];
    });
});

it('gets broadcast progress without losing new fields', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/broadcasts/broadcast-1' => Http::response([
            'broadcast_id' => 'broadcast-1',
            'status' => 'in_progress',
            'total' => 2003,
            'sent' => 1200,
            'failed' => 3,
            'new_api_field' => 'new-value',
        ]),
    ]);

    $broadcast = app(TeleggaInterface::class)->getBroadcast(
        broadcastId: 'broadcast-1',
    );

    expect($broadcast)
        ->toBeInstanceOf(BroadcastData::class)
        ->and($broadcast->status)
        ->toBe('in_progress')
        ->and($broadcast->total)
        ->toBe(2003)
        ->and($broadcast->sent)
        ->toBe(1200)
        ->and($broadcast->raw()->new_api_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.telegga.net/api/v1/broadcasts/broadcast-1';
    });
});

it('cancels a broadcast and returns the API result', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/broadcasts/broadcast-1/cancel' => Http::response([
            'status' => 'cancelled',
            'cancelled_messages' => 803,
            'new_api_field' => 'new-value',
        ]),
    ]);

    $result = app(TeleggaInterface::class)->cancelBroadcast(
        broadcastId: 'broadcast-1',
    );

    expect($result)
        ->toBeInstanceOf(BroadcastCancellationData::class)
        ->and($result->status)
        ->toBe('cancelled')
        ->and($result->cancelled_messages)
        ->toBe(803)
        ->and($result->raw()->new_api_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/broadcasts/broadcast-1/cancel'
            && $request->data() === [];
    });
});

it('rejects invalid broadcast parameters before an API request', function (
    Closure $action,
    string $message,
): void {
    Http::preventStrayRequests();

    try {
        $action(app(TeleggaInterface::class));
    } catch (BroadcastException $exception) {
        expect($exception->getMessage())
            ->toBe($message);

        Http::assertNothingSent();

        return;
    }

    test()->fail('Expected a BroadcastException.');
})->with([
    'empty UUID' => [
        fn (TeleggaInterface $telegga) => $telegga->startBroadcast(
            uuid: '   ',
            type: 'text',
        ),
        'Connection UUID cannot be empty.',
    ],
    'empty type' => [
        fn (TeleggaInterface $telegga) => $telegga->startBroadcast(
            uuid: 'connection-uuid',
            type: '   ',
        ),
        'Broadcast type cannot be empty.',
    ],
    'empty group' => [
        fn (TeleggaInterface $telegga) => $telegga->startBroadcast(
            uuid: 'connection-uuid',
            type: 'text',
            groupId: '   ',
        ),
        'Group identifier cannot be empty.',
    ],
    'empty progress identifier' => [
        fn (TeleggaInterface $telegga) => $telegga->getBroadcast(
            broadcastId: '   ',
        ),
        'Broadcast identifier cannot be empty.',
    ],
    'empty cancellation identifier' => [
        fn (TeleggaInterface $telegga) => $telegga->cancelBroadcast(
            broadcastId: '   ',
        ),
        'Broadcast identifier cannot be empty.',
    ],
]);

it('wraps an API error when getting a broadcast', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/broadcasts/broadcast-1' => Http::response([
            'error' => [
                'code' => 'not_found',
                'message' => 'Broadcast was not found.',
            ],
        ], 404),
    ]);

    try {
        app(TeleggaInterface::class)->getBroadcast(
            broadcastId: 'broadcast-1',
        );
    } catch (BroadcastException $exception) {
        expect($exception->broadcastId)
            ->toBe('broadcast-1')
            ->and($exception->connectionUuid)
            ->toBeNull()
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('not_found')
            ->and($exception->getPrevious()?->status)
            ->toBe(404);

        return;
    }

    test()->fail('Expected a BroadcastException.');
});

it('rejects a successful broadcast response with invalid JSON', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/broadcasts/broadcast-1' => Http::response(
            body: 'not-json',
            status: 200,
        ),
    ]);

    try {
        app(TeleggaInterface::class)->getBroadcast(
            broadcastId: 'broadcast-1',
        );
    } catch (BroadcastException $exception) {
        expect($exception->broadcastId)
            ->toBe('broadcast-1')
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($exception->getPrevious()?->apiCode)
            ->toBe('invalid_response');

        return;
    }

    test()->fail('Expected a BroadcastException.');
});
