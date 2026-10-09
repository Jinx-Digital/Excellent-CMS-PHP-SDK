<?php
/** @var ExcellentCms\Sdk\Block $block */
/** @var callable(mixed): string $e */
/** @var callable(?string): string $markdown */
/** @var callable(?ExcellentCms\Sdk\MediaFile, int, ?string=): string $img */
?>
<?php $width = is_numeric($block['media_width']) ? max(10, min(90, (int)$block['media_width'])) : null ?>
<section class="block media-text media-text--<?= $block['media_position'] === 'right' ? 'right' : 'left' ?>"<?= null !== $width ? ' style="--media: '.$width.'%"' : '' ?> id="block-<?= $e($block->key()) ?>" data-type="<?= $e($block->type()) ?> · <?= $e($block->key()) ?>">
  <div class="media-text__media"><?= $img($block->media('image'), 900, $block['ratio']) ?: '<div class="placeholder"></div>' ?></div>
  <div class="media-text__copy">
    <?php if ($block['title']): ?><h2><?= $e($block['title']) ?></h2><?php endif ?>
    <?= $markdown($block['body']) ?>
    <?php if ($block['button_label'] && $block['button_url']): ?><a class="button button--primary" href="<?= $e($block['button_url']) ?>"><?= $e($block['button_label']) ?></a><?php endif ?>
  </div>
</section>
