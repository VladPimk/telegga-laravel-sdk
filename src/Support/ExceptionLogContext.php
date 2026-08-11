<?php

declare(strict_types=1);

namespace Telegga\Laravel\Support;

use Illuminate\Database\QueryException;
use Throwable;

final class ExceptionLogContext
{
    /**
     * Create a log-safe exception context without messages or query bindings.
     *
     * @return array<string, mixed>
     */
    public static function from(Throwable $exception): array
    {
        $context = [
            'class' => $exception::class,
            'code' => $exception->getCode(),
            'location' => $exception->getFile().':'.$exception->getLine(),
        ];

        if ($exception instanceof QueryException) {
            $context['sql'] = $exception->getSql();
        }

        if ($exception->getPrevious() !== null) {
            $context['previous'] = self::from(
                exception: $exception->getPrevious(),
            );
        }

        return $context;
    }
}
