// Galleries with "click: lightbox" (blocks/gallery.php): the image enlarged in the <dialog class="lightbox">, arrows and ← → to the others
(() => {
  const dialog = document.querySelector('.lightbox')
  let links = [], index = 0
  const show = (i) => { index = (i + links.length) % links.length; dialog.querySelector('img').src = links[index].href }
  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[data-lightbox]')
    if (!link || event.defaultPrevented) return
    event.preventDefault()
    links = [...document.querySelectorAll(`a[data-lightbox="${CSS.escape(link.dataset.lightbox)}"]`)]
    show(links.indexOf(link))
    dialog.showModal()
  })
  dialog.addEventListener('click', (event) => {
    if (event.target.closest('[data-prev]')) show(index - 1)
    else if (event.target.closest('[data-next]')) show(index + 1)
    else if (event.target.closest('[data-close]') || event.target === dialog) dialog.close()
  })
  dialog.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowLeft') show(index - 1)
    if (event.key === 'ArrowRight') show(index + 1)
  })
})()
