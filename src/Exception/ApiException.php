<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Exception;

/**
 * The API answered with an error. The message is the one of the API (German), the code the HTTP
 * status, `errorCode()` the machine-readable code ("validation", "not_found" ...).
 */
class ApiException extends \RuntimeException implements ExcellentException
{
    /**
     * @param array<string, mixed>|null $errorData
     */
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly ?string $errorCode = null,
        private readonly ?array $errorData = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function errorData(): ?array
    {
        return $this->errorData;
    }
}
