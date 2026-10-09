<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Auth;

use ExcellentCms\Sdk\Internal\TokenEndpoint;

/**
 * A token you got elsewhere (e.g. from your own token cache). It is not renewed.
 */
final class BearerToken implements Authentication
{
    public function __construct(private readonly string $token)
    {
    }

    public function token(TokenEndpoint $endpoint): string
    {
        return $this->token;
    }

    public function invalidate(): bool
    {
        return false;
    }
}
