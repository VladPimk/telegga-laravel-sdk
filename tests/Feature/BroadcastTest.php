<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Telegga\Laravel\BroadcastAudience;
use Telegga\Laravel\Contracts\TeleggaInterface;
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
        'status' => 'active',
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

    $broadcast = app(TeleggaInterface::class)->startBroadcast(
        viaConnectionUuid: $connection->uuid,
        type: 'text',
        audience: BroadcastAudience::allLinkedUsers(),
        data: [
            'text' => 'Акция!',
            'bot_id' => 'foreign-bot',
            'group_id' => 'foreign-group',
            'type' => 'photo',
            'external_id' => 'foreign-external-id',
            'user_id' => 'foreign-user-id',
        ],
    );

    expect($broadcast->broadcast_id)
        ->toBe('broadcast-1')
        ->and($broadcast->status)
        ->toBe('pending');

    $this->assertSame('new-value', $broadcast->raw()->new_api_field);

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
        'status' => 'active',
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

    app(TeleggaInterface::class)->startBroadcast(
        viaConnectionUuid: $connection->uuid,
        type: 'photo',
        audience: BroadcastAudience::group(groupId: 'group-1'),
        data: [
            'media_id' => 'media-photo',
            'text' => 'Новая акция',
            'group_id' => 'foreign-group',
        ],
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

    expect($broadcast->status)
        ->toBe('in_progress')
        ->and($broadcast->total)
        ->toBe(2003)
        ->and($broadcast->sent)
        ->toBe(1200);

    $this->assertSame('new-value', $broadcast->raw()->new_api_field);

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

    expect($result->status)
        ->toBe('cancelled')
        ->and($result->cancelled_messages)
        ->toBe(803);

    $this->assertSame('new-value', $result->raw()->new_api_field);

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

    $this->fail('Expected a BroadcastException.');
})->with([
    'empty UUID' => [
        fn (TeleggaInterface $telegga) => $telegga->startBroadcast(
            viaConnectionUuid: '   ',
            type: 'text',
            audience: BroadcastAudience::allLinkedUsers(),
        ),
        'Connection UUID cannot be empty.',
    ],
    'empty type' => [
        fn (TeleggaInterface $telegga) => $telegga->startBroadcast(
            viaConnectionUuid: 'connection-uuid',
            type: '   ',
            audience: BroadcastAudience::allLinkedUsers(),
        ),
        'Broadcast type cannot be empty.',
    ],
    'empty group' => [
        fn (TeleggaInterface $telegga) => $telegga->startBroadcast(
            viaConnectionUuid: 'connection-uuid',
            type: 'text',
            audience: BroadcastAudience::group(groupId: '   '),
        ),
        'Group identifier cannot be empty.',
    ],
    'missing audience' => [
        fn (TeleggaInterface $telegga) => $telegga->startBroadcast(
            viaConnectionUuid: 'connection-uuid',
            type: 'text',
        ),
        'Broadcast audience must be specified.',
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
            ->and($this->previousApiException(exception: $exception)->apiCode)
            ->toBe('not_found')
            ->and($this->previousApiException(exception: $exception)->status)
            ->toBe(404);

        return;
    }

    $this->fail('Expected a BroadcastException.');
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
            ->and($this->previousApiException(exception: $exception)->apiCode)
            ->toBe('invalid_response');

        return;
    }

    $this->fail('Expected a BroadcastException.');
});
