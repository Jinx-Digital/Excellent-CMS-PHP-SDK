<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Auth;

use ExcellentCms\Sdk\Internal\TokenEndpoint;

/**
 * Where the bearer token of a request comes from. Without one only public entities can be read.
 */
interface Authentication
{
    /**
     * Token for the next request (null: send none).
     */
    public function token(TokenEndpoint $endpoint): ?string;

    /**
     * The API refused the token (401). Forget it - true if a new token may help, then the request
     * is sent once more.
     */
    public function invalidate(): bool;
}
