<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk;

use ExcellentCms\Sdk\Exception\NotFoundException;

/**
 * Helpers for the plugin "seo" of Excellent CMS: the meta tags of a page from its field group SEO, and
 * redirects of old addresses.
 *
 *     $seo = new Seo($cms);
 *     if ($redirect = $seo->redirect($_SERVER['REQUEST_URI'])) {
 *         http_response_code($redirect['status']);
 *         if ($redirect['target']) header('Location: '.$redirect['target']);
 *         exit;
 *     }
 *     echo Seo::meta($page['seo'], ['title' => $page['title'], 'url' => $url, 'site' => 'Example']);
 */
final class Seo
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Where an old address goes: {path, target, status} (target null for 410) - null without a redirect.
     *
     * @return array{path: string, target: ?string, status: int}|null
     */
    public function redirect(string $path): ?array
    {
        try {
            $result = $this->client->plugin('seo')->get('redirect', ['path' => $path]);
            return is_array($result) ? $result : null;
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * All redirects - for websites that keep them (e.g. in a cache).
     *
     * @return list<array{path: string, target: ?string, status: int}>
     */
    public function redirects(): array
    {
        $result = $this->client->plugin('seo')->get('redirects');
        return is_array($result) ? array_values($result) : [];
    }

    /**
     * <title>, description, canonical, robots and Open Graph tags. The field group SEO wins; $defaults fill
     * the gaps: title, description, image (address), url (canonical), site (appended to the title, og:site_name).
     *
     * @param array<string, mixed>|null $seo the value of the field group SEO
     * @param array{title?: ?string, description?: ?string, image?: ?string, url?: ?string, site?: ?string} $defaults
     */
    public static function meta(?array $seo, array $defaults = []): string
    {
        $seo ??= [];
        $text = static fn(mixed $value): ?string => is_scalar($value) && '' !== trim((string)$value) ? trim((string)$value) : null;
        $title = $text($seo['title'] ?? null) ?? $text($defaults['title'] ?? null);
        $site = $text($defaults['site'] ?? null);
        $description = $text($seo['description'] ?? null) ?? $text($defaults['description'] ?? null);
        $image = is_array($seo['image'] ?? null) ? $text($seo['image']['url'] ?? null) : $text($seo['image'] ?? null);
        $image ??= $text($defaults['image'] ?? null);
        $url = $text($seo['canonical'] ?? null) ?? $text($defaults['url'] ?? null);
        $noindex = in_array($seo['noindex'] ?? false, [true, 1, '1', 'true'], true);

        $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tags = [];
        if (null !== $title) {
            $tags[] = '<title>'.$e(null !== $site && $site !== $title ? $title.' | '.$site : $title).'</title>';
            $tags[] = '<meta property="og:title" content="'.$e($title).'">';
        }
        if (null !== $description) {
            $tags[] = '<meta name="description" content="'.$e($description).'">';
            $tags[] = '<meta property="og:description" content="'.$e($description).'">';
        }
        if (null !== $url) {
            $tags[] = '<link rel="canonical" href="'.$e($url).'">';
            $tags[] = '<meta property="og:url" content="'.$e($url).'">';
        }
        if (null !== $image) {
            $tags[] = '<meta property="og:image" content="'.$e($image).'">';
            $tags[] = '<meta name="twitter:card" content="summary_large_image">';
        }
        if (null !== $site) {
            $tags[] = '<meta property="og:site_name" content="'.$e($site).'">';
        }
        if ($noindex) {
            $tags[] = '<meta name="robots" content="noindex">';
        }
        $tags[] = '<meta property="og:type" content="website">';
        return implode("\n", $tags);
    }
}
