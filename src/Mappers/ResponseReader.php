<?php

declare(strict_types=1);

namespace Telegga\Laravel\Mappers;

use Telegga\Laravel\Exceptions\TeleggaApiException;

final class ResponseReader
{
    /**
     * Получить объект ответа.
     */
    public function object(mixed $response, string $context): object
    {
        if (! is_object($response)) {
            $this->invalid(
                context: $context,
                message: 'response must be an object',
            );
        }

        return $response;
    }

    /**
     * Получить обязательное строковое поле.
     */
    public function requiredString(object $response, string $field, string $context): string
    {
        $value = $response->{$field} ?? null;

        if (! is_string($value) || $value === '') {
            $this->invalid(
                context: $context,
                message: sprintf('required string field "%s" is missing or invalid', $field),
            );
        }

        return $value;
    }

    /**
     * Получить необязательное строковое поле.
     */
    public function nullableString(object $response, string $field, string $context): ?string
    {
        if (! property_exists($response, $field) || $response->{$field} === null) {
            return null;
        }

        if (! is_string($response->{$field})) {
            $this->invalid(
                context: $context,
                message: sprintf('optional string field "%s" is invalid', $field),
            );
        }

        return $response->{$field};
    }

    /**
     * Получить обязательное целочисленное поле.
     */
    public function requiredInteger(object $response, string $field, string $context): int
    {
        $value = $response->{$field} ?? null;

        if (! is_int($value)) {
            $this->invalid(
                context: $context,
                message: sprintf('required integer field "%s" is missing or invalid', $field),
            );
        }

        return $value;
    }

    /**
     * Получить необязательное целочисленное поле.
     */
    public function nullableInteger(object $response, string $field, string $context): ?int
    {
        if (! property_exists($response, $field) || $response->{$field} === null) {
            return null;
        }

        if (! is_int($response->{$field})) {
            $this->invalid(
                context: $context,
                message: sprintf('optional integer field "%s" is invalid', $field),
            );
        }

        return $response->{$field};
    }

    /**
     * Получить обязательное логическое поле.
     */
    public function requiredBoolean(object $response, string $field, string $context): bool
    {
        $value = $response->{$field} ?? null;

        if (! is_bool($value)) {
            $this->invalid(
                context: $context,
                message: sprintf('required boolean field "%s" is missing or invalid', $field),
            );
        }

        return $value;
    }

    /**
     * Получить обязательный массив.
     *
     * @return array<int, mixed>
     */
    public function requiredArray(object $response, string $field, string $context): array
    {
        $value = $response->{$field} ?? null;

        if (! is_array($value)) {
            $this->invalid(
                context: $context,
                message: sprintf('required array field "%s" is missing or invalid', $field),
            );
        }

        return array_values($value);
    }

    /**
     * Получить обязательный массив строк.
     *
     * @return array<int, string>
     */
    public function requiredStringArray(object $response, string $field, string $context): array
    {
        $values = $this->requiredArray(
            response: $response,
            field: $field,
            context: $context,
        );

        foreach ($values as $value) {
            if (! is_string($value)) {
                $this->invalid(
                    context: $context,
                    message: sprintf('required string array field "%s" is invalid', $field),
                );
            }
        }

        return $values;
    }

    /**
     * Получить необязательный массив.
     *
     * @return array<int, mixed>
     */
    public function optionalArray(object $response, string $field, string $context): array
    {
        if (! property_exists($response, $field) || $response->{$field} === null) {
            return [];
        }

        if (! is_array($response->{$field})) {
            $this->invalid(
                context: $context,
                message: sprintf('optional array field "%s" is invalid', $field),
            );
        }

        return array_values($response->{$field});
    }

    /**
     * Выбросить ошибку некорректного ответа API.
     */
    private function invalid(string $context, string $message): never
    {
        throw new TeleggaApiException(
            message: sprintf('Telegga returned an invalid %s: %s.', $context, $message),
            status: 0,
            apiCode: 'invalid_response',
        );
    }
}
