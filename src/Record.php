<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk;

/**
 * One record as the API returns it: `id`, the fields, `created_at` / `updated_at`. Read like an
 * array (`$book['title']`), like an object (`$book->title`) or by path (`$book->get('author.name')`).
 *
 * Included references (`with('author')`) are objects in the data - `record('author')` and
 * `records('authors')` return them as records.
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
final class Record implements \ArrayAccess, \IteratorAggregate, \JsonSerializable
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function id(): string
    {
        return (string)($this->data['id'] ?? '');
    }

    /**
     * A field, or a path through included references and lists: "author.name", "images.0.url".
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }
        return $value;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    /**
     * Included single reference as a record (null: empty, or not included - then it is only an id).
     */
    public function record(string $field): ?self
    {
        $value = $this->data[$field] ?? null;
        return is_array($value) && !array_is_list($value) ? new self($value) : null;
    }

    /**
     * Included list of references (repeatable reference field) as records.
     *
     * @return list<self>
     */
    public function records(string $field): array
    {
        $value = $this->data[$field] ?? null;
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_map(static fn(array $item): self => new self($item), array_filter($value, is_array(...))));
    }

    /**
     * Blocks of a blocks field (page builder), in their order - see BlockRenderer.
     *
     * @return list<Block>
     */
    public function blocks(string $field): array
    {
        return Block::list($this->data[$field] ?? null, $field);
    }

    /**
     * File of a media field (null: empty).
     */
    public function media(string $field): ?MediaFile
    {
        $value = $this->data[$field] ?? null;
        return is_array($value) && isset($value['id']) ? MediaFile::fromArray($value) : null;
    }

    /**
     * Children of the record in `tree()` results.
     *
     * @return list<self>
     */
    public function children(): array
    {
        return $this->records('children');
    }

    public function createdAt(): ?\DateTimeImmutable
    {
        return self::date($this->data['created_at'] ?? null);
    }

    public function updatedAt(): ?\DateTimeImmutable
    {
        return self::date($this->data['updated_at'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    /**
     * @return \ArrayIterator<string, mixed>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('Records are read-only - change them with $cms->entity(…)->update($id, [...]).');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('Records are read-only - change them with $cms->entity(…)->update($id, [...]).');
    }

    /**
     * The API returns times in UTC ("2026-10-05 07:55:19").
     */
    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return false !== $date ? $date : null;
    }
}
