<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk;

/**
 * An entity the client may read, with its fields (`GET /content`).
 */
final class EntitySchema
{
    /**
     * @param list<string> $languages
     * @param list<FieldSchema> $fields
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $description,
        /** "public" or "oauth" */
        public readonly string $access,
        /** Field shown for a record (also the default when sorting by a reference to it) */
        public readonly ?string $labelField,
        /** Parent field of a tree, see `tree()` */
        public readonly ?string $treeField,
        public readonly array $languages,
        public readonly array $fields,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        return new self(
            (string)($data['slug'] ?? ''),
            (string)($data['name'] ?? ''),
            is_string($data['description'] ?? null) ? $data['description'] : null,
            (string)($data['access'] ?? 'public'),
            is_string($data['label_field'] ?? null) ? $data['label_field'] : null,
            is_string($data['tree_field'] ?? null) ? $data['tree_field'] : null,
            array_values(array_map(strval(...), is_array($data['languages'] ?? null) ? $data['languages'] : [])),
            array_values(array_map(static fn(array $field): FieldSchema => FieldSchema::fromArray($field), array_filter($fields, is_array(...)))),
            $data,
        );
    }

    public function field(string $name): ?FieldSchema
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }
        return null;
    }

    public function isPublic(): bool
    {
        return 'public' === $this->access;
    }
}
