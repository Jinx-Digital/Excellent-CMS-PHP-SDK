<?php
/** @var ExcellentCms\Sdk\Block $block */
/** @var callable(mixed): string $e */
/** @var callable(?string): string $markdown */
/** @var callable(?ExcellentCms\Sdk\MediaFile, int): string $img */
?>
<section class="block faq" id="block-<?= $e($block->key()) ?>">
  <?php if ($block['title']): ?><h2><?= $e($block['title']) ?></h2><?php endif ?>
  <?php foreach (array_filter((array)$block['items'], 'is_array') as $item): ?>
    <details>
      <summary><?= $e($item['question'] ?? '') ?></summary>
      <?= $markdown($item['answer'] ?? null) ?>
    </details>
  <?php endforeach ?>
</section>
