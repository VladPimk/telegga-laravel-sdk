<?php

declare(strict_types=1);

use Telegga\Laravel\Exceptions\ConnectionException;
use Telegga\Laravel\Exceptions\TeleggaApiException;

it('exposes nested Telegga API error data', function (): void {
    $apiException = new TeleggaApiException(
        message: 'Rate limit exceeded.',
        status: 429,
        apiCode: 'rate_limited',
        retryAfter: 30,
        response: [
            'error' => [
                'code' => 'rate_limited',
                'message' => 'Rate limit exceeded.',
            ],
        ],
    );
    $exception = new ConnectionException(
        message: $apiException->getMessage(),
        connectionUuid: 'connection-uuid',
        previous: $apiException,
    );

    expect($exception->apiStatus())
        ->toBe(429)
        ->and($exception->apiCode())
        ->toBe('rate_limited')
        ->and($exception->retryAfter())
        ->toBe(30)
        ->and($exception->isRetryable())
        ->toBeTrue()
        ->and($exception->attempts())
        ->toBe(0)
        ->and($exception->wasRetried())
        ->toBeFalse();
});

it('exposes direct Telegga API error data', function (): void {
    $exception = new TeleggaApiException(
        message: 'Telegga API request failed.',
        status: 503,
        apiCode: 'internal',
    );

    expect($exception->apiStatus())
        ->toBe(503)
        ->and($exception->apiCode())
        ->toBe('internal')
        ->and($exception->retryAfter())
        ->toBeNull()
        ->and($exception->isRetryable())
        ->toBeTrue();
});

it('returns empty data without a nested Telegga API error', function (): void {
    $exception = new ConnectionException(
        message: 'Local connection failed.',
        connectionUuid: 'connection-uuid',
    );

    expect($exception->apiStatus())
        ->toBeNull()
        ->and($exception->apiCode())
        ->toBeNull()
        ->and($exception->retryAfter())
        ->toBeNull()
        ->and($exception->isRetryable())
        ->toBeFalse();
});

it('does not treat a client error as retryable', function (): void {
    $apiException = new TeleggaApiException(
        message: 'User is not linked.',
        status: 409,
        apiCode: 'user_not_linked',
    );
    $exception = new ConnectionException(
        message: $apiException->getMessage(),
        connectionUuid: 'connection-uuid',
        previous: $apiException,
    );

    expect($exception->apiStatus())
        ->toBe(409)
        ->and($exception->apiCode())
        ->toBe('user_not_linked')
        ->and($exception->isRetryable())
        ->toBeFalse()
        ->and($exception->isAlreadyInDesiredState())
        ->toBeTrue();
});

it('exposes the attempt count from a nested API error', function (): void {
    $apiException = new TeleggaApiException(
        message: 'User is not linked.',
        status: 409,
        apiCode: 'user_not_linked',
        attempts: 3,
    );
    $exception = new ConnectionException(
        message: $apiException->getMessage(),
        connectionUuid: 'connection-uuid',
        previous: $apiException,
    );

    expect($exception->attempts())
        ->toBe(3)
        ->and($exception->wasRetried())
        ->toBeTrue()
        ->and($exception->isAlreadyInDesiredState())
        ->toBeTrue();
});

it('treats every server error status as retryable', function (): void {
    $exception = new TeleggaApiException(
        message: 'Not implemented.',
        status: 501,
        apiCode: 'internal',
    );

    expect($exception->isRetryable())->toBeTrue();
});
