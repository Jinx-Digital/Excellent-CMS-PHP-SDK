<?php
/** @var ExcellentCms\Sdk\Block $block */
/** @var callable(mixed): string $e */
/** @var callable(?string): string $markdown */
/** @var callable(?ExcellentCms\Sdk\MediaFile, int): string $img */

// The field type "code": {language, file, code} - or plain text
$code = $block['code'];
$text = is_array($code) ? (string)($code['code'] ?? '') : (string)$code;
$file = $block['file'] ?: (is_array($code) ? ($code['file'] ?? null) : null);
$language = is_array($code) ? (string)($code['language'] ?? '') : '';
?>
<figure class="block code" id="block-<?= $e($block->key()) ?>" data-type="<?= $e($block->type()) ?> · <?= $e($block->key()) ?>">
  <?php if ($file): ?><figcaption><?= $e($file) ?></figcaption><?php endif ?>
  <pre><code<?= '' !== $language && 'text' !== $language ? ' class="language-'.$e($language).'"' : '' ?>><?= $e($text) ?></code></pre>
</figure>
