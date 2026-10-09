<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Tests\Unit;

use ExcellentCms\Sdk\Auth\BearerToken;
use ExcellentCms\Sdk\Exception\AccessDeniedException;
use ExcellentCms\Sdk\Exception\ApiException;
use ExcellentCms\Sdk\Exception\InvalidArgumentException;
use ExcellentCms\Sdk\MediaFile;
use ExcellentCms\Sdk\Tests\Support\ClientFactory;
use ExcellentCms\Sdk\Tests\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

final class MediaTest extends TestCase
{
    use ClientFactory;

    private const FILE = ['id' => 'm1', 'url' => 'https://cms.example.com/media/bibliothek/2026/10/m1.png', 'name' => 'logo.png', 'mime_type' => 'image/png', 'size' => 1234, 'width' => 40, 'height' => 30, 'is_image' => true, 'kept' => false];

    public function testUploadSendsMultipart(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sdk').'.png';
        file_put_contents($path, "\x89PNG-binary\r\n--not-a-boundary\0");
        $http = (new FakeHttpClient())->success(self::FILE, status: 201);

        $file = $this->client($http)->media()->upload($path, 'Logo "ACME".png', entity: 'partners', field: 'logo', keep: true);
        unlink($path);

        $this->assertSame(['m1', 'image/png', 40, true, false], [$file->id, $file->mimeType, $file->width, $file->isImage, $file->kept]);
        $request = $http->last();
        $this->assertSame(['POST', 'https://cms.example.com/api/v1/bibliothek/media'], [$request->getMethod(), (string)$request->getUri()]);
        $this->assertMatchesRegularExpression('/^multipart\/form-data; boundary=(excellent[0-9a-f]{24})$/', $request->getHeaderLine('Content-Type'));
        $parts = self::parts($request->getHeaderLine('Content-Type'), (string)$request->getBody());
        $this->assertSame(['entity' => 'partners', 'field' => 'logo', 'keep' => '1'], $parts['fields']);
        $this->assertSame('Logo %22ACME%22.png', $parts['filename']);
        $this->assertSame("\x89PNG-binary\r\n--not-a-boundary\0", $parts['file']);
    }

    public function testUploadFromStreamsGetAndDelete(): void
    {
        $http = (new FakeHttpClient())
            ->success(self::FILE, status: 201)
            ->success(self::FILE, status: 201)
            ->success(self::FILE + ['usage_count' => 2])
            ->failure(409, 'media_in_use', '„logo.png“ wird noch 2× verwendet.')
            ->failure(404, 'not_found');
        $media = $this->client($http)->media();

        $resource = fopen('php://memory', 'w+b');
        fwrite($resource, 'Hallo');
        rewind($resource);
        $media->upload($resource);
        $this->assertSame(['fields' => [], 'filename' => 'datei', 'file' => 'Hallo'], self::parts($http->last()->getHeaderLine('Content-Type'), (string)$http->last()->getBody()));
        $this->assertIsResource($resource, 'streams of the caller stay open');

        $media->upload(Utils::streamFor('Welt'), 'gruss.txt');
        $this->assertSame('Welt', self::parts($http->last()->getHeaderLine('Content-Type'), (string)$http->last()->getBody())['file']);

        $this->assertSame(2, $media->get('m1')->usageCount);
        $this->assertSame('https://cms.example.com/api/v1/bibliothek/media/m1', (string)$http->last()->getUri());

        try {
            $media->delete('m1');
            $this->fail('no exception');
        } catch (ApiException $e) {
            $this->assertSame([409, 'media_in_use'], [$e->status(), $e->errorCode()]);
            $this->assertSame('DELETE', $http->last()->getMethod());
        }
        $this->assertNull($media->find('m1'));
    }

    public function testInvalidUploads(): void
    {
        $media = $this->client(new FakeHttpClient())->media();
        foreach ([
            static fn() => $media->upload('/does/not/exist.png'),
            static fn() => $media->upload(42),
            static fn() => $media->upload(Utils::streamFor('x'), entity: 'partners'),
            static fn() => $media->delete(' '),
        ] as $call) {
            try {
                $call();
                $this->fail('no exception');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDownloadWithoutTokenAndSignedUrls(): void
    {
        $signed = ['url' => 'https://cms.example.com/media/bibliothek/2026/10/m1.png?expires=1791417600&signature=abc'] + self::FILE;
        $http = (new FakeHttpClient())
            ->push(new Response(200, ['Content-Type' => 'image/png'], 'PNG-1'))
            ->push(new Response(200, ['Content-Type' => 'image/png'], 'PNG-2'))
            ->success($signed)
            ->push(new Response(200, [], 'PNG-3'))
            ->push(new Response(403, [], 'Forbidden'));
        $media = $this->client($http, new BearerToken('geheim'))->media();

        $file = MediaFile::fromArray($signed);
        $this->assertTrue($file->isSigned());
        $this->assertSame('2026-10-08T00:00:00+00:00', $file->expiresAt()?->format(DATE_ATOM));
        $this->assertFalse(MediaFile::fromArray(self::FILE)->isSigned());

        $this->assertSame('PNG-1', $media->download($file));
        $this->assertSame($signed['url'], (string)$http->last()->getUri());
        $this->assertFalse($http->last()->hasHeader('Authorization'), 'the address is enough, the token stays with the API');

        $path = tempnam(sys_get_temp_dir(), 'sdk');
        $media->downloadTo(self::FILE['url'], $path);
        $this->assertSame('PNG-2', file_get_contents($path));
        unlink($path);

        // An id: the file is looked up first (with the token), then downloaded by its signed url
        $this->assertSame('PNG-3', $media->download('m1'));
        $this->assertSame('Bearer geheim', $http->requests[2]->getHeaderLine('Authorization'));
        $this->assertSame($signed['url'], (string)$http->last()->getUri());

        $this->expectException(AccessDeniedException::class);
        $media->download(self::FILE['url']);
    }

    /**
     * Minimal multipart parser - like PHP reads the body on the server.
     *
     * @return array{fields: array<string, string>, filename: ?string, file: ?string}
     */
    private static function parts(string $contentType, string $body): array
    {
        $boundary = substr($contentType, strpos($contentType, 'boundary=') + 9);
        $result = ['fields' => [], 'filename' => null, 'file' => null];
        foreach (array_slice(explode("--{$boundary}", $body), 1, -1) as $part) {
            [$headers, $content] = explode("\r\n\r\n", substr($part, 2), 2);
            $content = substr($content, 0, -2);
            preg_match('/name="([^"]*)"(?:; filename="([^"]*)")?/', $headers, $match);
            if (isset($match[2])) {
                $result['filename'] = $match[2];
                $result['file'] = $content;
            } else {
                $result['fields'][$match[1]] = $content;
            }
        }
        return $result;
    }

    public function testTransformBuildsTheAddress(): void
    {
        $url = 'https://cms.example.com/media/bibliothek/2026/10/m1.png';
        $file = MediaFile::fromArray(['transform_url' => $url] + self::FILE);
        $this->assertTrue($file->canTransform());
        $this->assertSame($url.'?w=800&h=450&format=webp', $file->transform(800, 450, format: 'webp'));
        $this->assertSame($url.'?w=128&h=128&fit=contain&bg=fff', $file->transform(128, 128, fit: 'contain', background: '#fff'));
        $this->assertSame($url, $file->transform());

        $this->assertNull($file->focalPoint());
        $focused = MediaFile::fromArray(['transform_url' => $url.'?fp=0.3,0.6', 'focal_point' => ['x' => 0.3, 'y' => 0.6]] + self::FILE);
        $this->assertSame(['x' => 0.3, 'y' => 0.6], $focused->focalPoint());
        $this->assertSame($url.'?fp=0.3,0.6&w=100&h=100', $focused->transform(100, 100));

        $signed = MediaFile::fromArray(['transform_url' => $url.'?expires=1&signature=abc'] + self::FILE);
        $this->assertSame($url.'?expires=1&signature=abc&w=10', $signed->transform(10));

        $pdf = MediaFile::fromArray(['transform_url' => null, 'mime_type' => 'application/pdf'] + self::FILE);
        $this->assertFalse($pdf->canTransform());
        $this->expectException(\LogicException::class);
        $pdf->transform(10);
    }
}
