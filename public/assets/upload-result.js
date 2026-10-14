// Resultado de la última carga: el informe (ya escapado por el servidor) se mueve al modal común en vez de duplicarse.
(() => {
  const root = document.querySelector('[data-upload-result]');
  const dialog = root?.querySelector('dialog');
  if (!dialog || !window.CitaYa || typeof dialog.showModal !== 'function') return;
  root.querySelector('[data-upload-result-content]').append(root.querySelector('[data-upload-result-report]'));
  const launch = root.querySelector('[data-open-upload-result]');
  launch.hidden = false;
  if (root.dataset.autoOpen === '1') window.CitaYa.open(dialog, launch.querySelector('a'));
})();
