<?php

declare(strict_types=1);

namespace Telegga\Laravel\Exceptions;

use RuntimeException;

abstract class TeleggaException extends RuntimeException
{
    /**
     * Получить HTTP-статус ответа Telegga API.
     */
    public function apiStatus(): ?int
    {
        return $this->apiException()?->status;
    }

    /**
     * Получить код ошибки Telegga API.
     */
    public function apiCode(): ?string
    {
        return $this->apiException()?->apiCode;
    }

    /**
     * Получить задержку перед повторным запросом.
     */
    public function retryAfter(): ?int
    {
        return $this->apiException()?->retryAfter;
    }

    /**
     * Определить, допускает ли ошибка повторный запрос.
     */
    public function isRetryable(): bool
    {
        return in_array(
            needle: $this->apiStatus(),
            haystack: [408, 429, 500, 502, 503, 504],
            strict: true,
        );
    }

    /**
     * Найти ошибку Telegga API в цепочке исключений.
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
