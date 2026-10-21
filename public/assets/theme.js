// Preferencias de la vista guardadas en este navegador: tema claro u oscuro y menú lateral oculto en escritorio.
// Se carga en <head> sin defer para aplicarlas antes de pintar (sin parpadeo).
(() => {
  try {
    const saved = localStorage.getItem('citaya-theme');
    if (saved === 'light' || saved === 'dark') document.documentElement.dataset.theme = saved;
    if (localStorage.getItem('citaya-rail') === 'collapsed') document.documentElement.classList.add('rail-collapsed');
  } catch {}
})();
