<?php

declare(strict_types=1);

namespace Telegga\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Telegga\Laravel\Http\Responses\WebhookResponse;
use Telegga\Laravel\Http\Responses\WebhookResponseCode;

final class VerifyWebhookToken
{
    /**
     * Validate the webhook bearer token.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configuredTokens = config(key: 'telegga.webhook_token');
        $providedToken = $request->bearerToken();

        if (! $this->tokenMatches(
            configuredTokens: $configuredTokens,
            providedToken: $providedToken,
        )) {
            Log::warning('Telegga webhook authorization failed.', [
                'path' => $request->path(),
                'ip' => $request->ip(),
                'error_code' => 'unauthorized',
            ]);

            return WebhookResponse::error(
                code: WebhookResponseCode::Unauthorized,
            )->toResponse($request);
        }

        return $next($request);
    }

    /**
     * Determine whether the provided token matches a configured token.
     */
    private function tokenMatches(mixed $configuredTokens, ?string $providedToken): bool
    {
        if ($providedToken === null || $providedToken === '') {
            return false;
        }

        $expectedTokens = is_array($configuredTokens)
            ? $configuredTokens
            : [$configuredTokens];

        foreach ($expectedTokens as $expectedToken) {
            if (
                is_string($expectedToken)
                && $expectedToken !== ''
                && hash_equals($expectedToken, $providedToken)
            ) {
                return true;
            }
        }

        return false;
    }
}
