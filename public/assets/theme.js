// Tema claro u oscuro del inicio de sesión. Se carga en <head> sin defer para aplicar la elección guardada antes de pintar.
(() => {
  try {
    const saved = localStorage.getItem('citaya-theme');
    if (saved === 'light' || saved === 'dark') document.documentElement.dataset.theme = saved;
  } catch {}
})();
