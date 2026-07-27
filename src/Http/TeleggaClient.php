<?php

declare(strict_types=1);

namespace Telegga\Laravel\Http;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Telegga\Laravel\Exceptions\TeleggaApiException;

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
    ) {}

    /**
     * Выполнить GET-запрос.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $uri, array $query = []): array
    {
        return $this->resolve(
            response: $this->execute(
                request: fn (): Response => $this->request()->get(
                    url: $uri,
                    query: $query,
                ),
            ),
        );
    }

    /**
     * Выполнить POST-запрос.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $uri, array $data = []): array
    {
        return $this->resolve(
            response: $this->execute(
                request: fn (): Response => $this->request()->post(
                    url: $uri,
                    data: $data,
                ),
            ),
        );
    }

    /**
     * Выполнить PUT-запрос.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function put(string $uri, array $data = []): array
    {
        return $this->resolve(
            response: $this->execute(
                request: fn (): Response => $this->request()->put(
                    url: $uri,
                    data: $data,
                ),
            ),
        );
    }

    /**
     * Выполнить PATCH-запрос.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function patch(string $uri, array $data = []): array
    {
        return $this->resolve(
            response: $this->execute(
                request: fn (): Response => $this->request()->patch(
                    url: $uri,
                    data: $data,
                ),
            ),
        );
    }

    /**
     * Выполнить DELETE-запрос.
     *
     * @param  array<string, mixed>  $query
     */
    public function delete(string $uri, array $query = []): void
    {
        $this->resolve(
            response: $this->execute(
                request: fn (): Response => $this->request()
                    ->withQueryParameters(parameters: $query)
                    ->delete(url: $uri),
            ),
        );
    }

    /**
     * Загрузить файл.
     *
     * @return array<string, mixed>
     */
    public function upload(string $uri, string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException(message: 'Media file is not readable.');
        }

        $stream = fopen(filename: $path, mode: 'rb');

        if ($stream === false) {
            throw new InvalidArgumentException(message: 'Media file cannot be opened.');
        }

        try {
            $response = $this->execute(
                request: fn (): Response => $this->request()
                    ->attach(
                        name: 'file',
                        contents: $stream,
                        filename: basename(path: $path),
                    )
                    ->post(url: $uri),
            );
        } finally {
            fclose(stream: $stream);
        }

        return $this->resolve(response: $response);
    }

    /**
     * Создать настроенный HTTP-запрос.
     */
    private function request(): PendingRequest
    {
        if ($this->apiKey === '') {
            throw new TeleggaApiException(
                message: 'Telegga API key is not configured.',
                status: 0,
                apiCode: 'missing_api_key',
            );
        }

        return $this->http
            ->baseUrl(url: rtrim(string: $this->baseUrl, characters: '/'))
            ->acceptJson()
            ->withToken(token: $this->apiKey)
            ->timeout(seconds: $this->timeout);
    }

    /**
     * Выполнить HTTP-запрос.
     */
    private function execute(Closure $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $exception) {
            throw new TeleggaApiException(
                message: 'Telegga API connection failed.',
                status: 0,
                apiCode: 'transport_error',
                previous: $exception,
            );
        }
    }

    /**
     * Проверить и преобразовать HTTP-ответ.
     *
     * @return array<string, mixed>
     */
    private function resolve(Response $response): array
    {
        if ($response->failed()) {
            throw TeleggaApiException::fromResponse(response: $response);
        }

        if ($response->noContent()) {
            return [];
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}
