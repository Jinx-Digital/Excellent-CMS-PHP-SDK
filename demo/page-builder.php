<?php

declare(strict_types=1);

/**
 * Page builder demo: a landing page of a local Excellent CMS (`make serve` - http://localhost:8090,
 * project "docs", entity "landing_pages" of the demo data), built from blocks - every block type has a template in demo/blocks/<type>.php:
 *
 *     composer install
 *     open demo/page-builder.php in the browser (e.g. MAMP)    or    php demo/page-builder.php
 *
 *     ?slug=home                  another landing page
 *
 * It is also a preview page: set the preview address of the entity in the CMS (Schema › Settings) to
 *
 *     http://localhost/excellent-cms-sdk/demo/page-builder.php?id={{id}}&token={{token}}
 *
 * and "Preview" in the record form shows drafts and working copies here. Live editing (LiveEdit):
 * click a block in the preview to edit it in the CMS - the page renders the unsaved blocks as you type.
 * admin_origin (demo/config.php): address of the admin app (default: the Nuxt dev server http://localhost:3090).
 *
 * Another CMS: demo/config.php - the live demo demo.excellent.jinx-digital.com has a demo/config.local.php
 * with url and admin_origin https://admin.demo.excellent.jinx-digital.com
 */

use ExcellentCms\Sdk\Block;
use ExcellentCms\Sdk\BlockRenderer;
use ExcellentCms\Sdk\Client;
use ExcellentCms\Sdk\Exception\ExcellentException;
use ExcellentCms\Sdk\Exception\NotFoundException;
use ExcellentCms\Sdk\LiveEdit;
use ExcellentCms\Sdk\MediaFile;
use ExcellentCms\Sdk\Record;

require dirname(__DIR__).'/vendor/autoload.php';

// Local CMS by default; the live demo or another CMS: demo/config.php (config.local.php on the server)
$config = require __DIR__.'/config.php';
$url = $config['url'];
$project = $config['pages_project'];
$entity = $config['pages_entity'];
// The admin app that may send unsaved changes (live preview)
$adminOrigin = $config['admin_origin'];

$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES);

/**
 * A tiny Markdown subset for the demo (headings, paragraphs, **bold**, *italic*, `code`, [links](…), lists) -
 * a real site uses league/commonmark or similar.
 */
$markdown = static function (?string $text) use ($e): string {
    $inline = static fn(string $line): string => (string)preg_replace(
        ['/`([^`]+)`/', '/\*\*(.+?)\*\*/', '/\*(.+?)\*/', '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/'],
        ['<code>$1</code>', '<strong>$1</strong>', '<em>$1</em>', '<a href="$2">$1</a>'],
        $e($line),
    );
    $html = '';
    foreach (preg_split('/\R{2,}/', trim((string)$text)) ?: [] as $paragraph) {
        $lines = preg_split('/\R/', $paragraph) ?: [];
        if (1 === count($lines) && 1 === preg_match('/^(#{1,4})\s+(.+)$/', $lines[0], $heading)) {
            $level = min(4, strlen($heading[1]) + 1);
            $html .= "<h{$level}>".$inline($heading[2])."</h{$level}>";
        } elseif ([] !== $lines && count(array_filter($lines, static fn(string $l): bool => 1 === preg_match('/^\s*[-*] /', $l))) === count($lines)) {
            $html .= '<ul>'.implode('', array_map(static fn(string $l): string => '<li>'.$inline((string)preg_replace('/^\s*[-*] /', '', $l)).'</li>', $lines)).'</ul>';
        } elseif ('' !== trim($paragraph)) {
            $html .= '<p>'.implode('<br>', array_map($inline, $lines)).'</p>';
        }
    }
    return $html;
};

// One template per block type: demo/blocks/<type>.php - unknown types show a note
$renderer = new BlockRenderer(
    directory: __DIR__.'/blocks',
    fallback: static fn(Block $block): string => '<section class="block missing">No template for the block type <code>'.$e($block->type()).'</code> – add demo/blocks/'.$e($block->type()).'.php</section>',
);
// Images in the size the template needs - transformed by the CMS (WebP); with a ratio ("16:9")
// cropped around the focal point of the image
$img = static function (?MediaFile $file, int $width, ?string $ratio = null) use ($e): string {
    if (null === $file) {
        return '';
    }
    [$w, $h] = 1 === preg_match('/^(\d+):(\d+)$/', (string)$ratio, $m) ? [(int)$m[1], (int)$m[2]] : [$file->width, $file->height];
    $cropped = null !== $ratio && 'original' !== $ratio && $w && $h;
    $src = match (true) {
        !$file->canTransform() => $file->url,
        $cropped => $file->transform(width: $width, height: (int)round($width * $h / $w), fit: 'cover', format: 'webp'),
        default => $file->transform(width: $width, format: 'webp'),
    };
    return '<img src="'.$e($src).'" alt="" loading="lazy"'.($w && $h ? ' style="aspect-ratio: '.$w.' / '.$h.'"' : '').'>';
};
$cms = (new Client($url, $project))->preview($_GET['token'] ?? null);
$context = ['e' => $e, 'markdown' => $markdown, 'img' => $img];
$renderer = $renderer->editable($cms->isPreview());
// Live editing: renders the unsaved blocks the page posts back (only with a valid preview token)
if ('cli' !== PHP_SAPI) {
    LiveEdit::handle($cms, $renderer, $entity, $context, $cms->isPreview() ? $cms->variables() : []);
}
$page = null;
$pages = [];
$error = null;
try {
    $query = $cms->entity($entity);
    $pages = $query->select('title', 'slug')->orderBy('title')->limit(20)->get()->records;
    // html: blocks without a template here (e.g. the forms of the plugin "Forms") come with the
    // HTML of their template in the CMS
    $page = match (true) {
        isset($_GET['id']) => $query->get((string)$_GET['id'], html: true),
        default => $query->where('slug', (string)($_GET['slug'] ?? 'home'))->html()->first() ?? $query->html()->first(),
    };
} catch (NotFoundException $exception) {
    $error = sprintf('“%s” was not found in the project “%s” of %s – load the demo data of the CMS (php yii fixtures:load dev). %s', $entity, $project, $url, $exception->getMessage());
} catch (ExcellentException $exception) {
    $error = sprintf('%s: %s', (new ReflectionClass($exception))->getShortName(), $exception->getMessage());
}
$blocks = $page?->blocks('content') ?? [];

if ('cli' === PHP_SAPI) {
    echo PHP_EOL, "Page builder – {$url} · {$project}/{$entity}", PHP_EOL;
    if (null !== $error) {
        fwrite(STDERR, $error.PHP_EOL);
        exit(1);
    }
    echo PHP_EOL, sprintf('“%s” has %d blocks:', $page?->get('title'), count($blocks)), PHP_EOL;
    foreach ($blocks as $index => $block) {
        $fields = array_map(static fn($value): string => is_array($value) ? '['.count($value).']' : mb_strimwidth(str_replace("\n", ' ', (string)$value), 0, 40, '…'), $block->fields());
        printf("  %d. %-15s %s  %s\n", $index + 1, $block->type(), $block->key(), implode(' · ', array_filter($fields)));
    }
    echo PHP_EOL, sprintf('Rendered with demo/blocks/*.php: %d characters of HTML – open the file in a browser to see it.', strlen($renderer->render($blocks, $context))), PHP_EOL;
    exit(0);
}

$link = static fn(array $params): string => '?'.http_build_query(array_filter($params + ['token' => $_GET['token'] ?? null], static fn($v): bool => null !== $v));
header('Content-Type: text/html; charset=utf-8');
if ($cms->isPreview()) {
    header('Cache-Control: no-store');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($page?->get('title') ?? 'Page builder') ?> – Excellent CMS SDK demo</title>
  <link rel="stylesheet" href="assets/page-builder.css">
</head>
<body>
  <div class="bar">
    <svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="7" fill="#059669"/><path d="M5.5 26V17l4-4.4v8.9H14V7.7L16 5.5l2 2.2v13.8h4.5v-8.9l4 4.4v9z" fill="#fff"/></svg>
    <strong>Page builder demo</strong>
    <?php if ($cms->isPreview()): ?><span class="badge preview">Preview · drafts &amp; working copies</span><?php else: ?><span class="badge live">Live</span><?php endif ?>
    <nav>
      <?php foreach ($pages as $item): ?>
        <a class="pill <?= $item->id() === $page?->id() ? 'active' : '' ?>" href="<?= $e($link(['slug' => $item['slug']])) ?>"><?= $e($item['title']) ?></a>
      <?php endforeach ?>
    </nav>
  </div>
  <main>
    <?php if (null !== $error): ?>
      <p class="error"><?= $e($error) ?></p>
    <?php else: ?>
      <div id="blocks"><?= $renderer->render($blocks, $context) ?></div>
      <?php if ([] === $blocks): ?><p>This page has no blocks yet.</p><?php endif ?>
    <?php endif ?>

    <details>
      <summary>How this page is made</summary>
      <pre><code><?= $e(<<<'PHP'
$cms = (new Client('http://localhost:8090', 'docs'))
    ->preview($_GET['token'] ?? null);              // drafts in the CMS preview

$page = $cms->entity('landing_pages')->where('slug', 'home')->first();

$renderer = new BlockRenderer(directory: __DIR__.'/blocks');   // blocks/hero.php, blocks/features.php …
echo $renderer->render($page->blocks('content'), ['e' => $e, 'markdown' => $markdown]);
PHP) ?></code></pre>
      <p>Blocks of this page (<code>$page->blocks('content')</code>):</p>
      <pre><code><?= $e(json_encode(array_map(static fn(Block $b): array => $b->toArray(), $blocks), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></code></pre>
    </details>
  </main>
  <!-- Galleries with "click: lightbox": the image enlarged in a dialog, arrows (and ← →) to the others of the gallery -->
  <dialog class="lightbox" aria-label="Image"><img alt=""><button type="button" data-prev aria-label="Previous">‹</button><button type="button" data-next aria-label="Next">›</button><button type="button" data-close aria-label="Close">×</button></dialog>
  <script src="assets/lightbox.js" defer></script>
  <?php if ($cms->isPreview()): ?><?= LiveEdit::script($adminOrigin) ?><?php endif ?>
</body>
</html>
