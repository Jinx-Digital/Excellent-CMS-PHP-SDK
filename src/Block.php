<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk;

/**
 * One block of a blocks field (page builder): its type (the name of the field group), its key
 * (stable within the list - good for HTML ids and the live preview) and its fields. Read like a
 * record: `$block['title']`, `$block->title`, `$block->get('link.url')`.
 *
 *     foreach ($page->blocks('content') as $block) {
 *         if ($block->is('hero')) { … $block->media('image')?->url … }
 *     }
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
final class Block implements \ArrayAccess, \IteratorAggregate, \JsonSerializable
{
    public const TYPE = '_type';
    public const KEY = '_key';
    /** The HTML of the block's template in the CMS (asked for with Query::html()) */
    public const HTML = '_html';

    /**
     * @param array<string, mixed> $data the item as the API returns it, with _type and _key
     * @param string|null $field the blocks field it belongs to (for live editing)
     * @param Block|null $parent the block it is nested in
     */
    public function __construct(private readonly array $data, private readonly ?string $field = null, private readonly ?Block $parent = null)
    {
    }

    /**
     * The blocks of a field value (a list of items).
     *
     * @return list<self>
     */
    public static function list(mixed $value, ?string $field = null, ?self $parent = null): array
    {
        if (!is_array($value)) {
            return [];
        }
        $items = array_filter($value, static fn($item): bool => is_array($item) && isset($item[self::TYPE]));
        return array_values(array_map(static fn(array $item): self => new self($item, $field, $parent), $items));
    }

    /**
     * Nested blocks: the blocks of a blocks field inside this block, by path - "content", or
     * "columns.0.content" for the content of the first column:
     *
     *     foreach ((array)$block['columns'] as $i => $column) {
     *         echo $renderer->render($block->blocks("columns.{$i}.content"), $context);
     *     }
     *
     * @return list<self>
     */
    public function blocks(string $path): array
    {
        return self::list($this->get($path), $this->field, $this);
    }

    /**
     * The block this one is nested in (null: a block of the record itself).
     */
    public function parent(): ?self
    {
        return $this->parent;
    }

    /**
     * The blocks field it belongs to (null: not known - e.g. made from a raw value).
     */
    public function field(): ?string
    {
        return $this->field;
    }

    public function type(): string
    {
        return (string)($this->data[self::TYPE] ?? '');
    }

    public function key(): string
    {
        return (string)($this->data[self::KEY] ?? '');
    }

    /**
     * Is it of this type (or one of these)?
     */
    public function is(string ...$types): bool
    {
        return in_array($this->type(), $types, true);
    }

    /**
     * A field, or a path into it: "link.url", "images.0.url".
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

    /**
     * File of a media field (null: empty).
     */
    public function media(string $field): ?MediaFile
    {
        $value = $this->data[$field] ?? null;
        return is_array($value) && isset($value['id']) ? MediaFile::fromArray($value) : null;
    }

    /**
     * Files of a media field with several files.
     *
     * @return list<MediaFile>
     */
    public function files(string $field): array
    {
        $value = $this->data[$field] ?? null;
        return is_array($value) ? array_values(array_map(MediaFile::fromArray(...), array_filter($value, static fn($file): bool => is_array($file) && isset($file['id'])))) : [];
    }

    /**
     * The fields without _type, _key and _html.
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return array_diff_key($this->data, [self::TYPE => true, self::KEY => true, self::HTML => true]);
    }

    /**
     * The HTML of the block's template in the CMS - null if not asked for or without a template.
     */
    public function html(): ?string
    {
        $html = $this->data[self::HTML] ?? null;
        return is_string($html) && '' !== $html ? $html : null;
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
        return new \ArrayIterator($this->fields());
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
        throw new \LogicException('Blocks are read-only.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('Blocks are read-only.');
    }
}
