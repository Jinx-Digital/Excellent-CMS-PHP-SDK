<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Tests\Unit;

use ExcellentCms\Sdk\Exception\InvalidArgumentException;
use ExcellentCms\Sdk\Query;
use ExcellentCms\Sdk\Tests\Support\ClientFactory;
use ExcellentCms\Sdk\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    use ClientFactory;

    private function query(): Query
    {
        return $this->client(new FakeHttpClient())->entity('books')->query();
    }

    public function testFiltersOnFieldsAndReferences(): void
    {
        $query = $this->query()
            ->where('title', 'Emma')
            ->where('year', '>=', 1800)
            ->where('year', '<', 1900)
            ->where('author.name', 'like', 'aus')
            ->where('author[country][alpha2code]', 'GB')
            ->whereIn('genres.slug', ['novel', 'satire'])
            ->whereNull('subtitle')
            ->whereNotNull('cover')
            ->where('available', true)
            ->where('published_at', '>', new \DateTimeImmutable('2020-01-02 03:04:05', new \DateTimeZone('UTC')));

        $this->assertSame([
            'filter' => [
                'title' => ['eq' => 'Emma'],
                'year' => ['gte' => '1800', 'lt' => '1900'],
                'author' => ['name' => ['like' => 'aus'], 'country' => ['alpha2code' => ['eq' => 'GB']]],
                'genres' => ['slug' => ['in' => ['novel', 'satire']]],
                'subtitle' => ['null' => 'true'],
                'cover' => ['null' => 'false'],
                'available' => ['eq' => 'true'],
                'published_at' => ['gt' => '2020-01-02T03:04:05+00:00'],
            ],
        ], $query->toParameters());
    }

    public function testSeveralFieldsAtOnce(): void
    {
        $query = $this->query()->where([
            'name' => 'asd',
            'age' => 30,
            'available' => true,
            'deleted_at' => null,
            'genre' => ['novel', 'drama'],
            'author.name' => 'Austen',
        ])->where('year', '>', 1800);

        $this->assertSame([
            'filter' => [
                'name' => ['eq' => 'asd'],
                'age' => ['eq' => '30'],
                'available' => ['eq' => 'true'],
                'deleted_at' => ['null' => 'true'],
                'genre' => ['in' => ['novel', 'drama']],
                'author' => ['name' => ['eq' => 'Austen']],
                'year' => ['gt' => '1800'],
            ],
        ], $query->toParameters());
        // Directly on the entity too
        $this->assertSame(['filter' => ['name' => ['eq' => 'asd']]], $this->client(new FakeHttpClient())->entity('books')->where(['name' => 'asd'])->toParameters());
    }

    public function testSeveralFieldsNeedNamesAndNoOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->query()->where(['name' => 'asd'], '=');
    }

    public function testSeveralFieldsWithoutNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->query()->where(['asd', 30]);
    }

    public function testFieldWithoutValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a value');
        $this->query()->where('name');
    }

    public function testOperatorAliasesAndShortcuts(): void
    {
        $this->assertSame(['filter' => ['a' => ['ne' => 'x']]], $this->query()->where('a', '!=', 'x')->toParameters());
        $this->assertSame(['filter' => ['a' => ['ne' => 'x']]], $this->query()->where('a', 'ne', 'x')->toParameters());
        $this->assertSame(['filter' => ['a' => ['null' => 'true']]], $this->query()->where('a', null)->toParameters());
        $this->assertSame(['filter' => ['a' => ['null' => 'false']]], $this->query()->where('a', '!=', null)->toParameters());
        $this->assertSame(['filter' => ['a' => ['in' => ['1', '2']]]], $this->query()->where('a', 'in', [1, 2])->toParameters());
        $this->assertSame(['filter' => ['a' => ['like' => 'x']]], $this->query()->whereLike('a', 'x')->toParameters());
        $this->assertSame(['filter' => ['a' => ['gte' => '1', 'lte' => '5']]], $this->query()->whereBetween('a', 1, 5)->toParameters());
        // A plain value from filter() becomes "eq" when a comparison is added
        $this->assertSame(['filter' => ['a' => ['eq' => 'x', 'ne' => 'y']]], $this->query()->filter(['a' => 'x'])->where('a', '!=', 'y')->toParameters());
    }

    public function testSortingSelectionAndPaging(): void
    {
        $query = $this->query()
            ->search('stolz')
            ->orderBy('author')
            ->orderByDesc('author.country.name')
            ->orderBy('title', 'ASC')
            ->select('title', 'year')
            ->select('year', 'author')
            ->with('author')
            ->lang(Query::ALL_LANGUAGES)
            ->limit(50)
            ->page(3);

        $this->assertSame([
            's' => 'stolz',
            'sort' => 'author,-author[country][name],title',
            'fields' => 'title,year,author',
            'include' => 'author',
            'lang' => 'all',
            'page' => 3,
            'limit' => 50,
        ], $query->toParameters());
        $this->assertSame('-year', $query->sort('-year')->toParameters()['sort']);
    }

    public function testQueriesAreImmutableAndNewFiltersStartAtPageOne(): void
    {
        $base = $this->query()->page(4);
        $filtered = $base->where('year', 2000);

        $this->assertSame(['page' => 4], $base->toParameters());
        $this->assertArrayNotHasKey('page', $filtered->toParameters());
        $this->assertArrayNotHasKey('page', $base->orderBy('title')->toParameters());
        $this->assertSame(4, $base->select('title')->toParameters()['page']);
    }

    /**
     * @return iterable<string, array{\Closure(Query): mixed}>
     */
    public static function invalidUses(): iterable
    {
        yield 'operator' => [static fn(Query $q) => $q->where('a', '~', 'x')];
        yield 'field' => [static fn(Query $q) => $q->where('a b', 'x')];
        yield 'empty path part' => [static fn(Query $q) => $q->where('a..b', 'x')];
        yield 'too deep' => [static fn(Query $q) => $q->where('a.b.c.d', 'x')];
        yield 'empty in' => [static fn(Query $q) => $q->whereIn('a', [])];
        yield 'array value' => [static fn(Query $q) => $q->where('a', '>', [1])];
        yield 'direction' => [static fn(Query $q) => $q->orderBy('a', 'up')];
        yield 'limit' => [static fn(Query $q) => $q->limit(201)];
        yield 'page' => [static fn(Query $q) => $q->page(0)];
    }

    /**
     * @param \Closure(Query): mixed $use
     */
    #[DataProvider('invalidUses')]
    public function testInvalidUseFailsBeforeAnyRequest(\Closure $use): void
    {
        $this->expectException(InvalidArgumentException::class);
        $use($this->query());
    }

    public function testEnumLabels(): void
    {
        $field = \ExcellentCms\Sdk\FieldSchema::fromArray(['name' => 'status', 'type' => 'enum', 'options' => [['value' => 'open', 'label' => 'Offen'], ['value' => 'done', 'label' => 'Erledigt']]]);
        $this->assertSame(['open' => 'Offen', 'done' => 'Erledigt'], $field->options);
        $this->assertSame(['Offen', 'unbekannt'], [$field->label('open'), $field->label('unbekannt')]);
        $this->assertSame([], \ExcellentCms\Sdk\FieldSchema::fromArray(['name' => 'title'])->options);
        $this->assertSame([true, true], [\ExcellentCms\Sdk\FieldSchema::fromArray(['name' => 'title'])->searchable, \ExcellentCms\Sdk\FieldSchema::fromArray(['name' => 'title'])->filterable]);
        $hidden = \ExcellentCms\Sdk\FieldSchema::fromArray(['name' => 'code', 'searchable' => false, 'filterable' => false]);
        $this->assertSame([false, false], [$hidden->searchable, $hidden->filterable]);
    }
}
