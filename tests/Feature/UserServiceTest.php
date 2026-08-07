<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Services\UserService;

it('creates a Telegga user without losing new response fields', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => 'connection-uuid',
            'link_status' => 'pending',
            'new_api_field' => 'new-value',
        ], 201),
    ]);

    $user = app(UserService::class)->create(
        externalId: 'connection-uuid',
        botId: 'bot-1',
        displayName: 'Иван',
        email: 'ivan@example.com',
        meta: ['locale' => 'ru'],
        groupId: 'group-1',
    );

    expect($user->user_id)
        ->toBe('telegga-user-1')
        ->and($user->external_id)
        ->toBe('connection-uuid');

    $this->assertSame('new-value', $user->raw()->new_api_field);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.telegga.net/api/v1/users'
            && $request->data() === [
                'external_id' => 'connection-uuid',
                'bot_id' => 'bot-1',
                'display_name' => 'Иван',
                'email' => 'ivan@example.com',
                'meta' => ['locale' => 'ru'],
                'group_id' => 'group-1',
            ];
    });
});

it('gets a Telegga user by external_id without losing new fields', function (): void {
    $response = $this->apiFixture(path: 'users/find-by-external-id');
    $response['new_api_field'] = 'new-value';

    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users?external_id=connection-uuid' => Http::response($response),
    ]);

    $user = app(UserService::class)->findByExternalId(
        externalId: 'connection-uuid',
    );

    expect($user->user_id)
        ->toBe('telegga-user-1')
        ->and($user->external_id)
        ->toBe('connection-uuid');

    $this->assertSame('new-value', $user->raw()->new_api_field);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.telegga.net/api/v1/users?external_id=connection-uuid';
    });
});

it('gets a Telegga user page by email', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users?email=ivan%40example.com' => Http::response(
            $this->apiFixture(path: 'users/list-by-email'),
        ),
    ]);

    $page = app(UserService::class)->getAll(
        query: ['email' => 'ivan@example.com'],
    );

    expect($page->data)
        ->toHaveCount(1)
        ->and($page->data->first()->external_id)
        ->toBe('connection-uuid')
        ->and($page->next_cursor)
        ->toBe('next-cursor');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.telegga.net/api/v1/users?email=ivan%40example.com';
    });
});

it('does not allow an external_id lookup through the list method', function (): void {
    Http::preventStrayRequests();

    expect(fn (): object => app(UserService::class)->getAll(
        query: ['external_id' => 'connection-uuid'],
    ))->toThrow(
        InvalidArgumentException::class,
        'Use findByExternalId() for exact external_id lookup: the API returns a single object.',
    );

    Http::assertNothingSent();
});

it('rejects a successful user response with invalid JSON', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users?external_id=connection-uuid' => Http::response(
            body: 'not-json',
            status: 200,
        ),
    ]);

    try {
        app(UserService::class)->findByExternalId(
            externalId: 'connection-uuid',
        );
    } catch (TeleggaApiException $exception) {
        expect($exception->apiCode)
            ->toBe('invalid_response')
            ->and($exception->status)
            ->toBe(0);

        return;
    }

    $this->fail('Expected a TeleggaApiException.');
});
