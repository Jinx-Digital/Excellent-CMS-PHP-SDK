<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk;

use ExcellentCms\Sdk\Exception\InvalidArgumentException;
use ExcellentCms\Sdk\Exception\NotFoundException;
use ExcellentCms\Sdk\Internal\Transport;
use Psr\Http\Message\StreamInterface;

/**
 * Files of the project - the API client needs "Medien hochladen" / "Medien löschen":
 *
 *     $logo = $cms->media()->upload('/path/logo.png', entity: 'partners', field: 'logo');
 *     $cms->entity('partners')->create(['name' => 'ACME', 'logo' => $logo->id]);
 */
final class Media
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Uploads a file. With `$entity` and `$field` the allowed types of that media field are checked
     * at once. Files no record uses are removed after a day - unless `$keep` keeps them in the
     * media library.
     *
     * @param string|resource|StreamInterface $file path of a local file, an open stream or a PSR-7 stream
     * @param string|null $filename name shown in the CMS (default: the file's name)
     */
    public function upload(mixed $file, ?string $filename = null, ?string $entity = null, ?string $field = null, bool $keep = false): MediaFile
    {
        if ((null === $entity) !== (null === $field)) {
            throw new InvalidArgumentException('Pass both $entity and $field, or neither.');
        }
        [$stream, $name, $close] = self::open($file);
        try {
            $fields = array_filter(['entity' => $entity, 'field' => $field, 'keep' => $keep ? '1' : null], static fn(?string $value): bool => null !== $value);
            $data = $this->transport->upload(['media'], $fields, $stream, $filename ?? $name)['data'];
        } finally {
            if ($close) {
                fclose($stream);
            }
        }
        return MediaFile::fromArray(is_array($data) ? $data : []);
    }

    /**
     * The file with `kept` and `usageCount` - throws NotFoundException if there is none.
     */
    public function get(string $id): MediaFile
    {
        $data = $this->transport->call('GET', ['media', self::id($id)])['data'];
        return MediaFile::fromArray(is_array($data) ? $data : []);
    }

    public function find(string $id): ?MediaFile
    {
        try {
            return $this->get($id);
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * Content of a file. Files of public entities are open to everyone; protected ones carry a
     * signature in the `url` the API returned with the record - it expires (default after 1-2
     * days), so download soon or read the record again. An id is looked up with get() first
     * (needs "Medien hochladen").
     *
     * @param MediaFile|string $file the file, its url or its id
     * @throws Exception\AccessDeniedException protected file without a valid signature
     */
    public function download(MediaFile|string $file): string
    {
        return (string)$this->transport->download($this->url($file))->getBody();
    }

    /**
     * Saves a file (see download()) to a local path, in chunks.
     *
     * @param MediaFile|string $file the file, its url or its id
     */
    public function downloadTo(MediaFile|string $file, string $path): void
    {
        $body = $this->transport->download($this->url($file))->getBody();
        $target = @fopen($path, 'wb');
        if (false === $target) {
            throw new InvalidArgumentException(sprintf('"%s" cannot be written.', $path));
        }
        try {
            while (!$body->eof()) {
                fwrite($target, $body->read(1 << 20));
            }
        } finally {
            fclose($target);
        }
    }

    /**
     * Only files no record uses (otherwise ApiException 409 "media_in_use").
     */
    public function delete(string $id): void
    {
        $this->transport->call('DELETE', ['media', self::id($id)]);
    }

    /**
     * @param string|resource|StreamInterface $file
     * @return array{0: resource, 1: string, 2: bool} stream, file name, close it afterwards
     */
    private static function open(mixed $file): array
    {
        if (is_string($file)) {
            if (!is_file($file) || !is_readable($file)) {
                throw new InvalidArgumentException(sprintf('"%s" is no readable file.', $file));
            }
            $stream = fopen($file, 'rb');
            if (false === $stream) {
                throw new InvalidArgumentException(sprintf('"%s" cannot be opened.', $file));
            }
            return [$stream, basename($file), true];
        }
        if (is_resource($file)) {
            $uri = stream_get_meta_data($file)['uri'] ?? '';
            return [$file, '' !== $uri && !str_starts_with($uri, 'php://') ? basename($uri) : 'datei', false];
        }
        if ($file instanceof StreamInterface) {
            $stream = fopen('php://temp', 'w+b');
            if (false === $stream) {
                throw new InvalidArgumentException('No temporary stream for the upload.');
            }
            if ($file->isSeekable()) {
                $file->rewind();
            }
            while (!$file->eof()) {
                fwrite($stream, $file->read(1 << 20));
            }
            rewind($stream);
            $uri = $file->getMetadata('uri');
            return [$stream, is_string($uri) && !str_starts_with($uri, 'php://') ? basename($uri) : 'datei', true];
        }
        throw new InvalidArgumentException(sprintf('A file must be a path, a stream resource or a PSR-7 stream, not %s.', get_debug_type($file)));
    }

    private function url(MediaFile|string $file): string
    {
        if ($file instanceof MediaFile) {
            return $file->url;
        }
        return 1 === preg_match('#^https?://#i', $file) ? $file : $this->get($file)->url;
    }

    private static function id(string $id): string
    {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('The file id is empty.');
        }
        return $id;
    }
}
