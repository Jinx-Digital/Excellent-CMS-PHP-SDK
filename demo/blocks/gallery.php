<?php
/** @var ExcellentCms\Sdk\Block $block */
/** @var callable(mixed): string $e */
/** @var callable(?string): string $markdown */
/** @var callable(?ExcellentCms\Sdk\MediaFile, int, ?string=): string $img */
// "ratio": all images cropped alike (empty: square, "original": as they are); "click": nothing,
// "lightbox" (enlarged on the page - see the dialog in page-builder.php) or "file" (the image itself)
$click = in_array($block['click'], ['lightbox', 'file'], true) ? $block['click'] : null;
?>
<section class="block gallery" style="--per-row: <?= (int)($block['per_row'] ?: 3) ?>" id="block-<?= $e($block->key()) ?>" data-type="<?= $e($block->type()) ?> · <?= $e($block->key()) ?>">
  <?php if ($block['title']): ?><h2><?= $e($block['title']) ?></h2><?php endif ?>
  <div class="gallery__grid">
    <?php foreach ($block->files('images') as $file): ?>
      <?php $image = $img($file, 600, $block['ratio'] ?: '1:1') ?>
      <?php if (null !== $click): ?>
        <a href="<?= $e($file->url) ?>"<?= 'lightbox' === $click ? ' data-lightbox="'.$e($block->key()).'"' : ' target="_blank" rel="noopener"' ?>><?= $image ?></a>
      <?php else: ?><?= $image ?><?php endif ?>
    <?php endforeach ?>
  </div>
</section>
