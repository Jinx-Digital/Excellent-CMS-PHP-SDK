<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Tests\Unit;

use ExcellentCms\Sdk\Block;
use ExcellentCms\Sdk\BlockRenderer;
use ExcellentCms\Sdk\LiveEdit;
use ExcellentCms\Sdk\Record;
use ExcellentCms\Sdk\Tests\Support\ClientFactory;
use ExcellentCms\Sdk\Tests\Support\FakeHttpClient;
use ExcellentCms\Sdk\Twig\BlocksExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class BlocksTest extends TestCase
{
    use ClientFactory;

    private const CONTENT = [
        ['_type' => 'hero', '_key' => 'a1', 'title' => 'Hallo <Welt>', 'image' => ['id' => 'm1', 'url' => 'https://cms.example.com/media/x.png', 'name' => 'x.png', 'mime_type' => 'image/png', 'size' => 10]],
        ['_type' => 'text', '_key' => 'b2', 'body' => 'Absatz'],
        ['_type' => 'unknown', '_key' => 'c3'],
        'no block',
    ];

    public function testBlocksOfARecord(): void
    {
        $page = new Record(['id' => 'p1', 'content' => self::CONTENT]);
        $blocks = $page->blocks('content');
        $this->assertCount(3, $blocks);
        $this->assertSame(['hero', 'a1', 'Hallo <Welt>'], [$blocks[0]->type(), $blocks[0]->key(), $blocks[0]->title]);
        $this->assertTrue($blocks[0]->is('hero', 'banner'));
        $this->assertSame('https://cms.example.com/media/x.png', $blocks[0]->media('image')?->url);
        $this->assertSame(['body' => 'Absatz'], $blocks[1]->fields());
        $this->assertSame([], $page->blocks('missing'));
    }

    public function testPhpTemplatesAndCallables(): void
    {
        $page = new Record(['id' => 'p1', 'content' => self::CONTENT]);
        $renderer = new BlockRenderer(
            ['text' => static fn(Block $block, array $context): string => '<p>'.$block['body'].' '.$context['site'].'</p>'],
            directory: __DIR__.'/fixtures/blocks',
            fallback: static fn(Block $block): string => '<!-- '.$block->type().' -->',
        );
        $this->assertSame(
            '<section id="a1"><h1>Hallo &lt;Welt&gt;</h1><img src="https://cms.example.com/media/x.png" alt=""><small>Demo#0</small></section>'."\n"
            .'<p>Absatz Demo</p><!-- unknown -->',
            $renderer->render($page->blocks('content'), ['site' => 'Demo']),
        );
        // The raw value works too; types without a template are skipped
        $this->assertSame('<p>Absatz Demo</p>', (new BlockRenderer())->with('text', static fn(Block $b, array $c): string => '<p>'.$b['body'].' '.$c['site'].'</p>')->render(self::CONTENT, ['site' => 'Demo']));
        $this->assertTrue($renderer->has('hero'));
        $this->assertFalse($renderer->has('../hero'));
    }

    public function testEditableBlocksAreMarkedForLiveEditing(): void
    {
        $page = new Record(['id' => 'p1', 'content' => self::CONTENT]);
        $renderer = (new BlockRenderer())
            ->with('hero', static fn(Block $b): string => "\n<!-- hero -->\n<section class=\"hero\"><h1>".$b['title'].'</h1></section>')
            ->with('text', static fn(Block $b): string => (string)$b['body']);
        $this->assertStringNotContainsString('data-excellent', $renderer->render($page->blocks('content')));

        $html = $renderer->editable()->render($page->blocks('content'));
        $this->assertStringStartsWith('<div data-excellent-blocks="content" style="display:contents">', $html);
        $this->assertStringContainsString('<section data-excellent-block="a1" data-excellent-type="hero" data-excellent-field="content" class="hero">', $html);
        $this->assertStringContainsString('<div style="display:contents" data-excellent-block="b2" data-excellent-type="text" data-excellent-field="content">Absatz</div>', $html, 'text only: wrapped');
        $this->assertTrue($renderer->editable()->isEditable());
        $this->assertFalse($renderer->editable(false)->isEditable());
    }

    public function testNestedBlocks(): void
    {
        $page = new Record(['id' => 'p1', 'content' => [
            ['_type' => 'columns', '_key' => 'c1', 'columns' => [
                ['span' => 8, 'content' => [['_type' => 'text', '_key' => 'n1', 'body' => 'Links']]],
                ['span' => 4, 'content' => [['_type' => 'text', '_key' => 'n2', 'body' => 'Rechts']]],
            ]],
        ]]);
        $columns = $page->blocks('content')[0];
        $inner = $columns->blocks('columns.1.content');
        $this->assertSame(['n2', 'content', 'c1'], [$inner[0]->key(), $inner[0]->field(), $inner[0]->parent()?->key()]);

        $renderer = (new BlockRenderer())
            ->with('text', static fn(Block $b): string => '<p>'.$b['body'].'</p>')
            ->with('columns', static function (Block $block, array $context) use (&$renderer): string {
                $html = '';
                foreach ((array)$block['columns'] as $i => $column) {
                    $html .= '<div>'.$renderer->render($block->blocks("columns.{$i}.content")).'</div>';
                }
                return '<section>'.$html.'</section>';
            })
            ->editable();
        $html = $renderer->render($page->blocks('content'));
        $this->assertSame(1, substr_count($html, 'data-excellent-blocks='), 'only the top level is swapped');
        $this->assertStringContainsString('<p data-excellent-block="n1" data-excellent-type="text" data-excellent-field="content">Links</p>', $html);
        $this->assertStringContainsString('<section data-excellent-block="c1"', $html);
    }

    public function testLiveEditScript(): void
    {
        $script = LiveEdit::script('https://cms.example.com/admin/whatever');
        $this->assertStringContainsString('"origin":"https://cms.example.com"', $script);
        $this->assertStringContainsString("'excellent:select'", $script);
        $this->assertStringContainsString('X-Excellent-Live', $script);
    }

    public function testTwig(): void
    {
        $twig = new Environment(new FilesystemLoader(__DIR__.'/fixtures'));
        $twig->addExtension(new BlocksExtension('blocks'));
        $html = $twig->createTemplate('{{ excellent_blocks(page.content, { site: "Demo" }) }}')->render(['page' => new Record(['content' => self::CONTENT])]);
        $this->assertSame('<p data-key="b2">Absatz – Demo</p>'."\n", $html);
    }

    public function testPreviewSendsTheToken(): void
    {
        $http = (new FakeHttpClient())->success(['id' => 'p1', 'title' => 'Entwurf'])->success(['id' => 'p1', 'title' => 'Live']);
        $cms = $this->client($http);
        $preview = $cms->preview('tok.en');
        $this->assertTrue($preview->isPreview());
        $this->assertFalse($cms->isPreview());
        $this->assertSame('Entwurf', $preview->entity('pages')->get('p1')['title']);
        $this->assertSame('tok.en', $http->last()->getHeaderLine('X-Preview-Token'));
        $cms->entity('pages')->get('p1');
        $this->assertFalse($http->last()->hasHeader('X-Preview-Token'), 'the normal client stays as it is');
        $this->assertFalse($cms->preview('')->isPreview());
    }

    public function testHtmlOfTheCmsWhereTheWebsiteHasNoTemplate(): void
    {
        $renderer = (new BlockRenderer())->with('quote', static fn(Block $block): string => '<blockquote>'.$block['text'].'</blockquote>');
        $blocks = Block::list([
            ['_type' => 'quote', '_key' => 'a', 'text' => 'Own', '_html' => '<p>CMS</p>'],
            ['_type' => 'form', '_key' => 'b', '_html' => '<form>CMS</form>'],
            ['_type' => 'gallery', '_key' => 'c'],
        ], 'content');
        // The website's template wins; the CMS's HTML where it has none; nothing without both
        $this->assertSame('<blockquote>Own</blockquote><form>CMS</form>', $renderer->render($blocks));
        $this->assertSame([true, false], [$renderer->has('quote'), $renderer->has('form')]);
        $this->assertSame(['text' => 'Own'], $blocks[0]->fields(), '_html is no field');
        $this->assertNull($blocks[2]->html());
    }
}
