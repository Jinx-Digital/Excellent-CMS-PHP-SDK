<?php

declare(strict_types=1);

/**
 * Reads a few things from the live demo of Excellent CMS (public entities of the project
 * "bibliothek") and shows them as tables - in the browser as a page, on the command line as text:
 *
 *     composer install
 *     php demo/demo.php                      or open demo/demo.php in the browser (e.g. MAMP)
 *
 * Another CMS:      EXCELLENT_URL=http://localhost:8090 EXCELLENT_PROJECT=main php demo/demo.php
 * With an API client (protected entities such as books):
 *                   EXCELLENT_CLIENT_ID=… EXCELLENT_CLIENT_SECRET=… php demo/demo.php
 */

use ExcellentCms\Sdk\Auth\ClientCredentials;
use ExcellentCms\Sdk\Client;
use ExcellentCms\Sdk\Exception\ExcellentException;
use ExcellentCms\Sdk\Exception\ValidationException;
use ExcellentCms\Sdk\Record;

require dirname(__DIR__).'/vendor/autoload.php';

$url = getenv('EXCELLENT_URL') ?: 'https://demo.excellent.jinx-digital.com';
$project = getenv('EXCELLENT_PROJECT') ?: 'bibliothek';
$clientId = getenv('EXCELLENT_CLIENT_ID');
$secret = getenv('EXCELLENT_CLIENT_SECRET');

$cms = new Client($url, $project, $clientId && $secret ? new ClientCredentials($clientId, $secret) : null);

/**
 * One block of the output: what was asked (the SDK code) and the answer as a table.
 *
 * @param list<string> $columns
 * @param list<list<mixed>> $rows
 */
function section(string $title, string $code, array $columns, array $rows, ?string $note = null): array
{
    return ['title' => $title, 'code' => $code, 'columns' => $columns, 'rows' => $rows, 'note' => $note];
}

$names = static fn(array $records): string => implode(', ', array_map(static fn(Record $record): string => (string)$record['name'], $records));
$sections = [];
$error = null;

try {
    $schemas = [];
    $rows = [];
    foreach ($cms->entities() as $entity) {
        $schemas[$entity->slug] = $entity;
        $rows[] = [
            $entity->slug,
            $entity->name,
            'public' === $entity->access ? 'public' : 'token',
            implode(', ', array_map(static fn($field): string => $field->name.($field->reference ? ' → '.$field->reference : ''), $entity->fields)),
            implode(', ', array_filter([$entity->treeField ? 'tree' : null, $entity->languages ? 'languages: '.implode('/', $entity->languages) : null])),
        ];
    }
    $sections[] = section('Entities of the project', '$cms->entities()', ['Slug', 'Name', 'Access', 'Fields', 'Notes'], $rows);

    if (isset($schemas['countries'])) {
        $countries = $cms->entity('countries');

        $page = $countries->select('name', 'name_de', 'alpha2code')->orderBy('name')->limit(5)->get();
        $sections[] = section(
            'Countries: the first 5 by name',
            "\$cms->entity('countries')->select('name', 'name_de', 'alpha2code')->orderBy('name')->limit(5)->get()",
            ['Code', 'Name', 'German'],
            array_map(static fn(Record $c): array => [$c['alpha2code'], $c['name'], $c['name_de']], $page->records),
            sprintf('%d of %d countries · page %d of %d', count($page), $page->totalItems, $page->currentPage, $page->totalPages),
        );

        $sections[] = section(
            'Search for “land”, with the region',
            "\$countries->search('land')->with('region')->orderBy('name')->limit(8)->get()",
            ['Country', 'Region'],
            array_map(static fn(Record $c): array => [$c['name'], $c->get('region.name') ?? '–'], $countries->search('land')->with('region')->orderBy('name')->limit(8)->get()->records),
        );

        $eu = $countries->where('eu_member', true)->where('name', '>', 'N')->orderBy('name');
        $sections[] = section(
            'EU members from “N”',
            "\$countries->where('eu_member', true)->where('name', '>', 'N')->orderBy('name')->all()",
            ['Count', 'Countries'],
            [[$eu->count(), $names($eu->all())]],
        );

        try {
            $western = $countries->where('region.name', 'Western Europe')->orderBy('name')->select('name')->all();
            $sections[] = section(
                'Filter on a reference: countries in “Western Europe”',
                "\$countries->where('region.name', 'Western Europe')->orderBy('name')->all()",
                ['Count', 'Countries'],
                [[count($western), $names($western)]],
            );
        } catch (ValidationException $e) {
            // Older CMS versions only filter references by id
            $sections[] = section('Filter on a reference', "\$countries->where('region.name', …)", [], [], 'This CMS version cannot filter on fields of references yet: '.$e->getMessage());
        }
    }

    if (isset($schemas['regions'])) {
        $rows = [];
        foreach ($cms->entity('regions')->orderBy('name')->tree() as $root) {
            foreach ($root->children() as $continent) {
                $rows[] = [$root['name'], $continent['name'], $names($continent->children())];
            }
        }
        $sections[] = section('Regions as a tree', "\$cms->entity('regions')->orderBy('name')->tree()", ['World', 'Continent', 'Subregions'], $rows);
    }

    if (isset($schemas['authors'])) {
        $authors = $cms->entity('authors')->whereBetween('born', '1800-01-01', '1899-12-31')->with('country')->orderBy('born');
        $sections[] = section(
            'Authors born in the 19th century',
            "\$cms->entity('authors')->whereBetween('born', '1800-01-01', '1899-12-31')->with('country')->orderBy('born')->cursor()",
            ['Name', 'Born', 'Died', 'Country'],
            array_map(static fn(Record $a): array => [$a['name'], $a['born'], $a['died'] ?? '–', $a->get('country.name') ?? '–'], iterator_to_array($authors->cursor(), false)),
        );
    }

    if (isset($schemas['books'])) {
        $sections[] = section(
            'The newest books (protected)',
            "\$cms->entity('books')->with('author')->orderByDesc('created_at')->limit(5)->get()",
            ['Title', 'Author'],
            array_map(static fn(Record $b): array => [$b['title'] ?? $b->id(), $b->get('author.name') ?? '–'], $cms->entity('books')->with('author')->orderByDesc('created_at')->limit(5)->get()->records),
        );
    }
} catch (ExcellentException $e) {
    $error = sprintf('%s: %s', (new ReflectionClass($e))->getShortName(), $e->getMessage());
}

$footer = array_filter([
    !isset($schemas['books']) && !$clientId ? 'Protected entities such as “books” need EXCELLENT_CLIENT_ID and EXCELLENT_CLIENT_SECRET.' : null,
    null !== ($limit = $cms->rateLimit()) ? sprintf('Rate limit: %d of %d requests left', $limit->remaining, $limit->limit) : null,
]);

if ('cli' === PHP_SAPI) {
    renderText($url, $project, $sections, $footer, $error);
    exit(null === $error ? 0 : 1);
}
renderHtml($url, $project, $sections, $footer, $error);

/**
 * Command line: a framed table per section.
 *
 * @param list<array> $sections
 * @param list<string> $footer
 */
function renderText(string $url, string $project, array $sections, array $footer, ?string $error): void
{
    $bold = static fn(string $text): string => stream_isatty(STDOUT) ? "\033[1m{$text}\033[0m" : $text;
    $dim = static fn(string $text): string => stream_isatty(STDOUT) ? "\033[2m{$text}\033[0m" : $text;
    echo PHP_EOL, $bold("Excellent CMS – project “{$project}”"), '  ', $dim($url), PHP_EOL;

    foreach ($sections as $section) {
        echo PHP_EOL, $bold($section['title']), PHP_EOL, $dim($section['code']), PHP_EOL;
        if ([] !== $section['columns']) {
            echo table($section['columns'], $section['rows']);
        }
        if (null !== $section['note']) {
            echo $dim($section['note']), PHP_EOL;
        }
    }
    foreach ($footer as $line) {
        echo PHP_EOL, $dim($line);
    }
    echo PHP_EOL;
    if (null !== $error) {
        fwrite(STDERR, PHP_EOL.'Error – '.$error.PHP_EOL);
    }
}

/**
 * ┌──────┬──────┐ table; long cells are cut so a row fits into the terminal.
 *
 * @param list<string> $columns
 * @param list<list<mixed>> $rows
 */
function table(array $columns, array $rows, int $maxCell = 60): string
{
    $cell = static fn(mixed $value): string => mb_strimwidth(str_replace("\n", ' ', (string)$value), 0, $maxCell, '…');
    $rows = array_map(static fn(array $row): array => array_map($cell, $row), $rows);
    $widths = array_map(static fn(string $column): int => mb_strlen($column), $columns);
    foreach ($rows as $row) {
        foreach ($row as $i => $value) {
            $widths[$i] = max($widths[$i] ?? 0, mb_strlen($value));
        }
    }
    $line = static fn(string $left, string $middle, string $right): string => $left.implode($middle, array_map(static fn(int $w): string => str_repeat('─', $w + 2), $widths)).$right.PHP_EOL;
    $row = static fn(array $values): string => '│'.implode('│', array_map(static fn(string $v, int $w): string => ' '.$v.str_repeat(' ', $w - mb_strlen($v)).' ', $values, $widths)).'│'.PHP_EOL;

    $out = $line('┌', '┬', '┐').$row($columns).$line('├', '┼', '┤');
    foreach ($rows as $values) {
        $out .= $row(array_pad($values, count($widths), ''));
    }
    if ([] === $rows) {
        $out .= $row(array_pad(['(no records)'], count($widths), ''));
    }
    return $out.$line('└', '┴', '┘');
}

/**
 * Browser: one page with a card per section.
 *
 * @param list<array> $sections
 * @param list<string> $footer
 */
function renderHtml(string $url, string $project, array $sections, array $footer, ?string $error): void
{
    $e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Excellent CMS SDK – Demo</title>
  <style>
    :root { --bg: #f6f7f8; --card: #fff; --text: #1f2328; --muted: #656d76; --line: #e3e6e8; --primary: #059669; --code: #f0f3f4; }
    @media (prefers-color-scheme: dark) { :root { --bg: #111315; --card: #1a1d20; --text: #e6e8ea; --muted: #9aa3ab; --line: #2b3035; --primary: #34d399; --code: #22262a; } }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
    main { max-width: 1100px; margin: 0 auto; padding: 32px 16px 48px; }
    header { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
    header svg { width: 40px; height: 40px; flex: none; }
    h1 { font-size: 22px; margin: 0; }
    header p { margin: 0; color: var(--muted); font-size: 14px; }
    section { background: var(--card); border: 1px solid var(--line); border-radius: 12px; margin-bottom: 20px; overflow: hidden; }
    section h2 { font-size: 16px; margin: 0; padding: 14px 18px 4px; }
    pre { margin: 0 18px 12px; padding: 8px 12px; background: var(--code); border-radius: 8px; overflow-x: auto; font-size: 12.5px; color: var(--muted); }
    .table { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th, td { text-align: left; padding: 8px 18px; border-top: 1px solid var(--line); vertical-align: top; }
    th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 600; }
    tbody tr:hover { background: var(--code); }
    .note { padding: 10px 18px 14px; color: var(--muted); font-size: 13px; border-top: 1px solid var(--line); }
    .error { background: #fde8e8; color: #9b1c1c; border-color: #f8b4b4; padding: 14px 18px; }
    footer { color: var(--muted); font-size: 13px; }
  </style>
</head>
<body>
<main>
  <header>
    <svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="7" fill="#059669"/><path d="M5.5 26V17l4-4.4v8.9H14V7.7L16 5.5l2 2.2v13.8h4.5v-8.9l4 4.4v9z" fill="#fff"/></svg>
    <div>
      <h1>Excellent CMS SDK – Demo</h1>
      <p>Project “<?= $e($project) ?>” · <a href="<?= $e($url) ?>" style="color:inherit"><?= $e($url) ?></a></p>
    </div>
  </header>
  <?php if (null !== $error): ?>
    <section class="error"><strong>Error:</strong> <?= $e($error) ?></section>
  <?php endif ?>
  <?php foreach ($sections as $section): ?>
    <section>
      <h2><?= $e($section['title']) ?></h2>
      <pre><code><?= $e($section['code']) ?></code></pre>
      <?php if ([] !== $section['columns']): ?>
        <div class="table">
          <table>
            <thead><tr><?php foreach ($section['columns'] as $column): ?><th><?= $e($column) ?></th><?php endforeach ?></tr></thead>
            <tbody>
              <?php foreach ($section['rows'] as $row): ?>
                <tr><?php foreach ($row as $value): ?><td><?= $e($value) ?></td><?php endforeach ?></tr>
              <?php endforeach ?>
              <?php if ([] === $section['rows']): ?><tr><td colspan="<?= count($section['columns']) ?>">No records</td></tr><?php endif ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
      <?php if (null !== $section['note']): ?><div class="note"><?= $e($section['note']) ?></div><?php endif ?>
    </section>
  <?php endforeach ?>
  <footer><?php foreach ($footer as $line): ?><p><?= $e($line) ?></p><?php endforeach ?></footer>
</main>
</body>
</html>
<?php
}
