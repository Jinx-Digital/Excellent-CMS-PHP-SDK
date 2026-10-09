<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk;

/**
 * One page of a list, with the totals of the whole list.
 *
 * @implements \IteratorAggregate<int, Record>
 */
final class Page implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Record> $records
     */
    public function __construct(
        public readonly array $records,
        public readonly int $currentPage,
        public readonly int $totalPages,
        public readonly int $totalItems,
        public readonly int $pageSize,
        private readonly ?Query $query = null,
    ) {
    }

    public function first(): ?Record
    {
        return $this->records[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->records;
    }

    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->totalPages;
    }

    /**
     * The next page of the same query (null: this is the last one).
     */
    public function nextPage(): ?self
    {
        return $this->hasNextPage() && null !== $this->query ? $this->query->page($this->currentPage + 1)->get() : null;
    }

    /**
     * Records on this page.
     */
    public function count(): int
    {
        return count($this->records);
    }

    /**
     * @return \ArrayIterator<int, Record>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->records);
    }
}
