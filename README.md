# Excellent CMS PHP SDK

PHP client for the headless content API of [Excellent CMS](https://github.com/Jinx-Digital/Excellent-CMS)
([website](https://excellent.jinx-digital.com/), [live demo](https://admin.demo.excellent.jinx-digital.com/)). It lets you:

- Read records with a fluent, immutable query builder, including filters and sorting on fields of referenced records.
- Page through lists lazily.
- Load trees and included references.
- Use translatable fields.
- Create, update and delete records.
- Upload, download and delete media files, including protected ones with signed URLs.
- Fetch OAuth 2.0 client credentials tokens automatically, with optional PSR-16 caching.
- Get typed exceptions for every API error.

It works with any PSR-18 HTTP client, such as Guzzle or Symfony HttpClient.

```php
use ExcellentCms\Sdk\Client;
use ExcellentCms\Sdk\Auth\ClientCredentials;

$cms = new Client('https://cms.example.com', 'bibliothek', new ClientCredentials($clientId, $clientSecret));

$books = $cms->entity('books')
    ->where('author.name', 'Jane Austen')    // a field of the referenced author
    ->where('year', '>=', 1800)
    ->orderBy('author')                      // by the author's display field
    ->orderByDesc('year')
    ->with('author')                         // load the author along
    ->get();

foreach ($books as $book) {
    echo $book->title, ' – ', $book->get('author.name'), PHP_EOL;
}
```

Run `composer install`, then `php demo/demo.php`, or open `demo/demo.php` in the browser. The demos read from the local CMS
(`http://localhost:8090`, admin app `http://localhost:3090`) by default; another CMS - e.g. the
[live demo](https://admin.demo.excellent.jinx-digital.com/) - goes into `demo/config.local.php` (an array
with `url` and `admin_origin`, see `demo/config.php`; `config.*.php` are not in git) or the environment variables of
`demo/config.php`.
`demo/page-builder.php` is a website from blocks - live at [demo.excellent.jinx-digital.com](https://demo.excellent.jinx-digital.com/).

`demo/page-builder.php` shows the [page builder](#blocks-page-builder) of the CMS: it renders a landing page from blocks with one
template per block type (`demo/blocks/*.php`). Use it as
the preview address of the entity (`…/demo/page-builder.php?id={{id}}&token={{token}}`) to see drafts and working copies,
and live editing: click a block in the preview to edit it.

## Requirements

- PHP 8.2 or newer.
- A PSR-18 HTTP client and PSR-17 factories, e.g. `guzzlehttp/guzzle`. They are found automatically through
  [php-http/discovery](https://github.com/php-http/discovery).

## Installation

```bash
composer require lugat/excellent-cms-php-sdk guzzlehttp/guzzle
```

## Connecting

```php
use ExcellentCms\Sdk\Auth\BearerToken;
use ExcellentCms\Sdk\Auth\ClientCredentials;
use ExcellentCms\Sdk\Client;

// Public entities only
$cms = new Client('https://cms.example.com', 'bibliothek');

// Protected entities and writing: an API client ("API-Zugänge" in the admin app)
$cms = new Client('https://cms.example.com', 'bibliothek', new ClientCredentials($clientId, $clientSecret));

// A token you already have
$cms = new Client('https://cms.example.com', 'bibliothek', new BearerToken($token));
```

The URL is the address of the CMS. `…/api/v1` is appended unless the URL already ends with it. The second argument is
the slug of the project.

**Tokens** are fetched with the first request. A token is reused until shortly before it expires, and renewed once if
the API refuses it (for example after the client's permissions changed). Without a cache, each PHP process fetches its
own token. To share one token across requests, pass any PSR-16 cache:

```php
new ClientCredentials($clientId, $clientSecret, cache: $psr16Cache);
new ClientCredentials($clientId, $clientSecret, scopes: ['books', 'authors']); // limit the token to some entities
```

**Your own HTTP client:**

```php
$cms = new Client($url, $project, $auth, $psr18Client, $psr17RequestFactory, $psr17StreamFactory);
```

## Reading

```php
$books = $cms->entity('books');

$page = $books->limit(50)->page(2)->get();   // Page: iterable, countable
$page->totalItems;  $page->totalPages;  $page->currentPage;  $page->pageSize;
$page->nextPage();                           // next Page of the same query, or null

$book = $books->find($id);                   // Record or null
$book = $books->get($id);                    // Record, or NotFoundException
$book = $books->get($id, fields: ['title', 'year'], with: ['author'], lang: 'de');

$books->where('year', '>', 2000)->count();   // number of matches
$books->where('year', '>', 2000)->first();   // first match or null

foreach ($books->orderBy('title')->cursor() as $book) {
    // every record, fetched page by page (200 per request) while you iterate
}
$all = $books->all();                        // every record as an array – for small lists
```

`Record` can be read as an array, as an object or by path:

```php
$book['title'];  $book->title;  $book->get('author.name');  $book->get('images.0.url');
$book->id();  $book->createdAt();  $book->updatedAt();          // DateTimeImmutable (UTC)
$book->record('author');                     // included reference as a Record
$book->records('genres');                    // included list of references as Records
$book->toArray();                            // the raw data
```

### Filters

```php
$books->where('title', 'Emma');                         // equals
$books->where('year', '>=', 1800);                      // =, !=, >, >=, <, <=, like, in (or eq, ne, gt, gte, lt, lte)
$books->where('title', 'like', 'pride');                // contains, not case-sensitive
$books->whereIn('language', ['en', 'de']);
$books->whereBetween('year', 1800, 1850);
$books->whereNull('subtitle');  $books->whereNotNull('cover');
$books->where('available', true);
$books->where('published_at', '>', new DateTimeImmutable('-1 year'));
$books->search('pride');                                // full text search in all text fields
```

For repeatable fields, `where()` and `whereIn()` mean "contains".

**Fields of referenced records** use dots or brackets, up to three levels:

```php
$books->where('author', $authorId);                     // the id of the referenced record
$books->where('author.name', 'Jane Austen');            // a field of the referenced record
$books->where('author.country.name', 'like', 'king');   // two references deep
$books->where('genres.slug', 'novel');                  // repeatable reference: one of them matches
```

The query parameters the API gets are available with `toParameters()`. Filters in the API's own format can be merged
with `filter(['price' => ['gte' => 10]])`.

### Sorting, fields, references and languages

```php
$books->orderBy('title');                    // ascending
$books->orderByDesc('year');
$books->orderBy('author');                   // a reference: by the display field of the referenced record
$books->orderBy('author.name', 'desc');      // or by one of its fields
$books->sort('-year,author[name]');          // in the API's own format

$books->select('title', 'year');             // only these fields (id, created_at, updated_at always come along)
$books->with('author', 'genres');            // load referenced records instead of ids
$books->lang('de');                          // translatable fields in German (empty ones fall back to the default language)
$books->lang(Query::ALL_LANGUAGES);          // every language (ExcellentCms\Sdk\Query): {"de": "…", "en": "…"}
```

Queries are immutable: every method returns a new query, so a base query can be reused safely. A new filter or sort
order starts at page 1 again.

### Trees

For entities with a parent field:

```php
foreach ($cms->entity('pages')->orderBy('title')->tree() as $page) {
    echo $page->title;
    foreach ($page->children() as $child) { /* … */ }
}
```

## Blocks (page builder)

A blocks field holds a list of blocks, and each block belongs to one of the field groups the field offers (hero,
text, image …). `Record::blocks()` returns them in their order:

```php
$page = $cms->entity('pages')->where('slug', 'home')->first();

foreach ($page->blocks('content') as $block) {
    $block->type();               // "hero" – the name of the field group
    $block->key();                // stable within the list, e.g. for HTML ids
    $block['title'];              // the fields, like a record
    $block->media('image')?->url; // files (also $block->files('gallery'))
}
```

`BlockRenderer` renders them with one template per type. Templates are PHP files that get `$block`, `$index` and the
context, or callables:

```php
use ExcellentCms\Sdk\Block;
use ExcellentCms\Sdk\BlockRenderer;

$renderer = new BlockRenderer(
    ['text' => fn(Block $block) => '<div class="prose">'.$markdown->convert($block['body']).'</div>'],
    directory: __DIR__.'/templates/blocks',            // every other type: blocks/<type>.php
    fallback: fn(Block $block) => "<!-- no template for {$block->type()} -->",
);
echo $renderer->render($page->blocks('content'), ['page' => $page]);
```

```php
<!-- templates/blocks/hero.php -->
<section id="<?= $block->key() ?>">
  <h1><?= htmlspecialchars($block['title']) ?></h1>
  <?php if ($image = $block->media('image')): ?>
    <img src="<?= $image->transform(width: 1600, format: 'webp') ?>" alt="">
  <?php endif ?>
</section>
```

For **Twig** (`composer require twig/twig`), `BlocksExtension` renders `blocks/<type>.twig` for each block:

```php
$twig->addExtension(new \ExcellentCms\Sdk\Twig\BlocksExtension('blocks'));
```

```twig
{{ excellent_blocks(page.content, { page: page }) }}
{# blocks/hero.twig: <h1>{{ block.title }}</h1> <img src="{{ block.media('image').url }}"> #}
```

Types without a template are skipped - unless the CMS renders them (below). A block type that was removed from the
field no longer comes from the API.

### HTML of the CMS

Blocks can have a template in the CMS (Twig, *Administration › Blocks › Template*; plugins bring theirs, e.g. the
forms). Asked for with `html()`, every block comes with that HTML, and the renderer uses it for the types the website
has no template of - so the SDK renders every block, also the ones it knows nothing about:

```php
$page = $cms->entity('pages')->where('slug', 'contact')->html()->first();   // or ->get($id, html: true)
echo $renderer->render($page->blocks('content'));   // own templates first, then the HTML of the CMS
$block->html();                                      // the CMS's HTML of one block (null: none)
```

In live editing, the CMS renders unsaved blocks of such types too (`$cms->renderBlocks()`, preview token only).

**Nested blocks** (blocks inside a block, e.g. the content of a column) come from `$block->blocks()` with the path of the
field inside the block. In a PHP template, `$renderer` and `$context` are available:

```php
<?php foreach ((array)$block['columns'] as $i => $column): ?>
  <div class="col-<?= (int)$column['span'] ?>">
    <?= $renderer->render($block->blocks("columns.{$i}.content"), $context) ?>
  </div>
<?php endforeach ?>
```

**Rich text** fields return cleaned HTML. Output it as it is (`<?= $page['body'] ?>`). Images in it already carry the
current address of their file.

## Preview

In the CMS, each entity can have a **preview address** (*Schema › Settings*), e.g.
`https://example.com/preview.php?id={{id}}&token={{token}}`. The *Preview* button opens it next to the form or in a
new tab, with a short-lived token (`PREVIEW_TTL`, 1 hour by default). With this token, the content API delivers
drafts and working copies instead of the live state:

```php
// preview.php
$cms = $cms->preview($_GET['token'] ?? null);   // same client, in preview mode
$page = $cms->entity('pages')->get($_GET['id']);
echo $renderer->render($page->blocks('content'));
```

- The token works only for the project of the CMS and expires. An invalid or expired token throws an `AuthenticationException`.
- Preview responses are sent with `Cache-Control: no-store`. Do not cache them on the website either.
- The CMS reloads the preview after each save. JavaScript frontends can also receive unsaved changes while the editor
  is typing: `window.addEventListener('message', e => e.data?.type === 'excellent:preview' && render(e.data.record))`.
  Check `e.origin` against the address of the CMS.

### Live editing

The preview can also be edited: editors click a block in the preview, the CMS opens its fields in a panel, and the page
renders the changed blocks while they type. The page only needs an editable renderer, the handler that renders
posted blocks, and the script:

```php
use ExcellentCms\Sdk\LiveEdit;

$cms = $cms->preview($_GET['token'] ?? null);
$renderer = (new BlockRenderer(directory: __DIR__.'/blocks'))->editable($cms->isPreview());

// Renders the unsaved blocks the script posts back, then ends the request
LiveEdit::handle($cms, $renderer, entity: 'pages', context: $context, variables: $cms->variables());

$page = $cms->entity('pages')->get($_GET['id']);
echo $renderer->render($page->blocks('content'), $context);
if ($cms->isPreview()) {
    echo LiveEdit::script('https://cms.example.com');   // the address of the admin app
}
```

- `editable()` adds `data-excellent-block` (the key), `-type` and `-field` to the first element of every block
  (nested blocks too), and wraps the top-level list in `data-excellent-blocks="<field>"`. Outside the preview nothing
  changes.
- In the preview, a **+** at the top or bottom edge of a block adds a block there. The CMS asks for the type. The
  block under the mouse shows a toolbar: **move** (drag it before or after another block, also into a column, if that
  list allows the type), **duplicate** and **remove**.
- The script runs only inside the preview panel and only accepts messages from the given origin. It outlines blocks on
  hover and sends the click to the CMS. When values change, it posts the blocks back to the page
  (header `X-Excellent-Live`) and swaps in the new HTML.
- `LiveEdit::handle()` answers only with a valid preview token (403 otherwise). It fills in `{{variables}}` like the
  content API does. Media come with their URLs, so templates work as usual.
- After blocks were swapped, `document` gets the event `excellent:rendered`, so the website can start its scripts again
  (animations, sliders …).

## Writing

The API client needs the permission to create, update or delete records of the entity.

```php
$pages = $cms->entity('pages');

$page = $pages->create(['title' => 'About us', 'parent' => $parentId]);   // the record as saved, with id
$page = $pages->update($page->id(), ['title' => 'About Excellent CMS']);   // only the sent fields change
$pages->update($page->id(), ['title' => 'Über uns'], lang: 'de');          // a translation
$pages->delete($page->id());                  // into the trash if the entity has one
```

## Media

The API client needs the media permissions *Upload media* and *Delete media*.

```php
$logo = $cms->media()->upload('/path/to/logo.png');                          // a path, a stream resource or a PSR-7 stream
$logo = $cms->media()->upload($stream, 'logo.png', entity: 'partners', field: 'logo');  // checks the field's allowed types at once
$cms->entity('partners')->create(['name' => 'ACME', 'logo' => $logo->id]);  // use the id in a media field

$logo->url;  $logo->mimeType;  $logo->size;  $logo->width;  $logo->height;  $logo->isImage;

$cms->media()->get($logo->id)->usageCount;    // number of records using it
$cms->media()->delete($logo->id);             // only files no record uses (ApiException 409 otherwise)
```

Images come in any size and format from the CMS, made once and cached there:

```php
$logo = MediaFile::fromArray($partner['logo']);                   // a media field of a record
$logo->transform(800, 450, format: 'webp');                        // 800 × 450, cropped (cover)
$logo->transform(128, 128, fit: 'contain', background: 'fff');     // whitespace instead of cutting
$logo->transform(1024);                                            // 1024 wide, proportions kept
$logo->canTransform();                                             // false for SVG, PDF …
$logo->focalPoint();                                               // ['x' => 0.3, 'y' => 0.6] - cropped variants keep it in view
```

The CMS removes files no record uses after a day. Files uploaded with `keep: true` stay in the media library.

### Protected files

Files of **public** entities have a plain `url` that everyone can open. All other files are protected: their `url`
carries a signature (`?expires=…&signature=…`). Whoever may read the record can use that URL as it is, also in an
`<img>`, but it expires (by default after one to two days). Without a valid signature the CMS answers 403.

```php
$file = MediaFile::fromArray($contract['file']);   // a media field of a protected entity
$file->isSigned();                                 // true
$file->expiresAt();                                // DateTimeImmutable, null for files of public entities

$data = $cms->media()->download($file);            // the content - also by url or id
$cms->media()->downloadTo($file, '/path/vertrag.pdf');
```

Downloads go to the file's URL without the token: the signature is enough, and the token never reaches another host
such as a bucket or a CDN. If you keep URLs longer than they are valid (e.g. in a page cache), download the files or
read the records again. An expired URL throws `AccessDeniedException`. `download()` with an id looks the file up first
and needs the media permission.

## Schema and project variables

```php
foreach ($cms->entities() as $entity) {       // entities this client may read
    echo $entity->slug, ' ', $entity->labelField;
    foreach ($entity->fields as $field) {
        echo $field->name, ' ', $field->type, $field->reference ? ' → '.$field->reference : '';
    }
}
$cms->schema('books');                        // one entity

// Fields of type "enum" store a value; their labels come with the schema
$status = $cms->schema('orders')->field('status');
$status->options;                             // ['open' => 'Offen', 'done' => 'Erledigt']
$status->label($order['status']);             // "Offen"

$cms->variables();                            // ['url' => 'https://…', …]
$cms->variables('de');
```

## Plugins

Plugins of the CMS can offer public routes per project (`/api/v1/<project>/plugins/<plugin>/…`), e.g. to send a
form. The SDK calls them; what they take and answer is up to the plugin:

```php
$cms->plugin('forms')->get('form/contact');
$cms->plugin('forms')->post('submit/contact', ['email' => 'ada@example.com', 'message' => 'Hello']);
```

Blocks of plugins (e.g. the forms of the plugin "Forms") need no helper: their templates in the CMS render them (see
[HTML of the CMS](#html-of-the-cms)).

**SEO** (plugin "seo"): redirects of old addresses and the meta tags of a page from its field group SEO:

```php
use ExcellentCms\Sdk\Seo;

$seo = new Seo($cms);
if ($redirect = $seo->redirect($_SERVER['REQUEST_URI'])) {   // e.g. before answering 404
    http_response_code($redirect['status']);                 // 301, 302 or 410
    if ($redirect['target']) header('Location: '.$redirect['target']);
    exit;
}

// <title>, description, canonical, robots (noindex), Open Graph - the field group wins, the defaults fill the gaps
echo Seo::meta($page['seo'], ['title' => $page['title'], 'description' => $page['summary'], 'url' => $url, 'site' => 'Example']);
```

The sitemap comes from the CMS: `GET /api/v1/<project>/plugins/seo/sitemap.xml`.

## Errors

Every exception implements `ExcellentCms\Sdk\Exception\ExcellentException`.

| Exception | When |
|---|---|
| `ValidationException` (422) | Invalid data, filter or sort. `errors()` lists the messages per field, e.g. `filter.author.nope`. |
| `NotFoundException` (404) | The entity or record does not exist. `find()` returns `null` instead. |
| `AuthenticationException` (401) | The entity is protected and there is no valid token. |
| `AccessDeniedException` (403) | The client may not do this. |
| `RateLimitException` (429) | Too many requests. `retryAfter()` gives the seconds to wait. |
| `ApiException` | Any other API error (base class of the above). Has `status()`, `errorCode()`, `errorData()`. |
| `OAuthException` | The token endpoint refused the credentials. `error()` gives e.g. `invalid_client`. |
| `TransportException` | No answer (network, DNS, TLS), or the answer was not JSON. |
| `InvalidArgumentException` | Wrong use of the SDK, found before any request is sent. |

Messages of the API are in German. The rate limit of the last answer is available with `$cms->rateLimit()`.

## Development

```bash
composer install
composer test        # unit tests
composer analyse     # PHPStan level 8

# Against a running CMS (works with any data, writes only with EXCELLENT_WRITE_ENTITY)
EXCELLENT_URL=http://localhost:8090 EXCELLENT_PROJECT=main \
EXCELLENT_CLIENT_ID=… EXCELLENT_CLIENT_SECRET=… EXCELLENT_WRITE_ENTITY=notes EXCELLENT_MEDIA=1 \
composer test:integration
```

## Links

- Excellent CMS: [github.com/Jinx-Digital/Excellent-CMS](https://github.com/Jinx-Digital/Excellent-CMS). The content API is documented
  in its README and on the page *API-Doku* in the admin app.
- Website: [excellent.jinx-digital.com](https://excellent.jinx-digital.com/)
- Live demo: [admin.demo.excellent.jinx-digital.com](https://admin.demo.excellent.jinx-digital.com/) (admin app and API),
  [demo.excellent.jinx-digital.com](https://demo.excellent.jinx-digital.com/) (website with the page builder)

## License

MIT
