<?php
/** @var ExcellentCms\Sdk\Block $block */
/** @var callable(mixed): string $e */
/** @var callable(?string): string $markdown */
/** @var callable(?ExcellentCms\Sdk\MediaFile, int): string $img */
?>
<figure class="block quote" id="block-<?= $e($block->key()) ?>">
  <blockquote><?= $e($block['text']) ?></blockquote>
  <?php if ($block['author']): ?><figcaption><strong><?= $e($block['author']) ?></strong><?php if ($block['role']): ?> · <?= $e($block['role']) ?><?php endif ?></figcaption><?php endif ?>
</figure>
