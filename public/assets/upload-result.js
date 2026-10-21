// Resultado de la última carga: el informe (ya escapado por el servidor) se mueve al modal común en vez de duplicarse.
(() => {
  const root = document.querySelector('[data-upload-result]');
  const dialog = root?.querySelector('#upload-result');
  if (!dialog || !window.CitaYa || typeof dialog.showModal !== 'function') return;
  root.querySelector('[data-upload-result-content]').append(root.querySelector('[data-upload-result-report]'));
  const launch = root.querySelector('[data-open-upload-result]');
  launch.hidden = false;
  if (root.dataset.autoOpen === '1') window.CitaYa.open(dialog, launch.querySelector('a'));

  // Vista del PDF: se abre encima del resultado; sin JavaScript el enlace abre el PDF en otra pestaña.
  const viewer = root.querySelector('#pdf-preview');
  const frame = viewer.querySelector('[data-pdf-frame]');
  root.addEventListener('click', event => {
    const link = event.target.closest('[data-pdf-preview]');
    if (!link || event.ctrlKey || event.metaKey || event.shiftKey) return;
    event.preventDefault();
    const page = link.dataset.pdfPage;
    viewer.querySelector('#pdf-preview-title').textContent = link.dataset.pdfName;
    viewer.querySelector('[data-pdf-note]').textContent = page ? 'Se abre en la página ' + page + ', donde está el problema.' : '';
    viewer.querySelector('[data-pdf-open]').href = link.href;
    frame.src = link.href;
    window.CitaYa.open(viewer, link);
  });
  viewer.addEventListener('close', () => { frame.src = 'about:blank'; });
})();
