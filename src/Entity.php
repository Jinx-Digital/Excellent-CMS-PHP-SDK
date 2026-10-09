<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk;

use ExcellentCms\Sdk\Exception\InvalidArgumentException;
use ExcellentCms\Sdk\Exception\NotFoundException;
use ExcellentCms\Sdk\Internal\Transport;

/**
 * Records of one entity: read (`find()`, `query()` and the query methods right here), write
 * (`create()`, `update()`, `delete()` - the API client needs the permission for it).
 *
 * @mixin Query
 */
final class Entity
{
    public function __construct(
        private readonly Transport $transport,
        public readonly string $slug,
    ) {
    }

    public function query(): Query
    {
        return new Query($this);
    }

    /**
     * Query methods directly on the entity: $cms->entity('books')->where('year', '>', 2000)->get()
     *
     * @param array<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $query = $this->query();
        if (!method_exists($query, $method)) {
            throw new \BadMethodCallException(sprintf('Method %s::%s() does not exist.', self::class, $method));
        }
        return $query->{$method}(...$arguments);
    }

    /**
     * One record, null if there is none with this id.
     *
     * @param list<string> $fields only these fields
     * @param list<string> $with included references
     */
    public function find(string $id, array $fields = [], array $with = [], ?string $lang = null, bool $html = false): ?Record
    {
        try {
            return $this->get($id, $fields, $with, $lang, $html);
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * One record - throws NotFoundException if there is none with this id. `$html`: blocks with the
     * HTML of their templates in the CMS (see Query::html()).
     *
     * @param list<string> $fields
     * @param list<string> $with
     */
    public function get(string $id, array $fields = [], array $with = [], ?string $lang = null, bool $html = false): Record
    {
        $result = $this->transport->call('GET', ['content', $this->slug, self::id($id)], array_filter([
            'fields' => $fields ? implode(',', $fields) : null,
            'include' => $with ? implode(',', $with) : null,
            'lang' => $lang,
            'render' => $html ? 'html' : null,
        ], static fn(mixed $value): bool => null !== $value));
        return self::record($result['data']);
    }

    /**
     * New record - the answer is the record as saved (with id and generated values).
     * `$lang`: the translatable fields in `$data` are in this language.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data, ?string $lang = null): Record
    {
        return self::record($this->transport->call('POST', ['content', $this->slug], self::lang($lang), $data)['data']);
    }

    /**
     * Changes only the fields in `$data` (PATCH).
     *
     * @param array<string, mixed> $data
     */
    public function update(string $id, array $data, ?string $lang = null): Record
    {
        return self::record($this->transport->call('PATCH', ['content', $this->slug, self::id($id)], self::lang($lang), $data)['data']);
    }

    /**
     * Into the trash if the entity has one, otherwise deleted. Records other records still point
     * to cannot be deleted (ApiException with status 409).
     */
    public function delete(string $id): void
    {
        $this->transport->call('DELETE', ['content', $this->slug, self::id($id)]);
    }

    /**
     * @internal used by Query
     * @param array<string, mixed> $parameters
     * @return array{data: mixed, meta: array<string, mixed>|null}
     */
    public function fetch(array $parameters): array
    {
        return $this->transport->call('GET', ['content', $this->slug], $parameters);
    }

    private static function id(string $id): string
    {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('The record id is empty.');
        }
        return $id;
    }

    /**
     * @return array<string, string>
     */
    private static function lang(?string $lang): array
    {
        return null !== $lang ? ['lang' => $lang] : [];
    }

    private static function record(mixed $data): Record
    {
        return new Record(is_array($data) ? $data : []);
    }
}
