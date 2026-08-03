<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
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
        'api.telegga.net/api/v1/users*' => Http::response([
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
