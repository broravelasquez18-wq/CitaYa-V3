// La barra de direcciones muestra solo la dirección base del sitio: sin ?page=, ids ni términos de búsqueda.
// La dirección real queda en el estado del historial de la pestaña (history.state), así que recargar, Atrás y
// Adelante vuelven a la página correcta. No es una medida de seguridad: la dirección sigue existiendo.
(() => {
  const key = 'citayaUrl';
  const saved = history.state && history.state[key];
  // Recarga o Atrás/Adelante sobre una entrada ya limpiada: volver a cargar su dirección real.
  if (!location.search && saved && saved !== location.pathname) { location.replace(saved + location.hash); return; }
  if (location.search) {
    const real = location.pathname + location.search;
    history.replaceState(Object.assign({}, history.state, {[key]: real}), '', location.pathname + location.hash);
  }
  // Dirección real de la página actual, para el código que antes leía location.href o location.search.
  window.citayaUrl = () => new URL((history.state && history.state[key]) || location.pathname, location.href);
  // Formularios sin action envían a la dirección del documento: se les da la real para no perder ?page=.
  document.addEventListener('submit', event => {
    const form = event.target;
    if (form instanceof HTMLFormElement && !form.getAttribute('action')) form.setAttribute('action', window.citayaUrl().pathname + window.citayaUrl().search);
  }, true);
})();
