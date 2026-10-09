<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk;

use ExcellentCms\Sdk\Internal\Transport;

/**
 * The public routes of a plugin in the project: /api/v1/<project>/plugins/<name>/<path>. Errors
 * come as the exceptions of the SDK (ValidationException with errors() per field, NotFound …).
 *
 *     $cms->plugin('forms')->post('submit/kontakt', ['email' => 'ada@example.com']);
 */
final class Plugin
{
    public function __construct(private readonly Transport $transport, public readonly string $name)
    {
    }

    /**
     * @param array<string, mixed> $query
     */
    public function get(string $path, array $query = []): mixed
    {
        return $this->transport->call('GET', $this->path($path), $query)['data'];
    }

    /**
     * @param array<string, mixed> $body sent as JSON
     */
    public function post(string $path, array $body = []): mixed
    {
        return $this->transport->call('POST', $this->path($path), body: $body)['data'];
    }

    /**
     * @return list<string>
     */
    private function path(string $path): array
    {
        return ['plugins', $this->name, ...array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => '' !== $part))];
    }
}
