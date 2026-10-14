(() => {
  const modal = document.querySelector('#upload-progress');
  const center = document.querySelector('[data-upload-center]');
  if (!modal || !center || typeof modal.showModal !== 'function') return;
  const title = modal.querySelector('h2');
  const subtitle = modal.querySelector('[data-upload-subtitle]');
  const message = modal.querySelector('#upload-progress-message');
  const note = modal.querySelector('[data-upload-note]');
  const progress = modal.querySelector('[role="progressbar"]');
  const arc = modal.querySelector('.queue-ring-fill');
  const percent = modal.querySelector('[data-upload-percent]');
  const close = modal.querySelector('button');
  let busy = false;
  let opener;
  modal.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
  close.addEventListener('click', () => modal.close());
  modal.addEventListener('close', () => {
    document.body.classList.remove('queue-progress-open');
    opener?.focus();
  });
  // Runs after the existing form validation and submit-button handling.
  center.querySelectorAll('form[enctype="multipart/form-data"]').forEach(form => {
    form.addEventListener('submit', event => {
      if (event.defaultPrevented) return;
      event.preventDefault();
      if (busy) return;
      const data = new FormData(form);
      opener = event.submitter;
      busy = true;
      close.hidden = true;
      note.textContent = 'Mantén esta página abierta hasta que termine.';
      modal.classList.remove('upload-processing', 'upload-error');
      title.textContent = 'Cargando PDF…';
      subtitle.textContent = message.textContent = 'Subiendo archivos al servidor…';
      progress.setAttribute('aria-valuemin', '0');
      progress.setAttribute('aria-valuemax', '100');
      progress.setAttribute('aria-valuenow', '0');
      progress.removeAttribute('aria-valuetext');
      arc.setAttribute('stroke-dasharray', '0 100');
      percent.textContent = '0%';
      modal.showModal();
      document.body.classList.add('queue-progress-open');
      const processing = () => {
        modal.classList.add('upload-processing');
        progress.removeAttribute('aria-valuenow');
        progress.setAttribute('aria-valuetext', 'Leyendo y guardando los PDF');
        arc.setAttribute('stroke-dasharray', '47 100');
        percent.textContent = '···';
        title.textContent = 'Guardando historias…';
        subtitle.textContent = 'Archivos recibidos. Procesando…';
        message.textContent = 'Leyendo, validando y organizando los PDF…';
      };
      const fail = text => {
        busy = false;
        modal.classList.add('upload-error');
        close.hidden = false;
        title.textContent = 'Revisa el resultado de la carga';
        subtitle.textContent = 'La carga requiere tu atención';
        message.textContent = text;
        percent.textContent = '!';
        progress.removeAttribute('aria-valuenow');
        progress.setAttribute('aria-valuetext', 'Carga sin confirmar');
        note.textContent = 'Cierra este mensaje para volver al formulario.';
        if (opener) { opener.disabled = false; opener.removeAttribute('aria-busy'); }
        window.CitaYa?.toast({tone: 'error', title: 'La carga no se confirmó', text});
        const bulkNotice = form.querySelector('[data-bulk-progress]');
        if (bulkNotice) bulkNotice.hidden = true;
        close.focus();
      };
      const xhr = new XMLHttpRequest();
      xhr.upload.addEventListener('progress', event => {
        if (!event.lengthComputable || !event.total) return;
        const value = Math.min(100, Math.round(event.loaded / event.total * 100));
        percent.textContent = `${value}%`;
        progress.setAttribute('aria-valuenow', String(value));
        arc.setAttribute('stroke-dasharray', `${value} 100`);
      });
      xhr.upload.addEventListener('load', processing);
      xhr.addEventListener('load', () => {
        const result = xhr.response;
        if (xhr.status >= 200 && xhr.status < 300 && result?.redirect) {
          const target = new URL(result.redirect, window.location.href);
          if (target.origin === window.location.origin) { window.location.assign(target.href); return; }
        }
        const errors = {
          413: 'Los PDF superan el tamaño permitido por el servidor. Divide la carga en lotes más pequeños.',
          401: 'Tu sesión venció. Vuelve a iniciar sesión y selecciona los PDF nuevamente.',
          403: 'El servidor rechazó la carga. Recarga la página y comprueba que tu sesión siga activa.',
          502: 'El servidor no pudo responder. Revisa el repositorio antes de repetir la carga.',
          504: 'El servidor agotó el tiempo de espera. Revisa el repositorio antes de repetir la carga.'
        };
        fail(result?.error || errors[xhr.status] || `No se pudo confirmar la carga (HTTP ${xhr.status}). Revisa el repositorio antes de volver a subir los archivos.`);
      });
      const connectionError = () => fail('Se perdió la conexión o se agotó el tiempo de espera. Revisa el repositorio antes de repetir la carga: el servidor podría haberla guardado.');
      xhr.addEventListener('error', connectionError);
      xhr.addEventListener('timeout', connectionError);
      xhr.addEventListener('abort', connectionError);
      try {
        // The hidden input named "action" shadows the form.action DOM property.
        xhr.open('POST', form.getAttribute('action') || window.location.href);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.responseType = 'json';
        xhr.timeout = 300000;
        xhr.send(data);
      } catch { connectionError(); }
    });
  });
})();
