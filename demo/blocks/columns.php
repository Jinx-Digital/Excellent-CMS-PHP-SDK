<?php
/** @var ExcellentCms\Sdk\Block $block */
/** @var ExcellentCms\Sdk\BlockRenderer $renderer */
/** @var array<string, mixed> $context */
/** @var callable(mixed): string $e */

// Each column: a width on the grid of 12 and blocks of its own (nested blocks)
$columns = array_values(array_filter((array)$block['columns'], 'is_array'));
// Without widths the columns share the row equally (12 / count)
$equal = max(1, intdiv(12, max(1, count($columns))));
?>
<section class="block columns" id="block-<?= $e($block->key()) ?>">
  <div class="columns__grid">
    <?php foreach ($columns as $i => $column): ?>
      <div class="column" style="--span: <?= (int)($column['span'] ?? 0) ?: $equal ?>">
        <?= $renderer->render($block->blocks("columns.{$i}.content"), $context) ?>
      </div>
    <?php endforeach ?>
  </div>
</section>
