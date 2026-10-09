<?php
/** @var ExcellentCms\Sdk\Block $block */
/** @var callable(mixed): string $e */
?>
<section class="block features" id="block-<?= $e($block->key()) ?>">
  <?php if ($block['title']): ?><h2><?= $e($block['title']) ?></h2><?php endif ?>
  <ul>
    <?php foreach ((array)$block['items'] as $item): ?>
      <li><span class="check">✓</span><?= $e($item) ?></li>
    <?php endforeach ?>
  </ul>
</section>
