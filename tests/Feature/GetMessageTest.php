<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Telegga\Laravel\Contracts\TeleggaInterface;
use Telegga\Laravel\Dto\DeliveryAttemptData;
use Telegga\Laravel\Exceptions\MessageException;
use Telegga\Laravel\Exceptions\TeleggaApiException;

it('gets message status without losing new response fields', function (): void {
    $messageId = 'b6949e36-0000-4000-8000-000000000000';

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/messages/{$messageId}" => Http::response([
            'message_id' => $messageId,
            'status' => 'sent',
            'type' => 'text',
            'attempts' => 1,
            'telegram_message_id' => 7,
            'created_at' => '2026-07-30T10:00:00Z',
            'sent_at' => '2026-07-30T10:00:01Z',
            'delivery_attempts' => [
                [
                    'at' => '2026-07-30T10:00:01Z',
                    'ok' => true,
                    'latency_ms' => 42,
                ],
            ],
            'new_api_field' => 'new-value',
        ]),
    ]);

    $message = app(TeleggaInterface::class)->getMessage(
        messageId: $messageId,
    );

    expect($message->message_id)
        ->toBe($messageId)
        ->and($message->status)
        ->toBe('sent')
        ->and($message->delivery_attempts)
        ->toHaveCount(1)
        ->and($message->delivery_attempts->first())
        ->toBeInstanceOf(DeliveryAttemptData::class)
        ->and($message->delivery_attempts[0]->ok)
        ->toBeTrue();

    $this->assertSame('new-value', $message->raw()->new_api_field);

    Http::assertSent(function (Request $request) use ($messageId): bool {
        return $request->method() === 'GET'
            && $request->url() === "https://api.telegga.net/api/v1/messages/{$messageId}";
    });
});

it('does not send a request with an empty message identifier', function (): void {
    Http::preventStrayRequests();

    try {
        app(TeleggaInterface::class)->getMessage(messageId: '   ');
    } catch (MessageException $exception) {
        expect($exception->getMessage())
            ->toBe('Message identifier cannot be empty.')
            ->and($exception->messageId)
            ->toBe('   ')
            ->and($exception->connectionUuid)
            ->toBeNull()
            ->and($exception->getPrevious())
            ->toBeNull();

        Http::assertNothingSent();

        return;
    }

    $this->fail('Expected a MessageException.');
});

it('wraps an API error when getting a message', function (): void {
    $messageId = 'b6949e36-0000-4000-8000-000000000000';

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/messages/{$messageId}" => Http::response([
            'error' => [
                'code' => 'not_found',
                'message' => 'Message was not found.',
            ],
        ], 404),
    ]);

    try {
        app(TeleggaInterface::class)->getMessage(
            messageId: $messageId,
        );
    } catch (MessageException $exception) {
        expect($exception->messageId)
            ->toBe($messageId)
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

    $this->fail('Expected a MessageException.');
});

it('rejects a successful status response with invalid JSON', function (): void {
    $messageId = 'b6949e36-0000-4000-8000-000000000000';

    Http::preventStrayRequests();
    Http::fake([
        "api.telegga.net/api/v1/messages/{$messageId}" => Http::response(
            body: 'not-json',
            status: 200,
        ),
    ]);

    try {
        app(TeleggaInterface::class)->getMessage(
            messageId: $messageId,
        );
    } catch (MessageException $exception) {
        expect($exception->messageId)
            ->toBe($messageId)
            ->and($exception->getPrevious())
            ->toBeInstanceOf(TeleggaApiException::class)
            ->and($this->previousApiException(exception: $exception)->apiCode)
            ->toBe('invalid_response');

        return;
    }

    $this->fail('Expected a MessageException.');
});
