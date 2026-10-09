<?php
/** @var ExcellentCms\Sdk\Block $block */
/** @var callable(mixed): string $e */
/** @var callable(?string): string $markdown */
?>
<section class="block rich-text" id="block-<?= $e($block->key()) ?>">
  <?= $markdown($block['body']) ?>
</section>
