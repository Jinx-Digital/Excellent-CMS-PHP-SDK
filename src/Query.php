<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk;

use ExcellentCms\Sdk\Exception\InvalidArgumentException;

/**
 * List query of an entity. Immutable - every method returns a new query:
 *
 *     $books = $cms->entity('books')
 *         ->where('author.name', 'Jane Austen')        // field of the referenced author
 *         ->where('year', '>=', 1800)
 *         ->whereIn('genre.slug', ['novel', 'satire'])
 *         ->orderBy('author')                           // by the author's display field
 *         ->orderByDesc('year')
 *         ->with('author')
 *         ->lang('de')
 *         ->limit(50)
 *         ->get();
 *
 * Paths to fields of referenced records use dots or brackets ("country.region.name" or
 * "country[region][name]"), up to three levels.
 */
final class Query
{
    /** `lang()`: every language at once, e.g. {"de": "…", "en": "…"} */
    public const ALL_LANGUAGES = 'all';

    /** Largest page the API returns */
    public const MAX_LIMIT = 200;

    private const OPERATORS = [
        '=' => 'eq', '==' => 'eq', 'eq' => 'eq',
        '!=' => 'ne', '<>' => 'ne', 'ne' => 'ne',
        '>' => 'gt', 'gt' => 'gt',
        '>=' => 'gte', 'gte' => 'gte',
        '<' => 'lt', 'lt' => 'lt',
        '<=' => 'lte', 'lte' => 'lte',
        'like' => 'like',
        'in' => 'in',
        'null' => 'null',
    ];

    /** @var array<string, mixed> */
    private array $filter = [];
    /** @var list<string> */
    private array $sort = [];
    private ?string $search = null;
    /** @var list<string> */
    private array $fields = [];
    /** @var list<string> */
    private array $with = [];
    private ?string $language = null;
    private int $page = 1;
    private ?int $limit = null;

    private bool $html = false;

    public function __construct(private readonly Entity $entity)
    {
    }

    /**
     * where('title', 'Emma') - equals
     * where('year', '>=', 1800) - with one of =, !=, >, >=, <, <=, like, in (or eq, ne, gt …)
     * where('author.name', 'like', 'aus') - fields of referenced records
     *
     * Values: strings, numbers, booleans, DateTimeInterface (sent as ISO 8601), null (empty).
     */
    public function where(string $field, mixed $operator, mixed $value = null): self
    {
        if (2 === func_num_args()) {
            [$operator, $value] = ['eq', $operator];
        }
        $name = self::OPERATORS[strtolower((string)$operator)] ?? throw new InvalidArgumentException(sprintf(
            'Unknown operator "%s" - use one of %s.',
            (string)$operator,
            implode(', ', array_keys(self::OPERATORS)),
        ));
        if (null === $value && 'eq' === $name) {
            return $this->whereNull($field);
        }
        if (null === $value && 'ne' === $name) {
            return $this->whereNotNull($field);
        }
        if ('in' === $name) {
            return $this->whereIn($field, is_iterable($value) ? $value : [$value]);
        }
        if ('null' === $name) {
            return $this->withCondition($field, 'null', self::boolean((bool)$value));
        }
        return $this->withCondition($field, $name, self::value($value));
    }

    /**
     * One of the values. For repeatable fields: contains one of them.
     *
     * @param iterable<mixed> $values
     */
    public function whereIn(string $field, iterable $values): self
    {
        $list = [];
        foreach ($values as $value) {
            $list[] = self::value($value);
        }
        if ([] === $list) {
            throw new InvalidArgumentException(sprintf('whereIn("%s") needs at least one value.', $field));
        }
        return $this->withCondition($field, 'in', $list);
    }

    public function whereNull(string $field): self
    {
        return $this->withCondition($field, 'null', 'true');
    }

    public function whereNotNull(string $field): self
    {
        return $this->withCondition($field, 'null', 'false');
    }

    /**
     * Contains the text (not case-sensitive).
     */
    public function whereLike(string $field, string $text): self
    {
        return $this->withCondition($field, 'like', $text);
    }

    /**
     * From `$min` to `$max`, both included.
     */
    public function whereBetween(string $field, mixed $min, mixed $max): self
    {
        return $this->withCondition($field, 'gte', self::value($min))->withCondition($field, 'lte', self::value($max));
    }

    /**
     * Filter in the format of the API, merged with the others: ['price' => ['gte' => 10]].
     *
     * @param array<string, mixed> $filter
     */
    public function filter(array $filter): self
    {
        $query = clone $this;
        $query->filter = array_replace_recursive($query->filter, $filter);
        return $query->firstPage();
    }

    /**
     * Text search in all text fields.
     */
    public function search(string $text): self
    {
        $query = clone $this;
        $query->search = '' !== trim($text) ? $text : null;
        return $query->firstPage();
    }

    /**
     * orderBy('title'), orderBy('year', 'desc'), orderBy('author') - a reference by the display
     * field of the referenced record -, orderBy('author.name'). Several calls: first one first.
     */
    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);
        if ('asc' !== $direction && 'desc' !== $direction) {
            throw new InvalidArgumentException(sprintf('Sort direction must be "asc" or "desc", not "%s".', $direction));
        }
        $query = clone $this;
        $query->sort[] = ('desc' === $direction ? '-' : '').self::sortPath($field);
        return $query->firstPage();
    }

    public function orderByDesc(string $field): self
    {
        return $this->orderBy($field, 'desc');
    }

    /**
     * Sorting in the format of the API, replaces the others: "-year,author[name]".
     */
    public function sort(string $sort): self
    {
        $query = clone $this;
        $query->sort = array_values(array_filter(array_map(trim(...), explode(',', $sort)), static fn(string $part): bool => '' !== $part));
        return $query->firstPage();
    }

    /**
     * Only these fields (`id`, `created_at` and `updated_at` always come along).
     */
    public function select(string ...$fields): self
    {
        $query = clone $this;
        $query->fields = array_values(array_unique([...$query->fields, ...$fields]));
        return $query;
    }

    /**
     * Load referenced records along: with('author', 'genres') - their values instead of the ids.
     */
    public function with(string ...$references): self
    {
        $query = clone $this;
        $query->with = array_values(array_unique([...$query->with, ...$references]));
        return $query;
    }

    /**
     * Language of translatable fields (empty ones fall back to the default language), also for
     * search, filters and sorting. Query::ALL_LANGUAGES: every language as an object.
     */
    public function lang(?string $language): self
    {
        $query = clone $this;
        $query->language = $language;
        return $query;
    }

    public function page(int $page): self
    {
        if ($page < 1) {
            throw new InvalidArgumentException('The page starts at 1.');
        }
        $query = clone $this;
        $query->page = $page;
        return $query;
    }

    /**
     * Records per page, 1 to 200 (default of the API: 25).
     */
    public function limit(int $limit): self
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('The limit must be between 1 and %d.', self::MAX_LIMIT));
        }
        $query = clone $this;
        $query->limit = $limit;
        return $query;
    }

    /**
     * Blocks with the HTML of their templates in the CMS (Block::html(), used by BlockRenderer for
     * types without a template of the website).
     */
    public function html(bool $html = true): self
    {
        $query = clone $this;
        $query->html = $html;
        return $query;
    }

    public function get(): Page
    {
        $result = $this->entity->fetch($this->toParameters());
        $meta = $result['meta'] ?? [];
        $records = array_values(array_map(static fn(array $row): Record => new Record($row), array_filter(is_array($result['data']) ? $result['data'] : [], is_array(...))));
        return new Page(
            $records,
            (int)($meta['current_page'] ?? $this->page),
            (int)($meta['total_pages'] ?? 1),
            (int)($meta['total_items'] ?? count($records)),
            (int)($meta['page_size'] ?? $this->limit ?? count($records)),
            $this,
        );
    }

    public function first(): ?Record
    {
        return $this->limit(1)->page(1)->get()->first();
    }

    /**
     * Number of matching records (one small request).
     */
    public function count(): int
    {
        return $this->limit(1)->page(1)->get()->totalItems;
    }

    /**
     * Every matching record, page by page while you iterate (200 per request unless `limit()` says
     * otherwise). Starts at the page of the query.
     *
     * @return \Generator<int, Record>
     */
    public function cursor(): \Generator
    {
        $page = ($this->limit ? $this : $this->limit(self::MAX_LIMIT))->get();
        while (true) {
            yield from $page->records;
            $next = $page->nextPage();
            if (null === $next) {
                return;
            }
            $page = $next;
        }
    }

    /**
     * Every matching record at once - for small lists, use cursor() for large ones.
     *
     * @return list<Record>
     */
    public function all(): array
    {
        return iterator_to_array($this->cursor(), false);
    }

    /**
     * Tree entities: all matching records nested by their parent field, each with `children()`
     * (at most 10,000 - paging does not apply). whereNull('parent') is not needed.
     *
     * @return list<Record>
     */
    public function tree(): array
    {
        $parameters = $this->toParameters();
        unset($parameters['page'], $parameters['limit']);
        $result = $this->entity->fetch($parameters + ['tree' => '1']);
        return array_values(array_map(static fn(array $row): Record => new Record($row), array_filter(is_array($result['data']) ? $result['data'] : [], is_array(...))));
    }

    /**
     * Query parameters as the API gets them - handy for debugging and own requests.
     *
     * @return array<string, mixed>
     */
    public function toParameters(): array
    {
        return array_filter([
            's' => $this->search,
            'filter' => $this->filter ?: null,
            'sort' => $this->sort ? implode(',', $this->sort) : null,
            'fields' => $this->fields ? implode(',', $this->fields) : null,
            'include' => $this->with ? implode(',', $this->with) : null,
            'lang' => $this->language,
            'page' => 1 !== $this->page ? $this->page : null,
            'limit' => $this->limit,
            'render' => $this->html ? 'html' : null,
        ], static fn(mixed $value): bool => null !== $value);
    }

    private function withCondition(string $field, string $operator, mixed $value): self
    {
        $query = clone $this;
        $node = &$query->filter;
        foreach (self::path($field) as $part) {
            if (!is_array($node[$part] ?? null)) {
                // A plain value from filter([...]) is an "equals"
                $node[$part] = isset($node[$part]) ? ['eq' => $node[$part]] : [];
            }
            $node = &$node[$part];
        }
        $node[$operator] = $value;
        unset($node);
        return $query->firstPage();
    }

    /**
     * A new filter or order changes the list - start at its first page again.
     */
    private function firstPage(): self
    {
        $this->page = 1;
        return $this;
    }

    /**
     * "country.region.name" or "country[region][name]" => ['country', 'region', 'name']
     *
     * @return non-empty-list<string>
     */
    private static function path(string $field): array
    {
        $parts = preg_split('/[.\[\]]+/', $field, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ([] === $parts || !preg_match('/^[A-Za-z0-9_]+(?:(?:\.[A-Za-z0-9_]+)|(?:\[[A-Za-z0-9_]+\]))*$/', $field)) {
            throw new InvalidArgumentException(sprintf('"%s" is no field name - use e.g. "title", "author.name" or "author[name]".', $field));
        }
        if (count($parts) > 3) {
            throw new InvalidArgumentException(sprintf('"%s" goes deeper than three levels.', $field));
        }
        return $parts;
    }

    private static function sortPath(string $field): string
    {
        $parts = self::path($field);
        $first = array_shift($parts);
        return $first.implode('', array_map(static fn(string $part): string => "[{$part}]", $parts));
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            is_bool($value) => self::boolean($value),
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \BackedEnum => (string)$value->value,
            is_scalar($value), $value instanceof \Stringable => (string)$value,
            default => throw new InvalidArgumentException(sprintf('A filter value cannot be %s.', get_debug_type($value))),
        };
    }

    private static function boolean(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
