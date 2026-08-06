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
        $status = $this->apiStatus();

        return $status === 408
            || $status === 429
            || ($status !== null && $status >= 500 && $status <= 599);
    }

    /**
     * Получить количество выполненных HTTP-попыток.
     */
    public function attempts(): int
    {
        $exception = $this->apiException();

        return $exception === null ? 0 : $exception->attempts;
    }

    /**
     * Определить, выполнялся ли повторный HTTP-запрос.
     */
    public function wasRetried(): bool
    {
        return $this->attempts() > 1;
    }

    /**
     * Определить, сообщает ли API об уже достигнутом состоянии.
     */
    public function isAlreadyInDesiredState(): bool
    {
        return in_array(
            needle: $this->apiCode(),
            haystack: ['already_linked', 'user_not_linked'],
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
