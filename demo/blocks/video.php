<?php
/** @var ExcellentCms\Sdk\Block $block */
/** @var callable(mixed): string $e */
/** @var callable(?string): string $markdown */
/** @var callable(?ExcellentCms\Sdk\MediaFile, int): string $img */
// YouTube and Vimeo addresses become their embed address
$url = (string)$block['url'];
$embed = match (true) {
    1 === preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/)([\w-]{6,})~', $url, $m) => 'https://www.youtube-nocookie.com/embed/'.$m[1],
    1 === preg_match('~vimeo\.com/(\d+)~', $url, $m) => 'https://player.vimeo.com/video/'.$m[1],
    default => null,
};
?>
<figure class="block video" id="block-<?= $e($block->key()) ?>">
  <?php if ($embed): ?>
    <div class="video__frame"><iframe src="<?= $e($embed) ?>" title="<?= $e($block['caption'] ?: 'Video') ?>" loading="lazy" allowfullscreen></iframe></div>
  <?php else: ?>
    <a href="<?= $e($url) ?>"><?= $e($url) ?></a>
  <?php endif ?>
  <?php if ($block['caption']): ?><figcaption><?= $e($block['caption']) ?></figcaption><?php endif ?>
</figure>
