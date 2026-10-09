<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Tests\Unit;

use ExcellentCms\Sdk\Exception\AccessDeniedException;
use ExcellentCms\Sdk\Exception\ApiException;
use ExcellentCms\Sdk\Exception\AuthenticationException;
use ExcellentCms\Sdk\Exception\ExcellentException;
use ExcellentCms\Sdk\Exception\NotFoundException;
use ExcellentCms\Sdk\Exception\RateLimitException;
use ExcellentCms\Sdk\Exception\TransportException;
use ExcellentCms\Sdk\Exception\ValidationException;
use ExcellentCms\Sdk\Tests\Support\ClientFactory;
use ExcellentCms\Sdk\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class ClientTest extends TestCase
{
    use ClientFactory;

    public function testListRequestAndPage(): void
    {
        $http = (new FakeHttpClient())->success(
            [['id' => 'a1', 'title' => 'Emma', 'author' => ['id' => 'p1', 'name' => 'Jane Austen'], 'created_at' => '2026-10-05 07:55:19', 'updated_at' => '2026-10-05 08:00:00']],
            ['page_size' => 1, 'current_page' => 2, 'total_pages' => 3, 'total_items' => 3],
        );
        $page = $this->client($http)->entity('books')->where('author.name', 'Jane Austen')->with('author')->limit(1)->page(2)->get();

        $request = $http->last();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://cms.example.com/api/v1/bibliothek/content/books', (string)$request->getUri()->withQuery(''));
        $this->assertSame(['filter' => ['author' => ['name' => ['eq' => 'Jane Austen']]], 'include' => 'author', 'page' => '2', 'limit' => '1'], FakeHttpClient::query($request));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertStringStartsWith('excellent-cms-php-sdk/', $request->getHeaderLine('User-Agent'));
        $this->assertFalse($request->hasHeader('Authorization'), 'public entities need no token');

        $this->assertSame([2, 3, 3, 1], [$page->currentPage, $page->totalPages, $page->totalItems, $page->pageSize]);
        $this->assertTrue($page->hasNextPage());
        $book = $page->first();
        $this->assertNotNull($book);
        $this->assertSame(['a1', 'Emma', 'Emma', 'Jane Austen'], [$book->id(), $book['title'], $book->title, $book->get('author.name')]);
        $this->assertSame('Jane Austen', $book->record('author')?->get('name'));
        $this->assertSame('2026-10-05 07:55:19 UTC', $book->createdAt()?->format('Y-m-d H:i:s T'));
        $this->assertNull($book->get('nope.deeper'));
    }

    public function testUrlsWithApiPathAndEncodedSegments(): void
    {
        $http = (new FakeHttpClient())->success(['id' => 'x/y']);
        $this->client($http, url: 'https://cms.example.com/api/v1/')->entity('books')->get('x/y', ['title'], ['author'], 'de');
        $this->assertSame('https://cms.example.com/api/v1/bibliothek/content/books/x%2Fy?fields=title&include=author&lang=de', (string)$http->last()->getUri());
    }

    public function testCursorWalksThroughAllPages(): void
    {
        $http = (new FakeHttpClient())
            ->success([['id' => '1'], ['id' => '2']], ['page_size' => 2, 'current_page' => 1, 'total_pages' => 2, 'total_items' => 3])
            ->success([['id' => '3']], ['page_size' => 2, 'current_page' => 2, 'total_pages' => 2, 'total_items' => 3]);
        $ids = array_map(static fn($record) => $record->id(), $this->client($http)->entity('books')->orderBy('title')->limit(2)->all());

        $this->assertSame(['1', '2', '3'], $ids);
        $this->assertCount(2, $http->requests);
        $this->assertSame(['sort' => 'title', 'page' => '2', 'limit' => '2'], FakeHttpClient::query($http->requests[1]));
    }

    public function testCursorUsesLargestPagesAndCountOneRecord(): void
    {
        $http = (new FakeHttpClient())
            ->success([], ['page_size' => 200, 'current_page' => 1, 'total_pages' => 1, 'total_items' => 0])
            ->success([['id' => '1']], ['page_size' => 1, 'current_page' => 1, 'total_pages' => 249, 'total_items' => 249]);
        $books = $this->client($http)->entity('books');

        $this->assertSame([], iterator_to_array($books->cursor()));
        $this->assertSame('200', FakeHttpClient::query($http->requests[0])['limit']);
        $this->assertSame(249, $books->where('year', '>', 2000)->count());
        $this->assertSame('1', FakeHttpClient::query($http->requests[1])['limit']);
    }

    public function testTreeIsNested(): void
    {
        $http = (new FakeHttpClient())->success([['id' => 'w', 'name' => 'World', 'children' => [['id' => 'e', 'name' => 'Europe', 'children' => []]]]]);
        $tree = $this->client($http)->entity('regions')->orderBy('name')->limit(5)->tree();

        // Paging does not apply to trees
        $this->assertSame(['sort' => 'name', 'tree' => '1'], FakeHttpClient::query($http->last()));
        $this->assertSame('Europe', $tree[0]->children()[0]->get('name'));
    }

    public function testWriting(): void
    {
        $http = (new FakeHttpClient())
            ->success(['id' => 'n1', 'title' => 'Emma'], status: 201)
            ->success(['id' => 'n1', 'title' => 'Emma (Neuausgabe)'])
            ->success(['deleted' => true]);
        $books = $this->client($http)->entity('books');

        $created = $books->create(['title' => 'Emma', 'year' => 1815, 'price' => 12.0], 'de');
        $this->assertSame('n1', $created->id());
        $this->assertSame('POST', $http->last()->getMethod());
        $this->assertSame('https://cms.example.com/api/v1/bibliothek/content/books?lang=de', (string)$http->last()->getUri());
        $this->assertSame('application/json', $http->last()->getHeaderLine('Content-Type'));
        $this->assertSame('{"title":"Emma","year":1815,"price":12.0}', (string)$http->last()->getBody());

        $this->assertSame('Emma (Neuausgabe)', $books->update('n1', ['title' => 'Emma (Neuausgabe)'])->title);
        $this->assertSame('PATCH', $http->last()->getMethod());

        $books->delete('n1');
        $this->assertSame(['DELETE', 'https://cms.example.com/api/v1/bibliothek/content/books/n1'], [$http->last()->getMethod(), (string)$http->last()->getUri()]);
    }

    public function testErrorsBecomeExceptions(): void
    {
        $http = (new FakeHttpClient())
            ->failure(422, 'validation', 'Der Filter passt nicht zu den Feldern.', ['filter.author.nope' => ['Das Feld gibt es nicht.']])
            ->failure(404, 'not_found')
            ->failure(401, 'unauthorized')
            ->failure(403, 'forbidden')
            ->failure(429, 'rate_limited', headers: ['Retry-After' => '17'])
            ->failure(409, 'record_in_use', 'Wird noch verwendet.')
            ->failure(404, 'not_found');
        $books = $this->client($http)->entity('books');

        try {
            $books->where('author.nope', 1)->get();
            $this->fail('no exception');
        } catch (ValidationException $e) {
            $this->assertSame('Der Filter passt nicht zu den Feldern.', $e->getMessage());
            $this->assertSame(['filter.author.nope' => ['Das Feld gibt es nicht.']], $e->errors());
            $this->assertSame([422, 'validation'], [$e->status(), $e->errorCode()]);
        }
        $this->assertException(NotFoundException::class, static fn() => $books->get('x'));
        $this->assertException(AuthenticationException::class, static fn() => $books->get('x'));
        $this->assertException(AccessDeniedException::class, static fn() => $books->get('x'));
        $rateLimit = $this->assertException(RateLimitException::class, static fn() => $books->get('x'));
        $this->assertSame(17, $rateLimit->retryAfter());
        $conflict = $this->assertException(ApiException::class, static fn() => $books->delete('x'));
        $this->assertSame([409, 'record_in_use', 'Wird noch verwendet.'], [$conflict->status(), $conflict->errorCode(), $conflict->getMessage()]);
        $this->assertNull($books->find('x'), 'find() returns null for a missing record');
    }

    public function testTransportProblems(): void
    {
        $network = new class ('offline') extends \RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return new Request('GET', 'https://cms.example.com');
            }
        };
        $http = (new FakeHttpClient())->push($network)->push(new Response(502, [], '<html>Bad Gateway</html>'));
        $books = $this->client($http)->entity('books');

        $this->assertException(TransportException::class, static fn() => $books->get('x'));
        $error = $this->assertException(TransportException::class, static fn() => $books->get('x'));
        $this->assertStringContainsString('HTTP 502', $error->getMessage());
        $this->assertInstanceOf(ExcellentException::class, $error);
    }

    public function testSchemaVariablesAndRateLimit(): void
    {
        $entities = [['slug' => 'books', 'name' => 'Bücher', 'access' => 'oauth', 'label_field' => 'title', 'tree_field' => null, 'languages' => ['en', 'de'], 'fields' => [
            ['name' => 'title', 'label' => 'Titel', 'type' => 'string', 'required' => true, 'unique' => false, 'repeatable' => false, 'translatable' => true, 'reference' => null],
            ['name' => 'author', 'label' => 'Autor', 'type' => 'reference', 'required' => false, 'unique' => false, 'repeatable' => false, 'translatable' => false, 'reference' => 'authors'],
        ]]];
        $http = (new FakeHttpClient())
            ->success($entities)
            ->json(['status' => 'success', 'success' => true, 'data' => ['url' => 'https://example.com']], 200, ['X-RateLimit-Limit' => '120', 'X-RateLimit-Remaining' => '119', 'X-RateLimit-Reset' => '1791294360'])
            ->success($entities);
        $cms = $this->client($http);

        $books = $cms->entities()[0];
        $this->assertSame(['books', 'title', false, ['en', 'de']], [$books->slug, $books->labelField, $books->isPublic(), $books->languages]);
        $this->assertSame('authors', $books->field('author')?->reference);
        $this->assertTrue($books->field('title')?->translatable);

        $this->assertSame(['url' => 'https://example.com'], $cms->variables('de'));
        $this->assertSame('https://cms.example.com/api/v1/bibliothek/variables?lang=de', (string)$http->last()->getUri());
        $this->assertSame([120, 119, 1791294360], [$cms->rateLimit()?->limit, $cms->rateLimit()?->remaining, $cms->rateLimit()?->reset]);

        $this->assertException(NotFoundException::class, static fn() => $cms->schema('nope'));
    }

    /**
     * @template T of \Throwable
     * @param class-string<T> $class
     * @return T
     */
    private function assertException(string $class, \Closure $call): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
            return $e;
        }
        $this->fail("{$class} expected");
    }
}
