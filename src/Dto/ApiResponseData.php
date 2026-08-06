<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

abstract readonly class ApiResponseData
{
    /**
     * Создать данные ответа API.
     */
    public function __construct(
        private object $rawResponse,
    ) {}

    /**
     * Получить исходный объект ответа API.
     */
    public function raw(): object
    {
        return $this->rawResponse;
    }
}
