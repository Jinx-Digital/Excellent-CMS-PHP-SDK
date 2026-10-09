<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Exception;

/**
 * The token endpoint refused the client credentials. `error()` is the OAuth error code
 * ("invalid_client", "invalid_scope" ...), the message its description.
 */
final class OAuthException extends \RuntimeException implements ExcellentException
{
    public function __construct(
        private readonly string $error,
        string $description,
        private readonly int $status,
    ) {
        parent::__construct('' !== $description ? $description : $error, $status);
    }

    public function error(): string
    {
        return $this->error;
    }

    public function status(): int
    {
        return $this->status;
    }
}
