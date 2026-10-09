<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Exception;

/**
 * 429 – too many requests. `retryAfter()` says how many seconds to wait.
 */
final class RateLimitException extends ApiException
{
    /**
     * @param array<string, mixed>|null $errorData
     */
    public function __construct(
        string $message,
        int $status,
        ?string $errorCode = null,
        ?array $errorData = null,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status, $errorCode, $errorData);
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
