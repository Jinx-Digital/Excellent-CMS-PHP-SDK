<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk;

/**
 * A field of an entity as `GET /content` describes it.
 */
final class FieldSchema
{
    /**
     * @param array<string, mixed> $raw everything the API returns (length, media_accept, pattern ...)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        /** string, text, integer, decimal, boolean, date, datetime, time, email, url, reference, uuid, autoincrement, media, slug, markdown, richtext, group, regex, order, enum, color, phone, daterange, json */
        public readonly string $type,
        public readonly bool $required,
        public readonly bool $unique,
        public readonly bool $repeatable,
        public readonly bool $translatable,
        /** Slug of the referenced entity (type "reference") */
        public readonly ?string $reference,
        public readonly array $raw,
        /** @var array<string, string> type "enum": value => label */
        public readonly array $options = [],
        /** Part of the text search (search()) */
        public readonly bool $searchable = true,
        /** Can be used in where() - otherwise the API answers 422 */
        public readonly bool $filterable = true,
        /** @var array<string, string> blocks field (type "group", page builder): block type => label */
        public readonly array $blocks = [],
    ) {
    }

    /**
     * A blocks field (page builder) - see Record::blocks().
     */
    public function isBlocks(): bool
    {
        return [] !== $this->blocks;
    }

    /**
     * Type "enum": the label of a value (the value itself if it is unknown).
     */
    public function label(string $value): string
    {
        return $this->options[$value] ?? $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['name'] ?? ''),
            (string)($data['label'] ?? $data['name'] ?? ''),
            (string)($data['type'] ?? 'string'),
            (bool)($data['required'] ?? false),
            (bool)($data['unique'] ?? false),
            (bool)($data['repeatable'] ?? false),
            (bool)($data['translatable'] ?? false),
            is_string($data['reference'] ?? null) ? $data['reference'] : null,
            $data,
            array_column(array_filter(is_array($data['options'] ?? null) ? $data['options'] : [], is_array(...)), 'label', 'value'),
            (bool)($data['searchable'] ?? true),
            (bool)($data['filterable'] ?? true),
            array_column(array_filter(is_array($data['blocks'] ?? null) ? $data['blocks'] : [], is_array(...)), 'label', 'name'),
        );
    }
}
