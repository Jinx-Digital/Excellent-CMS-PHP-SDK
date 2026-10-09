<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk;

/**
 * Live editing in the preview of the CMS. The website renders its blocks with an editable renderer
 * and adds the script of this class; in the preview panel of the CMS editors then:
 *
 * - see every block outlined on hover, with its type,
 * - click a block: the CMS opens its form in a popup (the same fields as in the editor),
 * - type: the page renders the changed blocks right away - before anything is saved,
 * - add blocks with the "+" at the edges, move them by drag & drop, duplicate or remove them.
 *
 *     $cms = $cms->preview($_GET['token'] ?? null);
 *     $renderer = (new BlockRenderer(directory: __DIR__.'/blocks'))->editable($cms->isPreview());
 *
 *     // Renders the unsaved blocks the script posts back (only with a valid preview token)
 *     LiveEdit::handle($cms, $renderer, entity: 'pages', context: [...], variables: $cms->variables());
 *
 *     $page = $cms->entity('pages')->get($_GET['id']);
 *     echo $renderer->render($page->blocks('content'), [...]);
 *     if ($cms->isPreview()) echo LiveEdit::script('https://cms.example.com');
 *
 * The page listens to the CMS only (its origin); the script works only inside the preview panel.
 * After blocks were swapped it dispatches the event "excellent:rendered" on document - e.g. to
 * start animations of the new elements again.
 */
final class LiveEdit
{
    /** Header of the requests of the script that render blocks */
    public const HEADER = 'X-Excellent-Live';

    /**
     * Renders the posted blocks and ends the request - if it is such a request; otherwise nothing
     * happens. Needs the preview mode: the token is checked with a request to the entity.
     *
     * @param array<string, mixed> $context passed to the templates
     * @param array<string, mixed> $variables project variables ($cms->variables()): {{name}} in the
     *        unsaved values is replaced like the content API does
     */
    public static function handle(Client $cms, BlockRenderer $renderer, string $entity, array $context = [], array $variables = []): void
    {
        if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || '' === ($_SERVER['HTTP_'.strtoupper(str_replace('-', '_', self::HEADER))] ?? '')) {
            return;
        }
        header('Cache-Control: no-store');
        if (!$cms->isPreview()) {
            http_response_code(403);
            exit('Live editing needs the preview token.');
        }
        try {
            // A cheap request with the token: invalid or expired tokens fail here
            $cms->entity($entity)->limit(1)->get();
        } catch (Exception\AuthenticationException | Exception\AccessDeniedException) {
            http_response_code(403);
            exit('The preview token is invalid or expired.');
        }
        $data = json_decode((string)file_get_contents('php://input'), true);
        $field = is_array($data) && is_string($data['field'] ?? null) ? $data['field'] : '';
        $blocks = is_array($data) && is_array($data['blocks'] ?? null) ? self::fill($data['blocks'], $variables) : [];
        // Types without a template of the website: rendered by the CMS with its templates
        $missing = array_filter($blocks, static fn(mixed $b): bool => is_array($b) && is_string($b[Block::TYPE] ?? null) && !$renderer->has($b[Block::TYPE]));
        if ([] !== $missing && '' !== $field) {
            try {
                $blocks = $cms->renderBlocks($entity, $field, array_values($blocks));
            } catch (Exception\ExcellentException) {
                // Without the CMS's HTML those blocks stay empty
            }
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $renderer->editable()->render(Block::list($blocks, '' !== $field ? $field : null), $context + ['field' => $field]);
        exit;
    }

    /**
     * Script and styles for the page in the preview.
     *
     * @param string $cmsOrigin origin of the admin app of the CMS, e.g. https://cms.example.com
     * @param string $renderUrl where blocks are rendered (LiveEdit::handle) - default: the page itself
     */
    public static function script(string $cmsOrigin, string $renderUrl = ''): string
    {
        $origin = (string)preg_replace('#^(https?://[^/]+).*$#', '$1', rtrim($cmsOrigin, '/'));
        $config = json_encode(['origin' => $origin, 'render' => $renderUrl, 'header' => self::HEADER], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
        return <<<HTML
<style>
  .excellent-live [data-excellent-block] { cursor: pointer; transition: outline-color .15s; outline: 2px dashed transparent; outline-offset: 4px; }
  .excellent-live [data-excellent-block]:hover { outline-color: rgba(5, 150, 105, .55); }
  .excellent-live [data-excellent-block].excellent-selected { outline: 2px solid #059669; }
  .excellent-label, .excellent-toolbar { position: fixed; z-index: 2147483647; font: 600 12px/1.6 ui-sans-serif, system-ui, sans-serif; }
  .excellent-label { pointer-events: none; padding: 2px 8px; border-radius: 6px; background: #059669; color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.2); opacity: 0; transition: opacity .1s; }
  .excellent-label.is-visible { opacity: 1; }
  .excellent-toolbar { display: none; gap: 2px; padding: 3px; border-radius: 8px; background: #111827; box-shadow: 0 4px 14px rgba(0,0,0,.3); }
  .excellent-toolbar.is-visible { display: flex; }
  .excellent-toolbar button { all: unset; display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 24px; cursor: pointer; border-radius: 5px; color: #fff; }
  .excellent-toolbar button svg { width: 15px; height: 15px; }
  .excellent-toolbar button:hover { background: #059669; }
  .excellent-toolbar button[data-action=move] { cursor: grab; }
  .excellent-toolbar button[data-action=remove]:hover { background: #dc2626; }
  .excellent-dragging, .excellent-dragging * { cursor: grabbing !important; user-select: none !important; }
  .excellent-dragging .excellent-toolbar, .excellent-dragging .excellent-plus, .excellent-dragging .excellent-label { display: none !important; }
  .excellent-live [data-excellent-block].excellent-moving { opacity: .35; outline: 2px dashed #059669; }
  .excellent-plus { position: fixed; z-index: 2147483647; display: none; width: 28px; height: 28px; margin: -14px 0 0 -14px; padding: 0; border: 2px solid #fff; border-radius: 50%; background: #059669; color: #fff; font: 600 20px/22px ui-sans-serif, system-ui, sans-serif; text-align: center; cursor: pointer; box-shadow: 0 2px 10px rgba(0,0,0,.3); transition: transform .1s; }
  .excellent-plus.is-visible { display: block; }
  .excellent-plus:hover { transform: scale(1.15); }
  .excellent-gap { position: fixed; z-index: 2147483646; display: none; height: 2px; margin-top: -1px; background: #059669; pointer-events: none; }
  .excellent-gap.is-visible { display: block; }
</style>
<script>
(() => {
  const config = {$config}
  // Only inside the preview panel of the CMS
  if (window.parent === window) return
  document.documentElement.classList.add('excellent-live')
  const label = document.createElement('div')
  label.className = 'excellent-label'
  // On the block under the mouse (else the selected one): move by drag & drop, duplicate, remove
  const icon = (path) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>'
  const toolbar = document.createElement('div')
  toolbar.className = 'excellent-toolbar'
  toolbar.innerHTML = '<button type="button" data-action="move" title="Drag to move the block">' + icon('<circle cx="9" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="19" r="1"/>') + '</button>'
    + '<button type="button" data-action="duplicate" title="Duplicate the block">' + icon('<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>') + '</button>'
    + '<button type="button" data-action="remove" title="Remove the block">' + icon('<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>') + '</button>'
  // Near the top or bottom edge of a block: "+" adds a block there (the CMS asks for the type)
  const plus = document.createElement('button')
  plus.type = 'button'
  plus.className = 'excellent-plus'
  plus.textContent = '+'
  const gap = document.createElement('div')
  gap.className = 'excellent-gap'
  document.body.append(label, toolbar, gap, plus)
  let edge = null
  const blockOf = (target) => target instanceof Element ? target.closest('[data-excellent-block]') : null
  const find = (key) => key ? document.querySelector('[data-excellent-block="' + CSS.escape(key) + '"]') : null
  const send = (message) => window.parent.postMessage(message, config.origin)
  let selectedKey = null
  let hoverKey = null
  let scrollTo = false
  let drag = null

  // Inside the top right corner of the block - the mouse can reach it without leaving the block
  const place = () => {
    const block = find(hoverKey) ?? find(selectedKey)
    toolbar.classList.toggle('is-visible', !!block && !drag)
    if (!block) return
    toolbar.dataset.key = block.dataset.excellentBlock
    const box = block.getBoundingClientRect()
    toolbar.style.top = Math.min(window.innerHeight - 36, Math.max(4, box.top + 6)) + 'px'
    toolbar.style.left = Math.max(4, Math.min(window.innerWidth - toolbar.offsetWidth - 4, box.right - toolbar.offsetWidth - 6)) + 'px'
  }
  const select = (key, scroll = false) => {
    selectedKey = key
    document.querySelectorAll('.excellent-selected').forEach((el) => el.classList.remove('excellent-selected'))
    const block = find(key)
    if (block) {
      block.classList.add('excellent-selected')
      if (scroll) block.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }
    place()
    return block
  }
  window.addEventListener('scroll', () => requestAnimationFrame(place), { passive: true })
  window.addEventListener('resize', place)

  toolbar.addEventListener('click', (event) => {
    const action = event.target instanceof Element ? event.target.closest('button')?.dataset.action : null
    const block = find(toolbar.dataset.key)
    if (!action || action === 'move' || !block) return
    const message = { field: block.dataset.excellentField || null, key: block.dataset.excellentBlock }
    if (action === 'remove') send({ type: 'excellent:remove', ...message })
    if (action === 'duplicate') send({ type: 'excellent:duplicate', ...message })
  })

  // Drag & drop: the handle of the toolbar, dropped before or after another block (the CMS checks
  // that the block may go into that list)
  const dropAt = (x, y) => {
    let target = blockOf(document.elementFromPoint(x, y))
    const moving = find(drag.key)
    // Not into itself
    while (target && moving && (target === moving || moving.contains(target))) target = blockOf(target.parentElement)
    if (!target) return null
    const box = target.getBoundingClientRect()
    return { key: target.dataset.excellentBlock, field: target.dataset.excellentField || null, position: y < box.top + box.height / 2 ? 'before' : 'after', box }
  }
  const endDrag = (drop) => {
    if (!drag) return
    const { key, field } = drag
    find(key)?.classList.remove('excellent-moving')
    document.documentElement.classList.remove('excellent-dragging')
    gap.classList.remove('is-visible')
    drag = null
    if (drop && drop.key !== key) send({ type: 'excellent:move', key, field, target: drop.key, targetField: drop.field, position: drop.position })
    place()
  }
  toolbar.addEventListener('pointerdown', (event) => {
    const handle = event.target instanceof Element ? event.target.closest('[data-action=move]') : null
    const block = find(toolbar.dataset.key)
    if (!handle || !block) return
    event.preventDefault()
    drag = { key: block.dataset.excellentBlock, field: block.dataset.excellentField || null, drop: null }
    block.classList.add('excellent-moving')
    document.documentElement.classList.add('excellent-dragging')
    hideEdge()
    label.classList.remove('is-visible')
    place()
  })
  document.addEventListener('pointermove', (event) => {
    if (!drag) return
    drag.drop = dropAt(event.clientX, event.clientY)
    if (!drag.drop) { gap.classList.remove('is-visible'); return }
    const { box, position } = drag.drop
    gap.style.top = (position === 'before' ? box.top : box.bottom) + 'px'
    gap.style.left = box.left + 'px'
    gap.style.width = box.width + 'px'
    gap.classList.add('is-visible')
    // Near the edges of the window: scroll
    if (event.clientY < 40) window.scrollBy(0, -12)
    else if (event.clientY > window.innerHeight - 40) window.scrollBy(0, 12)
  })
  document.addEventListener('pointerup', () => { if (drag) endDrag(drag.drop) })
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') endDrag(null) })
  const hideEdge = () => {
    edge = null
    plus.classList.remove('is-visible')
    gap.classList.remove('is-visible')
  }
  document.addEventListener('mousemove', (event) => {
    if (drag || event.target === plus || toolbar.contains(event.target)) return
    const block = blockOf(event.target)
    if (!block) { hideEdge(); return }
    const box = block.getBoundingClientRect()
    const zone = Math.min(28, box.height / 4)
    const position = event.clientY - box.top < zone ? 'before' : box.bottom - event.clientY < zone ? 'after' : null
    if (!position) { hideEdge(); return }
    edge = { key: block.dataset.excellentBlock, field: block.dataset.excellentField || null, position }
    const y = position === 'before' ? box.top : box.bottom
    plus.style.top = y + 'px'
    plus.style.left = (box.left + box.width / 2) + 'px'
    plus.title = position === 'before' ? 'Add a block before' : 'Add a block after'
    gap.style.top = y + 'px'
    gap.style.left = box.left + 'px'
    gap.style.width = box.width + 'px'
    plus.classList.add('is-visible')
    gap.classList.add('is-visible')
  })
  window.addEventListener('scroll', hideEdge, { passive: true })
  plus.addEventListener('click', (event) => {
    event.preventDefault()
    if (edge) send({ type: 'excellent:insert', key: edge.key, field: edge.field, position: edge.position })
    hideEdge()
  })
  document.addEventListener('mouseover', (event) => {
    if (drag || event.target === plus || toolbar.contains(event.target)) return
    const block = blockOf(event.target)
    const key = block?.dataset.excellentBlock ?? null
    if (key !== hoverKey) { hoverKey = key; place() }
    if (!block) { label.classList.remove('is-visible'); return }
    const box = block.getBoundingClientRect()
    label.textContent = block.dataset.excellentType + ' – click to edit'
    label.style.top = Math.max(4, box.top - 12) + 'px'
    label.style.left = Math.max(4, box.left + 8) + 'px'
    label.classList.add('is-visible')
  })
  document.addEventListener('mouseleave', () => {
    label.classList.remove('is-visible')
    hoverKey = null
    place()
  })
  // A click selects the (innermost) block in the CMS - links and buttons inside do not navigate while editing
  document.addEventListener('click', (event) => {
    if (event.target === plus || toolbar.contains(event.target)) return
    const block = blockOf(event.target)
    if (!block) return
    event.preventDefault()
    event.stopPropagation()
    select(block.dataset.excellentBlock)
    send({ type: 'excellent:select', field: block.dataset.excellentField || null, key: block.dataset.excellentBlock })
  }, true)

  // Unsaved values from the CMS: render the blocks fields of the page again
  let pending = null
  window.addEventListener('message', async (event) => {
    if (event.origin !== config.origin || !event.data || typeof event.data !== 'object') return
    if (event.data.type === 'excellent:highlight') {
      // A block that is not rendered yet (just added) is selected after the next render
      scrollTo = !select(event.data.key ?? null, !!event.data.scroll) && !!event.data.scroll
      return
    }
    if (event.data.type !== 'excellent:preview' || !event.data.record) return
    const record = event.data.record
    for (const container of document.querySelectorAll('[data-excellent-blocks]')) {
      const field = container.dataset.excellentBlocks
      if (!Array.isArray(record[field])) continue
      const request = pending = fetch(config.render || location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', [config.header]: '1' },
        body: JSON.stringify({ field, blocks: record[field] }),
      })
      const response = await request
      // Only the answer to the newest change counts
      if (request !== pending || !response.ok) continue
      const template = document.createElement('template')
      template.innerHTML = await response.text()
      const next = template.content.firstElementChild
      if (next) container.replaceWith(next)
    }
    if (selectedKey && select(selectedKey, scrollTo)) scrollTo = false
    document.dispatchEvent(new CustomEvent('excellent:rendered'))
  })
  send({ type: 'excellent:ready' })
})()
</script>
HTML;
    }

    /**
     * {{name}} of the project variables in the posted values, as the content API fills them in.
     *
     * @param array<mixed> $value
     * @param array<string, mixed> $variables
     * @return array<mixed>
     */
    private static function fill(array $value, array $variables): array
    {
        if ([] === $variables) {
            return $value;
        }
        array_walk_recursive($value, static function (mixed &$item) use ($variables): void {
            if (is_string($item) && str_contains($item, '{{')) {
                $item = (string)preg_replace_callback('/\{\{\s*(?:project\.)?([a-z_][a-z0-9_]*)\s*\}\}/i', static fn(array $m): string => is_scalar($variables[$m[1]] ?? null) ? (string)$variables[$m[1]] : $m[0], $item);
            }
        });
        return $value;
    }
}
