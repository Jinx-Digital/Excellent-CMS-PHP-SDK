<?php
/** @var ExcellentCms\Sdk\Block $block */
/** @var callable(mixed): string $e */
/** @var callable(?string): string $markdown */
/** @var callable(?ExcellentCms\Sdk\MediaFile, int): string $img */
?>
<div class="block spacer spacer--<?= $e($block['size'] ?: 'medium') ?>" id="block-<?= $e($block->key()) ?>"><?php if ('line' === $block['size']): ?><hr><?php endif ?></div>
