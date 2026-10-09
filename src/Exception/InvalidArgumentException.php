<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Exception;

/**
 * Wrong use of the SDK (unknown operator, empty id ...) - found before any request is sent.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements ExcellentException
{
}
