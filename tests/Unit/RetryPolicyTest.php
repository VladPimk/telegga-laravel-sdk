<?php

declare(strict_types=1);

use Telegga\Laravel\Http\RetryPolicy;

it('classifies retryable HTTP statuses', function (?int $status, bool $retryable): void {
    expect(RetryPolicy::isRetryableStatus(status: $status))
        ->toBe($retryable);
})->with([
    'missing status' => [null, false],
    'status before request timeout' => [407, false],
    'request timeout' => [408, true],
    'status after request timeout' => [409, false],
    'rate limit' => [429, true],
    'last client error' => [499, false],
    'first server error' => [500, true],
    'last server error' => [599, true],
    'status after server errors' => [600, false],
]);
