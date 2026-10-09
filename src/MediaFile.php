<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk;

/**
 * An uploaded file. Its `id` goes into media fields, `url` is its address: open for files of
 * public entities, signed (and expiring) for all others - see isSigned() and Media::download().
 */
final class MediaFile implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $raw everything the API returned
     */
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly string $name,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly bool $isImage,
        /** Kept in the media library even while no record uses it */
        public readonly ?bool $kept,
        /** Number of records using it (only from `get()`) */
        public readonly ?int $usageCount,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $int = static fn(string $key): ?int => is_numeric($data[$key] ?? null) ? (int)$data[$key] : null;
        return new self(
            (string)($data['id'] ?? ''),
            (string)($data['url'] ?? ''),
            (string)($data['name'] ?? ''),
            (string)($data['mime_type'] ?? ''),
            (int)($data['size'] ?? 0),
            $int('width'),
            $int('height'),
            (bool)($data['is_image'] ?? false),
            isset($data['kept']) ? (bool)$data['kept'] : null,
            $int('usage_count'),
            $data,
        );
    }

    /**
     * Protected file: the url carries a signature (?expires=…&signature=…) and works until expiresAt().
     */
    public function isSigned(): bool
    {
        return null !== $this->expiresAt();
    }

    /**
     * When the signed url stops working (null: not signed, it does not expire).
     */
    public function expiresAt(): ?\DateTimeImmutable
    {
        parse_str((string)parse_url($this->url, PHP_URL_QUERY), $query);
        if (!isset($query['signature']) || !is_numeric($query['expires'] ?? null)) {
            return null;
        }
        return (new \DateTimeImmutable('@'.(int)$query['expires']));
    }

    /**
     * The point cropped variants keep in view (0 - 1 from the left and the top), null if none is set.
     * transform() keeps it: the transform_url carries it.
     *
     * @return array{x: float, y: float}|null
     */
    public function focalPoint(): ?array
    {
        $point = $this->raw['focal_point'] ?? null;
        if (!is_array($point) || !is_numeric($point['x'] ?? null) || !is_numeric($point['y'] ?? null)) {
            return null;
        }
        return ['x' => (float)$point['x'], 'y' => (float)$point['y']];
    }

    /**
     * Images the CMS can transform (jpg, png, gif, webp) - see transform().
     */
    public function canTransform(): bool
    {
        return is_string($this->raw['transform_url'] ?? null) && '' !== $this->raw['transform_url'];
    }

    /**
     * Address of the image in another size and format, made and cached by the CMS:
     *
     *     $file->transform(800, 450, format: 'webp')                // 800 × 450, cropped (cover)
     *     $file->transform(128, 128, fit: 'contain', background: 'fff')  // whitespace instead of cutting
     *     $file->transform(1024)                                     // 1024 wide, proportions kept
     *
     * fit: cover (default with width and height), contain, inside (default with one of them);
     * position: what cover keeps (center, top, bottom, left, right); background: fill of contain
     * (hex color or transparent); format: jpg, png, webp, gif, avif; quality: 1 - 100.
     * Images are never enlarged. Signed addresses keep their signature (and expire like url).
     *
     * @throws \LogicException if the file is no image the CMS can transform
     */
    public function transform(
        ?int $width = null,
        ?int $height = null,
        ?string $fit = null,
        ?string $format = null,
        ?int $quality = null,
        ?string $background = null,
        ?string $position = null,
    ): string {
        if (!$this->canTransform()) {
            throw new \LogicException(sprintf('"%s" cannot be transformed (%s).', $this->name, $this->mimeType));
        }
        $query = array_filter([
            'w' => $width,
            'h' => $height,
            'fit' => $fit,
            'pos' => $position,
            'bg' => null !== $background ? ltrim($background, '#') : null,
            'format' => $format,
            'q' => $quality,
        ], static fn(mixed $value): bool => null !== $value);
        $url = (string)$this->raw['transform_url'];
        return [] === $query ? $url : $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
