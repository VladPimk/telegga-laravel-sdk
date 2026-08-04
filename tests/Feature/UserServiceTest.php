<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Telegga\Laravel\Services\UserService;

it('создаёт пользователя Telegga без потери новых полей ответа', function (): void {
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

    expect($user)
        ->toBeInstanceOf(stdClass::class)
        ->and($user->user_id)
        ->toBe('telegga-user-1')
        ->and($user->external_id)
        ->toBe('connection-uuid')
        ->and($user->new_api_field)
        ->toBe('new-value');

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

it('получает пользователя Telegga по external_id без потери новых полей', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users?external_id=connection-uuid' => Http::response([
            'user_id' => 'telegga-user-1',
            'external_id' => 'connection-uuid',
            'status' => 'active',
            'links' => [],
            'new_api_field' => 'new-value',
        ]),
    ]);

    $user = app(UserService::class)->findByExternalId(
        externalId: 'connection-uuid',
    );

    expect($user)
        ->toBeInstanceOf(stdClass::class)
        ->and($user->user_id)
        ->toBe('telegga-user-1')
        ->and($user->external_id)
        ->toBe('connection-uuid')
        ->and($user->new_api_field)
        ->toBe('new-value');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.telegga.net/api/v1/users?external_id=connection-uuid';
    });
});

it('получает страницу пользователей Telegga по email', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users?email=ivan%40example.com' => Http::response([
            'data' => [
                [
                    'user_id' => 'telegga-user-1',
                    'external_id' => 'connection-uuid',
                    'email' => 'ivan@example.com',
                ],
            ],
            'next_cursor' => 'next-cursor',
        ]),
    ]);

    $page = app(UserService::class)->getAll(
        query: ['email' => 'ivan@example.com'],
    );

    expect($page->data)
        ->toBeInstanceOf(Collection::class)
        ->and($page->data)
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

it('не допускает поиск по external_id через списочный метод', function (): void {
    Http::preventStrayRequests();

    expect(fn (): object => app(UserService::class)->getAll(
        query: ['external_id' => 'connection-uuid'],
    ))->toThrow(
        InvalidArgumentException::class,
        'Use findByExternalId() for exact external_id lookup: the API returns a single object.',
    );

    Http::assertNothingSent();
});

it('отклоняет успешный ответ пользователя с некорректным json', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.telegga.net/api/v1/users*' => Http::response(
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

    test()->fail('Ожидалось исключение TeleggaApiException.');
});
