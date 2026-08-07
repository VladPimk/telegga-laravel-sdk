<?php

declare(strict_types=1);

namespace Telegga\Laravel\Http;

final class RetryPolicy
{
    /**
     * Determine whether an HTTP status allows a retry.
     */
    public static function isRetryableStatus(?int $status): bool
    {
        return $status === 408
            || $status === 429
            || ($status !== null && $status >= 500 && $status <= 599);
    }
}
