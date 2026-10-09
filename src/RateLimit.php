<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk;

/**
 * X-RateLimit-* headers of the last answer (only if the project has a rate limit).
 */
final class RateLimit
{
    public function __construct(
        public readonly int $limit,
        public readonly int $remaining,
        /** Unix time when the window starts again */
        public readonly int $reset,
    ) {
    }
}
