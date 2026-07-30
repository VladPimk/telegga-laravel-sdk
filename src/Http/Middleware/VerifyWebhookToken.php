<?php

declare(strict_types=1);

namespace Telegga\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
            return response()->json(
                data: [
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
