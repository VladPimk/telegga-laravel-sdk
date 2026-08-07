<?php

declare(strict_types=1);

namespace Telegga\Laravel\Dto;

abstract readonly class ApiResponseData
{
    /**
     * Create API response data.
     */
    public function __construct(
        private object $rawResponse,
    ) {}

    /**
     * Get the raw API response object.
     */
    public function raw(): object
    {
        return $this->rawResponse;
    }
}
