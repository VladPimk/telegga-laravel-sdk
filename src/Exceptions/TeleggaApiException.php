<?php

declare(strict_types=1);

namespace Telegga\Laravel\Exceptions;

use Illuminate\Http\Client\Response;
use Throwable;

final class TeleggaApiException extends TeleggaException
{
    /**
     * Create a Telegga API exception.
     *
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $apiCode = null,
        public readonly ?int $retryAfter = null,
        public readonly array $response = [],
        public readonly int $attempts = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(message: $message, previous: $previous);
    }

    /**
     * Create an exception from an HTTP response.
     */
    public static function fromResponse(Response $response, int $attempts = 1): self
    {
        $body = $response->json();
        $data = is_array($body) ? $body : [];
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $message = is_string($error['message'] ?? null)
            ? $error['message']
            : 'Telegga API request failed.';
        $apiCode = is_string($error['code'] ?? null) ? $error['code'] : null;
        $retryAfter = $response->header('Retry-After');

        return new self(
            message: $message,
            status: $response->status(),
            apiCode: $apiCode,
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
            response: $data,
            attempts: $attempts,
        );
    }
}
