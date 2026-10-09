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
 *     ?outline=1                  shows the blocks with their type and key
 *
 * It is also a preview page: set the preview address of the entity in the CMS (Schema › Settings) to
 *
 *     http://localhost/excellent-cms-sdk/demo/page-builder.php?id={{id}}&token={{token}}
 *
 * and "Preview" in the record form shows drafts and working copies here. Live editing (LiveEdit):
 * click a block in the preview to edit it in the CMS - the page renders the unsaved blocks as you type.
 * EXCELLENT_ADMIN_ORIGIN: address of the admin app (default: the Nuxt dev server http://localhost:3090).
 *
 * Another CMS: EXCELLENT_URL=https://admin.demo.excellent.jinx-digital.com (EXCELLENT_PROJECT, EXCELLENT_ENTITY) - so runs
 * the live demo demo.excellent.jinx-digital.com, with EXCELLENT_ADMIN_ORIGIN=https://admin.demo.excellent.jinx-digital.com
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

$url = getenv('EXCELLENT_URL') ?: 'http://localhost:8090';
$project = getenv('EXCELLENT_PROJECT') ?: 'docs';
$entity = getenv('EXCELLENT_ENTITY') ?: 'landing_pages';
// The admin app that may send unsaved changes (live preview) - by default the CMS itself
$adminOrigin = getenv('EXCELLENT_ADMIN_ORIGIN') ?: 'http://localhost:3090';

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

$outline = isset($_GET['outline']);
$link = static fn(array $params): string => '?'.http_build_query(array_filter($params + ['outline' => $outline ? 1 : null, 'token' => $_GET['token'] ?? null], static fn($v): bool => null !== $v));
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
  <style>
    :root { --bg: #f6f7f8; --card: #fff; --text: #1f2328; --muted: #656d76; --line: #e3e6e8; --primary: #059669; --primary-dark: #047857; --soft: #ecfdf5; --code: #f0f3f4; }
    @media (prefers-color-scheme: dark) { :root { --bg: #0e1012; --card: #171a1d; --text: #e6e8ea; --muted: #9aa3ab; --line: #2b3035; --primary: #34d399; --primary-dark: #10b981; --soft: #0f2a21; --code: #22262a; } }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
    a { color: var(--primary); }
    code { background: var(--code); padding: .1em .35em; border-radius: 5px; font-size: .9em; }
    .bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; padding: 10px 16px; border-bottom: 1px solid var(--line); background: var(--card); font-size: 14px; }
    .bar svg { width: 26px; height: 26px; flex: none; }
    .bar strong { margin-right: auto; }
    .bar nav { display: flex; flex-wrap: wrap; gap: 6px; }
    .pill { padding: 3px 10px; border: 1px solid var(--line); border-radius: 999px; text-decoration: none; color: var(--text); }
    .pill.active { border-color: var(--primary); color: var(--primary); }
    .badge { padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
    .badge.preview { background: #fef3c7; color: #92400e; }
    .badge.live { background: var(--soft); color: var(--primary-dark); }
    main { max-width: 960px; margin: 0 auto; padding: 24px 16px 64px; }
    .block { position: relative; margin: 0 0 24px; border-radius: 16px; }
    .hero { padding: 64px 32px; text-align: center; color: #fff; background: radial-gradient(circle at 20% 0%, #34d399 0, transparent 45%), linear-gradient(135deg, #065f46, #059669 60%, #10b981); }
    .hero h1 { font-size: clamp(32px, 6vw, 52px); line-height: 1.1; margin: 0 0 16px; letter-spacing: -.02em; }
    .hero .lead { font-size: 19px; opacity: .9; max-width: 560px; margin: 0 auto 28px; }
    .hero img { display: block; max-width: 100%; margin: 0 auto 24px; border-radius: 12px; }
    .button { display: inline-block; padding: 12px 22px; border-radius: 10px; background: #fff; color: #065f46; font-weight: 600; text-decoration: none; }
    .button:hover { transform: translateY(-1px); }
    .features { padding: 32px; background: var(--card); border: 1px solid var(--line); }
    .features h2 { margin: 0 0 16px; }
    .features ul { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
    .features li { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; background: var(--soft); border-radius: 10px; }
    .check { color: var(--primary); font-weight: 700; }
    .rich-text { padding: 8px 32px; font-size: 17px; }
    .cta { padding: 40px 32px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; background: #111827; color: #fff; }
    .cta h2 { margin: 0; font-size: 26px; }
    .button--light { background: var(--primary); color: #fff; }
    .missing { padding: 16px; border: 2px dashed #f59e0b; color: var(--muted); }
    .block img { display: block; width: 100%; height: auto; border-radius: 12px; object-fit: cover; }
    .button--primary { background: var(--primary); color: #fff; }
    .link { font-weight: 600; text-decoration: none; }
    .placeholder { aspect-ratio: 3 / 2; border-radius: 12px; background: var(--code); }
    /* --media: width of the image (field "media_width", %) - empty: half */
    .media-text { display: grid; grid-template-columns: minmax(0, var(--media, 1fr)) minmax(0, 1fr); gap: 32px; align-items: center; padding: 24px 0; }
    .media-text--right { grid-template-columns: minmax(0, 1fr) minmax(0, var(--media, 1fr)); }
    .media-text--right .media-text__media { order: 2; }
    .media-text h2 { margin: 0 0 12px; font-size: 28px; }
    .columns h2, .gallery h2, .faq h2 { margin: 0 0 16px; }
    .columns__grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 24px; }
    .column { grid-column: span var(--span); padding: 20px; background: var(--card); border: 1px solid var(--line); border-radius: 12px; }
    .column > .block { margin-bottom: 16px; }
    .column > .block:last-child { margin-bottom: 0; }
    .column h3, .column h4 { margin: 0 0 4px; }
    .column .rich-text { padding: 0; font-size: 16px; }
    .column .image--content, .column .image--wide { max-width: none; margin: 0; }
    .column .quote { padding: 20px 24px; } .column .quote blockquote { font-size: 19px; }
    .column .stats { padding: 16px; } .column .stat strong { font-size: 32px; }
    .image--content { max-width: 680px; margin-inline: auto; }
    .image--full { margin-inline: calc(50% - 50vw); border-radius: 0; }
    .image--full img { border-radius: 0; }
    figcaption { margin-top: 8px; color: var(--muted); font-size: 14px; text-align: center; }
    .gallery__grid { display: grid; grid-template-columns: repeat(var(--per-row), minmax(0, 1fr)); gap: 12px; }
    .gallery__grid a { display: block; cursor: zoom-in; }
    .lightbox { max-width: min(92vw, 1400px); padding: 0; border: 0; border-radius: 12px; background: #111; }
    .lightbox::backdrop { background: rgb(0 0 0 / .8); }
    .lightbox img { display: block; max-width: 100%; max-height: 86vh; margin: 0 auto; }
    .lightbox button { position: absolute; top: 50%; translate: 0 -50%; border: 0; border-radius: 999px; width: 40px; height: 40px; background: rgb(255 255 255 / .85); font-size: 20px; cursor: pointer; }
    .lightbox [data-prev] { left: 8px; } .lightbox [data-next] { right: 8px; }
    .lightbox [data-close] { top: 28px; right: 8px; translate: none; }
    .excellent-form { display: grid; gap: 16px; max-width: 720px; }
    .excellent-form .form-fields { display: flex; flex-wrap: wrap; gap: 16px; }
    .excellent-form .form-fields > *, .form-column > *, .form-fieldset > * { flex: 1 1 100%; }
    .excellent-form .is-half { flex: 1 1 calc(50% - 8px); min-width: 200px; }
    .form-columns { display: grid; grid-template-columns: repeat(var(--columns), minmax(0, 1fr)); gap: 16px; }
    .form-column, .form-fieldset { display: flex; flex-wrap: wrap; gap: 16px; align-content: start; }
    .form-fieldset { margin: 0; padding: 16px; border: 1px solid var(--line, #e5e7eb); border-radius: 12px; }
    .form-fieldset legend { padding: 0 6px; font-weight: 600; }
    .form-field { display: grid; gap: 6px; }
    .form-label { font-weight: 600; font-size: 15px; }
    .form-required, .form-error, .form-errors { color: #dc2626; }
    .form-field input:not([type=checkbox]):not([type=radio]), .form-field select, .form-field textarea { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 10px; font: inherit; background: #fff; }
    .form-field.has-error input, .form-field.has-error select, .form-field.has-error textarea { border-color: #dc2626; }
    .form-choices { display: flex; flex-wrap: wrap; gap: 8px 18px; }
    .form-help { color: var(--muted, #6b7280); }
    .form-actions button { padding: 12px 22px; border: 0; border-radius: 10px; background: var(--primary); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
    .form-success { padding: 16px 20px; border-radius: 12px; background: var(--soft); font-weight: 600; }
    @media (max-width: 640px) { .form-columns { grid-template-columns: minmax(0, 1fr); } }
    .quote { margin-inline: 0; padding: 32px 40px; border-left: 4px solid var(--primary); background: var(--soft); }
    .quote blockquote { margin: 0; font-size: 24px; line-height: 1.4; font-weight: 500; }
    .quote figcaption { text-align: left; margin-top: 16px; }
    .video__frame { position: relative; aspect-ratio: 16 / 9; border-radius: 12px; overflow: hidden; background: #000; }
    .video__frame iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
    .faq details { margin: 0 0 8px; padding: 14px 18px; background: var(--card); border: 1px solid var(--line); border-radius: 10px; }
    .faq details[open] summary { margin-bottom: 8px; }
    .stats { display: flex; flex-wrap: wrap; justify-content: space-around; gap: 24px; padding: 32px; background: var(--card); border: 1px solid var(--line); }
    .stat { text-align: center; }
    .stat strong { display: block; font-size: 44px; line-height: 1.1; color: var(--primary); }
    .stat span { color: var(--muted); }
    .code { margin-inline: 0; border-radius: 12px; overflow: hidden; background: #0d1117; color: #e6edf3; }
    .code figcaption { margin: 0; padding: 8px 16px; text-align: left; color: #8b949e; border-bottom: 1px solid #30363d; }
    .code pre { margin: 0; padding: 16px; background: none; border-radius: 0; }
    .code code { background: none; padding: 0; }
    .spacer--small { height: 8px; } .spacer--medium { height: 32px; } .spacer--large { height: 72px; }
    .spacer hr { border: 0; border-top: 1px solid var(--line); margin: 16px 0; }
    @media (max-width: 720px) {
      .media-text, .media-text--right { grid-template-columns: 1fr; } .media-text--right .media-text__media { order: 0; }
      .column { grid-column: span 12; } .gallery__grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    .outline .block { outline: 2px dashed var(--primary); outline-offset: 4px; }
    .outline .block::before { content: attr(data-type); position: absolute; top: -12px; left: 12px; z-index: 1; padding: 1px 8px; border-radius: 6px; background: var(--primary); color: #fff; font: 600 12px/1.6 ui-monospace, monospace; }
    .error { padding: 16px; border-radius: 12px; background: #fde8e8; color: #9b1c1c; }
    details { margin-top: 40px; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 12px 16px; font-size: 14px; }
    summary { cursor: pointer; font-weight: 600; }
    pre { overflow-x: auto; background: var(--code); padding: 12px; border-radius: 8px; font-size: 12.5px; }
  </style>
</head>
<body class="<?= $outline ? 'outline' : '' ?>">
  <div class="bar">
    <svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="7" fill="#059669"/><path d="M5.5 26V17l4-4.4v8.9H14V7.7L16 5.5l2 2.2v13.8h4.5v-8.9l4 4.4v9z" fill="#fff"/></svg>
    <strong>Page builder demo</strong>
    <?php if ($cms->isPreview()): ?><span class="badge preview">Preview · drafts &amp; working copies</span><?php else: ?><span class="badge live">Live</span><?php endif ?>
    <nav>
      <?php foreach ($pages as $item): ?>
        <a class="pill <?= $item->id() === $page?->id() ? 'active' : '' ?>" href="<?= $e($link(['slug' => $item['slug']])) ?>"><?= $e($item['title']) ?></a>
      <?php endforeach ?>
      <a class="pill" href="<?= $e($link(['slug' => $page?->get('slug'), 'outline' => $outline ? null : 1])) ?>"><?= $outline ? 'Hide blocks' : 'Show blocks' ?></a>
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
  <script>
  (() => {
    const dialog = document.querySelector('.lightbox')
    let links = [], index = 0
    const show = (i) => { index = (i + links.length) % links.length; dialog.querySelector('img').src = links[index].href }
    document.addEventListener('click', (event) => {
      const link = event.target.closest('a[data-lightbox]')
      if (!link || event.defaultPrevented) return
      event.preventDefault()
      links = [...document.querySelectorAll(`a[data-lightbox="${CSS.escape(link.dataset.lightbox)}"]`)]
      show(links.indexOf(link))
      dialog.showModal()
    })
    dialog.addEventListener('click', (event) => {
      if (event.target.closest('[data-prev]')) show(index - 1)
      else if (event.target.closest('[data-next]')) show(index + 1)
      else if (event.target.closest('[data-close]') || event.target === dialog) dialog.close()
    })
    dialog.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowLeft') show(index - 1)
      if (event.key === 'ArrowRight') show(index + 1)
    })
  })()
  </script>
  <?php if ($cms->isPreview()): ?><?= LiveEdit::script($adminOrigin) ?><?php endif ?>
</body>
</html>
