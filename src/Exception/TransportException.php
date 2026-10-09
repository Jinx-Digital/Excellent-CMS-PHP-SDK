<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Exception;

/**
 * The request did not get an answer (network, DNS, TLS ...) or the answer was no JSON.
 */
final class TransportException extends \RuntimeException implements ExcellentException
{
}
