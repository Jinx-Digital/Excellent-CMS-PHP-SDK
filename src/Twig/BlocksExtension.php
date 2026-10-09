<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Twig;

use ExcellentCms\Sdk\Block;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Blocks in Twig (needs twig/twig): one template per block type, blocks/<type>.twig by default.
 *
 *     $twig->addExtension(new BlocksExtension('blocks'));
 *
 *     {{ excellent_blocks(page.content) }}                  {# or page.blocks('content') #}
 *     {{ excellent_blocks(page.content, { page: page }) }}  {# more variables for the templates #}
 *
 * Every template gets `block` (Block: block.title, block.type, block.key, block.media('image')),
 * `index` and the context. Types without a template are skipped.
 */
final class BlocksExtension extends AbstractExtension
{
    public function __construct(
        private readonly string $directory = 'blocks',
        private readonly string $extension = '.twig',
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('excellent_blocks', $this->render(...), ['needs_environment' => true, 'is_safe' => ['html']]),
        ];
    }

    /**
     * @param iterable<Block>|list<array<string, mixed>>|null $blocks
     * @param array<string, mixed> $context
     */
    public function render(Environment $twig, iterable|null $blocks, array $context = []): string
    {
        $html = '';
        $index = 0;
        $list = is_array($blocks) ? Block::list(array_map(static fn($block) => $block instanceof Block ? $block->toArray() : $block, $blocks)) : ($blocks ?? []);
        foreach ($list as $block) {
            $name = rtrim($this->directory, '/').'/'.$block->type().$this->extension;
            if (1 !== preg_match('/^[a-z0-9_]+$/', $block->type()) || !$twig->getLoader()->exists($name)) {
                $index++;
                continue;
            }
            $html .= $twig->render($name, ['block' => $block, 'index' => $index++] + $context);
        }
        return $html;
    }
}
