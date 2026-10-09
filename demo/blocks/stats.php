<?php
/** @var ExcellentCms\Sdk\Block $block */
/** @var callable(mixed): string $e */
/** @var callable(?string): string $markdown */
/** @var callable(?ExcellentCms\Sdk\MediaFile, int): string $img */
?>
<section class="block stats" id="block-<?= $e($block->key()) ?>">
  <?php foreach (array_filter((array)$block['items'], 'is_array') as $item): ?>
    <div class="stat"><strong><?= $e($item['value'] ?? '') ?></strong><span><?= $e($item['label'] ?? '') ?></span></div>
  <?php endforeach ?>
</section>
