<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Auth;

/**
 * Answer of the token endpoint.
 */
final class AccessToken
{
    /**
     * @param int $expiresAt unix time
     */
    public function __construct(
        public readonly string $value,
        public readonly int $expiresAt,
        public readonly ?string $scope = null,
    ) {
    }

    /**
     * Expired, or it will be within `$leeway` seconds (the request should not run into the end).
     */
    public function isExpired(int $now, int $leeway = 30): bool
    {
        return $now + $leeway >= $this->expiresAt;
    }
}
