<?php

declare(strict_types=1);

namespace Telegga\Laravel\Http;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Telegga\Laravel\Exceptions\TeleggaApiException;
use Throwable;

final class TeleggaClient
{
    /**
     * Создать HTTP-клиент Telegga.
     */
    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout,
        private readonly int $connectTimeout,
        private readonly int $retryTimes = 3,
        private readonly int $retrySleepMilliseconds = 200,
    ) {
        if ($this->retryTimes < 1) {
            throw new InvalidArgumentException(message: 'Retry attempts must be at least 1.');
        }

        if ($this->retrySleepMilliseconds < 0) {
            throw new InvalidArgumentException(message: 'Retry delay cannot be negative.');
        }
    }

    /**
     * Выполнить GET-запрос.
     *
     * @param  array<string, mixed>  $query
     */
    public function get(string $uri, array $query = []): Response
    {
        return $this->execute(
            request: fn (PendingRequest $request): Response => $request->get(
                url: $uri,
                query: $query,
            ),
            idempotent: true,
        );
    }

    /**
     * Выполнить POST-запрос.
     *
     * @param  array<string, mixed>  $data
     */
    public function post(string $uri, array $data = [], bool $idempotent = false): Response
    {
        return $this->execute(
            request: fn (PendingRequest $request): Response => $request->post(
                url: $uri,
                data: $data,
            ),
            idempotent: $idempotent,
        );
    }

    /**
     * Выполнить PUT-запрос.
     *
     * @param  array<string, mixed>  $data
     */
    public function put(string $uri, array $data = [], bool $idempotent = false): Response
    {
        return $this->execute(
            request: fn (PendingRequest $request): Response => $request->put(
                url: $uri,
                data: $data,
            ),
            idempotent: $idempotent,
        );
    }

    /**
     * Выполнить PATCH-запрос.
     *
     * @param  array<string, mixed>  $data
     */
    public function patch(string $uri, array $data = [], bool $idempotent = false): Response
    {
        return $this->execute(
            request: fn (PendingRequest $request): Response => $request->patch(
                url: $uri,
                data: $data,
            ),
            idempotent: $idempotent,
        );
    }

    /**
     * Выполнить DELETE-запрос.
     *
     * @param  array<string, mixed>  $query
     */
    public function delete(string $uri, array $query = [], bool $idempotent = false): Response
    {
        return $this->execute(
            request: fn (PendingRequest $request): Response => $request
                ->withQueryParameters(parameters: $query)
                ->delete(url: $uri),
            idempotent: $idempotent,
        );
    }

    /**
     * Загрузить файл.
     */
    public function upload(string $uri, string $contents, string $filename): Response
    {
        return $this->execute(
            request: fn (PendingRequest $request): Response => $request
                ->attach(
                    name: 'file',
                    contents: $contents,
                    filename: $filename,
                )
                ->post(url: $uri),
        );
    }

    /**
     * Создать настроенный HTTP-запрос.
     */
    private function request(bool $idempotent): PendingRequest
    {
        if (strcasecmp((string) parse_url($this->baseUrl, PHP_URL_SCHEME), 'https') !== 0) {
            throw new TeleggaApiException(
                message: 'Telegga API base URL must use HTTPS.',
                status: 0,
                apiCode: 'invalid_base_url',
            );
        }

        if ($this->apiKey === '') {
            throw new TeleggaApiException(
                message: 'Telegga API key is not configured.',
                status: 0,
                apiCode: 'missing_api_key',
            );
        }

        $request = $this->http
            ->baseUrl(url: rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->withToken(token: $this->apiKey)
            ->timeout(seconds: $this->timeout)
            ->connectTimeout(seconds: $this->connectTimeout);

        if (! $idempotent || $this->retryTimes === 1) {
            return $request;
        }

        return $request->retry(
            times: $this->retryTimes,
            sleepMilliseconds: fn (int $attempt, Throwable $exception): int => $this->retryDelay(
                attempt: $attempt,
                exception: $exception,
            ),
            when: fn (Throwable $exception): bool => $this->shouldRetry(exception: $exception),
            throw: false,
        );
    }

    /**
     * Выполнить HTTP-запрос.
     */
    private function execute(Closure $request, bool $idempotent = false): Response
    {
        $attempts = 0;
        $pendingRequest = $this->request(idempotent: $idempotent)
            ->beforeSending(function () use (&$attempts): void {
                $attempts++;
            });

        try {
            $response = $request($pendingRequest);
        } catch (ConnectionException $exception) {
            throw new TeleggaApiException(
                message: 'Telegga API connection failed.',
                status: 0,
                apiCode: 'transport_error',
                attempts: $attempts,
                previous: $exception,
            );
        }

        return $this->ensureSuccessful(
            response: $response,
            attempts: $attempts,
        );
    }

    /**
     * Проверить HTTP-ответ.
     */
    private function ensureSuccessful(Response $response, int $attempts): Response
    {
        if ($response->failed()) {
            throw TeleggaApiException::fromResponse(
                response: $response,
                attempts: $attempts,
            );
        }

        return $response;
    }

    /**
     * Определить, допускает ли ошибка автоматический повтор.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        return $status === 408 || $status === 429 || $exception->response->serverError();
    }

    /**
     * Рассчитать задержку перед следующей HTTP-попыткой.
     */
    private function retryDelay(int $attempt, Throwable $exception): int
    {
        $delay = $this->retrySleepMilliseconds * $attempt;

        if (! $exception instanceof RequestException || $exception->response->status() !== 429) {
            return $delay;
        }

        $retryAfter = $exception->response->header('Retry-After');

        if (! is_numeric($retryAfter)) {
            return $delay;
        }

        return max($delay, (int) $retryAfter * 1000);
    }
}
