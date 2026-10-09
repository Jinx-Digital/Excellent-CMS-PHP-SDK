<?php
/** @var ExcellentCms\Sdk\Block $block */
/** @var callable(mixed): string $e */
?>
<section class="block hero" id="block-<?= $e($block->key()) ?>">
  <h1><?= $e($block['title']) ?></h1>
  <?php if ($block['text']): ?><p class="lead"><?= $e($block['text']) ?></p><?php endif ?>
  <?php if ($image = $block->media('image')): ?><img src="<?= $e($image->url) ?>" alt=""><?php endif ?>
  <?php if ($block['button_label'] && $block['button_url']): ?>
    <a class="button" href="<?= $e($block['button_url']) ?>"><?= $e($block['button_label']) ?> →</a>
  <?php endif ?>
</section>
