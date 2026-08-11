<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Telegga\Laravel\Support\ExceptionLogContext;

it('creates a safe context without query bindings or throwable objects', function (): void {
    $email = 'john@example.com';
    $previous = new PDOException("Database rejected {$email}.");
    $exception = new QueryException(
        connectionName: 'testing',
        sql: 'insert into users (name, email) values (?, ?)',
        bindings: ['John Doe', $email],
        previous: $previous,
    );

    $context = ExceptionLogContext::from(exception: $exception);
    $serializedContext = json_encode($context, JSON_THROW_ON_ERROR);

    $containsThrowable = function (array $values) use (&$containsThrowable): bool {
        foreach ($values as $value) {
            if ($value instanceof Throwable) {
                return true;
            }

            if (is_array($value) && $containsThrowable($value)) {
                return true;
            }
        }

        return false;
    };

    expect($context)
        ->toMatchArray([
            'class' => QueryException::class,
            'code' => 0,
            'sql' => 'insert into users (name, email) values (?, ?)',
        ])
        ->and($context['previous']['class'])
        ->toBe(PDOException::class)
        ->and($serializedContext)
        ->not->toContain('John Doe')
        ->not->toContain($email)
        ->not->toContain('Database rejected')
        ->and($containsThrowable($context))
        ->toBeFalse();
});
