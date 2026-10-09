<?php
/** @var ExcellentCms\Sdk\Block $block */
/** @var callable(mixed): string $e */
/** @var callable(?string): string $markdown */
/** @var callable(?ExcellentCms\Sdk\MediaFile, int, ?string=): string $img */
?>
<figure class="block image image--<?= $e($block['width'] ?: 'content') ?>" id="block-<?= $e($block->key()) ?>">
  <?= $img($block->media('image'), 1600, $block['ratio']) ?>
  <?php if ($block['caption']): ?><figcaption><?= $e($block['caption']) ?></figcaption><?php endif ?>
</figure>
