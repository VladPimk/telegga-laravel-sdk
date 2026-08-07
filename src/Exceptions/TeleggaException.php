<?php

declare(strict_types=1);

namespace Telegga\Laravel\Exceptions;

use RuntimeException;

abstract class TeleggaException extends RuntimeException
{
    /**
     * Get the Telegga API response HTTP status.
     */
    public function apiStatus(): ?int
    {
        return $this->apiException()?->status;
    }

    /**
     * Get the Telegga API error code.
     */
    public function apiCode(): ?string
    {
        return $this->apiException()?->apiCode;
    }

    /**
     * Get the delay before a retry.
     */
    public function retryAfter(): ?int
    {
        return $this->apiException()?->retryAfter;
    }

    /**
     * Determine whether the error allows a retry.
     */
    public function isRetryable(): bool
    {
        $status = $this->apiStatus();

        return $status === 408
            || $status === 429
            || ($status !== null && $status >= 500 && $status <= 599);
    }

    /**
     * Get the number of HTTP attempts made.
     */
    public function attempts(): int
    {
        $exception = $this->apiException();

        return $exception === null ? 0 : $exception->attempts;
    }

    /**
     * Determine whether an HTTP request was retried.
     */
    public function wasRetried(): bool
    {
        return $this->attempts() > 1;
    }

    /**
     * Determine whether the API reports an already achieved state.
     */
    public function isAlreadyInDesiredState(): bool
    {
        return in_array(
            $this->apiCode(),
            ['already_linked', 'user_not_linked'],
            true,
        );
    }

    /**
     * Find a Telegga API error in the exception chain.
     */
    private function apiException(): ?TeleggaApiException
    {
        $exception = $this;

        while ($exception !== null) {
            if ($exception instanceof TeleggaApiException) {
                return $exception;
            }

            $exception = $exception->getPrevious();
        }

        return null;
    }
}
