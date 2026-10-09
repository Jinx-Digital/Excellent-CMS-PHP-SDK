<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Exception;

/**
 * 401 – the entity is protected and the request has no valid token.
 */
final class AuthenticationException extends ApiException
{
}
