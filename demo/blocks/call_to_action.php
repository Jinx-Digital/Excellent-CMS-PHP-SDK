<?php
/** @var ExcellentCms\Sdk\Block $block */
/** @var callable(mixed): string $e */
?>
<section class="block cta" id="block-<?= $e($block->key()) ?>" data-type="<?= $e($block->type()) ?> · <?= $e($block->key()) ?>">
  <h2><?= $e($block['title']) ?></h2>
  <?php if ($block['button_label'] && $block['button_url']): ?>
    <a class="button button--light" href="<?= $e($block['button_url']) ?>"><?= $e($block['button_label']) ?></a>
  <?php endif ?>
</section>
