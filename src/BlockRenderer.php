<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk;

use ExcellentCms\Sdk\Exception\InvalidArgumentException;

/**
 * Renders blocks with the templates of the website - one per block type:
 *
 *     $renderer = new BlockRenderer([
 *         'hero' => __DIR__.'/blocks/hero.php',              // PHP template: $block, $context …
 *         'text' => fn(Block $block): string => '<div class="prose">'.$markdown->convert($block['body']).'</div>',
 *     ], directory: __DIR__.'/blocks');                    // all others: blocks/<type>.php
 *     echo $renderer->render($page->blocks('content'), ['page' => $page]);
 *
 * PHP templates get `$block`, `$context`, `$index`, `$renderer` and the variables of the context.
 * Types without a template are skipped (or rendered by `fallback`, e.g. an HTML comment while
 * building the site). For Twig see Twig\BlocksExtension.
 *
 * Live editing in the preview of the CMS (see LiveEdit): `editable()` marks the first element of
 * every block with data-excellent-block (its key), -type and -field, and wraps the list in an
 * element with data-excellent-blocks="<field>" that LiveEdit replaces when blocks change.
 */
final class BlockRenderer
{
    private bool $editable = false;

    /**
     * @param array<string, string|callable(Block, array<string, mixed>, int): string> $templates type => template file or callable
     * @param string|null $directory templates of the other types: <directory>/<type>.php
     * @param (\Closure(Block, array<string, mixed>, int): string)|null $fallback types without a template
     */
    public function __construct(
        private array $templates = [],
        private readonly ?string $directory = null,
        private readonly ?\Closure $fallback = null,
    ) {
    }

    /**
     * Marks the blocks for live editing (only in the preview: `$renderer->editable($cms->isPreview())`).
     */
    public function editable(bool $editable = true): self
    {
        $clone = clone $this;
        $clone->editable = $editable;
        return $clone;
    }

    public function isEditable(): bool
    {
        return $this->editable;
    }

    /**
     * A template for one type - a file or a callable.
     *
     * @param string|callable(Block, array<string, mixed>, int): string $template
     */
    public function with(string $type, string|callable $template): self
    {
        $clone = clone $this;
        $clone->templates[$type] = $template;
        return $clone;
    }

    /**
     * All blocks, one after the other.
     *
     * @param iterable<Block>|list<array<string, mixed>>|null $blocks Record::blocks() or the raw field value
     * @param array<string, mixed> $context passed to every template
     */
    public function render(iterable|null $blocks, array $context = []): string
    {
        if (null === $blocks) {
            return '';
        }
        $html = '';
        $index = 0;
        $field = null;
        $nested = false;
        $list = [];
        foreach ($blocks as $block) {
            if ($block instanceof Block) {
                $list[] = $block;
            } elseif (is_array($block)) {
                array_push($list, ...Block::list([$block]));
            }
        }
        foreach ($list as $block) {
            $field ??= $block->field();
            $nested = $nested || null !== $block->parent();
            $html .= $this->renderBlock($block, $context, $index++);
        }
        // The list LiveEdit swaps - only the top level, nested blocks are part of their parent's HTML
        if ($this->editable && !$nested && null !== ($field ??= is_string($context['field'] ?? null) ? $context['field'] : null)) {
            $html = '<div data-excellent-blocks="'.htmlspecialchars($field, ENT_QUOTES).'" style="display:contents">'.$html.'</div>';
        }
        return $html;
    }

    /**
     * One block.
     *
     * @param array<string, mixed> $context
     */
    public function renderBlock(Block $block, array $context = [], int $index = 0): string
    {
        $template = $this->template($block->type());
        if (null === $template && null !== $block->html()) {
            // No template of the website: the one of the CMS (Query::html())
            $html = (string)$block->html();
        } elseif (null === $template) {
            $html = null !== $this->fallback ? (string)($this->fallback)($block, $context, $index) : '';
        } elseif (is_callable($template)) {
            $html = (string)$template($block, $context, $index);
        } else {
            $html = self::include($template, ['block' => $block, 'context' => $context, 'index' => $index, 'renderer' => $this] + $context);
        }
        return $this->editable ? self::mark($html, $block) : $html;
    }

    /**
     * Has the website a template of its own for the type?
     */
    public function has(string $type): bool
    {
        return null !== $this->template($type);
    }

    /**
     * @return string|callable|null
     */
    private function template(string $type): string|callable|null
    {
        $template = $this->templates[$type] ?? null;
        if (null === $template && null !== $this->directory) {
            $file = rtrim($this->directory, '/').'/'.$type.'.php';
            // Types are field group names (a-z, 0-9, _) - nothing else is looked up as file
            $template = 1 === preg_match('/^[a-z0-9_]+$/', $type) && is_file($file) ? $file : null;
        }
        return $template;
    }

    /**
     * Live editing: the attributes of the block on the first element of its HTML.
     */
    private static function mark(string $html, Block $block): string
    {
        $attributes = sprintf(
            ' data-excellent-block="%s" data-excellent-type="%s"%s',
            htmlspecialchars($block->key(), ENT_QUOTES),
            htmlspecialchars($block->type(), ENT_QUOTES),
            null !== $block->field() ? ' data-excellent-field="'.htmlspecialchars($block->field(), ENT_QUOTES).'"' : '',
        );
        $marked = preg_replace('/^(\s*(?:<!--.*?-->\s*)*<[a-zA-Z][\w-]*)/s', '$1'.$attributes, $html, 1, $count);
        // No element (only text): a wrapper that does not change the layout
        return 1 === $count && is_string($marked) ? $marked : '<div style="display:contents"'.$attributes.'>'.$html.'</div>';
    }

    /**
     * @param array<string, mixed> $variables
     */
    private static function include(string $file, array $variables): string
    {
        if (!is_file($file)) {
            throw new InvalidArgumentException(sprintf('The block template "%s" does not exist.', $file));
        }
        $render = static function (string $__file, array $__variables): void {
            extract($__variables, EXTR_SKIP);
            include $__file;
        };
        ob_start();
        try {
            $render($file, $variables);
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
