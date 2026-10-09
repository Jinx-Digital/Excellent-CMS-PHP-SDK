<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Tests\Integration;

use ExcellentCms\Sdk\Auth\ClientCredentials;
use ExcellentCms\Sdk\Client;
use ExcellentCms\Sdk\EntitySchema;
use ExcellentCms\Sdk\Exception\NotFoundException;
use ExcellentCms\Sdk\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Against a running Excellent CMS - works with any data:
 *
 *     EXCELLENT_URL=http://localhost:8090 EXCELLENT_PROJECT=main vendor/bin/phpunit --testsuite integration
 *
 * With EXCELLENT_CLIENT_ID / EXCELLENT_CLIENT_SECRET the token is used, with EXCELLENT_WRITE_ENTITY
 * (and a client that may create, update and delete there) records are written and deleted again.
 * EXCELLENT_WRITE_DATA: JSON of a valid new record, default {"name": "SDK test …"}.
 * EXCELLENT_MEDIA=1: uploads and deletes a file (the client needs both media permissions).
 */
final class ContentApiTest extends TestCase
{
    private Client $cms;

    protected function setUp(): void
    {
        $url = getenv('EXCELLENT_URL');
        $project = getenv('EXCELLENT_PROJECT');
        if (!$url || !$project) {
            $this->markTestSkipped('EXCELLENT_URL and EXCELLENT_PROJECT are not set.');
        }
        $id = getenv('EXCELLENT_CLIENT_ID');
        $secret = getenv('EXCELLENT_CLIENT_SECRET');
        $this->cms = new Client($url, $project, $id && $secret ? new ClientCredentials($id, $secret) : null);
    }

    public function testReadingListsAndRecords(): void
    {
        $entity = $this->entityWithRecords();
        $books = $this->cms->entity($entity->slug);

        $page = $books->limit(2)->get();
        $this->assertGreaterThan(0, $page->totalItems);
        $this->assertLessThanOrEqual(2, count($page));
        $this->assertSame($page->totalItems, $books->count());

        $first = $page->first();
        $this->assertNotNull($first);
        $this->assertSame($first->id(), $books->get($first->id())->id());
        $this->assertNull($books->find('does-not-exist'));

        $label = $entity->labelField;
        if (null !== $label && is_string($first[$label])) {
            $matches = $books->where($label, $first[$label])->all();
            $this->assertContains($first->id(), array_map(static fn($record) => $record->id(), $matches));
            // Descending: the first one is not smaller than the last one (collation of MySQL)
            $sorted = $books->orderByDesc($label)->limit(5)->get()->records;
            $this->assertNotEmpty($sorted);
        }

        // Paging through everything gives every record once
        $ids = array_map(static fn($record) => $record->id(), iterator_to_array($books->limit(7)->cursor(), false));
        $this->assertCount($page->totalItems, $ids);
        $this->assertCount($page->totalItems, array_unique($ids));
    }

    public function testFiltersAndSortingOnReferences(): void
    {
        foreach ($this->cms->entities() as $entity) {
            foreach ($entity->fields as $field) {
                if ('reference' !== $field->type || $field->repeatable || null === $field->reference) {
                    continue;
                }
                $target = $this->schema($field->reference);
                $label = $target?->labelField;
                if (null === $label) {
                    continue;
                }
                $record = $this->cms->entity($entity->slug)->whereNotNull($field->name)->with($field->name)->first();
                $referenced = $record?->record($field->name);
                if (null === $referenced || !is_string($referenced[$label])) {
                    continue;
                }

                $matches = $this->cms->entity($entity->slug)->where("{$field->name}.{$label}", $referenced[$label])->with($field->name)->all();
                $this->assertNotEmpty($matches);
                foreach ($matches as $match) {
                    $this->assertSame(mb_strtolower($referenced[$label]), mb_strtolower((string)$match->get("{$field->name}.{$label}")));
                }
                // By the display field of the referenced record, and by a path
                $this->assertGreaterThan(0, count($this->cms->entity($entity->slug)->orderBy($field->name)->limit(3)->get()));
                $this->assertGreaterThan(0, count($this->cms->entity($entity->slug)->orderByDesc("{$field->name}.{$label}")->limit(3)->get()));

                try {
                    $this->cms->entity($entity->slug)->where("{$field->name}.does_not_exist", 1)->get();
                    $this->fail('unknown field of a reference');
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey("filter.{$field->name}.does_not_exist", $e->errors());
                }
                return;
            }
        }
        $this->markTestSkipped('No readable entity with a filled reference to an entity with a display field.');
    }

    public function testWritingRecords(): void
    {
        $slug = getenv('EXCELLENT_WRITE_ENTITY');
        if (!$slug) {
            $this->markTestSkipped('EXCELLENT_WRITE_ENTITY is not set.');
        }
        $data = json_decode(getenv('EXCELLENT_WRITE_DATA') ?: 'null', true) ?? ['name' => 'SDK test '.bin2hex(random_bytes(4))];
        $entity = $this->cms->entity($slug);

        $created = $entity->create($data);
        try {
            $this->assertNotSame('', $created->id());
            $field = (string)array_key_first($data);
            $this->assertSame($data[$field], $created[$field]);

            $updated = $entity->update($created->id(), [$field => $data[$field].' (geändert)']);
            $this->assertSame($data[$field].' (geändert)', $updated[$field]);
            $this->assertSame($updated[$field], $entity->get($created->id())[$field]);
        } finally {
            $entity->delete($created->id());
        }
        $this->expectException(NotFoundException::class);
        $entity->get($created->id());
    }

    public function testUploadingAndDeletingMedia(): void
    {
        if (!getenv('EXCELLENT_MEDIA')) {
            $this->markTestSkipped('EXCELLENT_MEDIA is not set (needs a client that may upload and delete media).');
        }
        // A real 1x1 PNG - the CMS detects the type from the content
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, (string)$png);
        rewind($stream);

        $file = $this->cms->media()->upload($stream, 'sdk-test.png');
        $this->assertSame(['image/png', 1, 1, true], [$file->mimeType, $file->width, $file->height, $file->isImage]);
        $this->assertStringStartsWith('http', $file->url);
        $this->assertSame(0, $this->cms->media()->get($file->id)->usageCount);

        $this->cms->media()->delete($file->id);
        $this->assertNull($this->cms->media()->find($file->id));
    }

    private function entityWithRecords(): EntitySchema
    {
        foreach ($this->cms->entities() as $entity) {
            if ($this->cms->entity($entity->slug)->count() > 0) {
                return $entity;
            }
        }
        $this->markTestSkipped('No readable entity has records.');
    }

    private function schema(string $slug): ?EntitySchema
    {
        foreach ($this->cms->entities() as $entity) {
            if ($entity->slug === $slug) {
                return $entity;
            }
        }
        return null;
    }
}
