<?php

declare(strict_types=1);

namespace Telegga\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class VerifyWebhookToken
{
    /**
     * Проверить bearer-токен webhook.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = (string) config(key: 'telegga.webhook_token');
        $providedToken = $request->bearerToken();

        if (
            $expectedToken === ''
            || ! is_string($providedToken)
            || ! hash_equals($expectedToken, $providedToken)
        ) {
            Log::warning('Telegga webhook authorization failed.', [
                'path' => $request->path(),
                'ip' => $request->ip(),
                'error_code' => 'unauthorized',
            ]);

            return response()->json(
                data: [
                    'success' => false,
                    'error' => [
                        'code' => 'unauthorized',
                        'message' => 'Invalid webhook token.',
                    ],
                ],
                status: 401,
            );
        }

        return $next($request);
    }
}
