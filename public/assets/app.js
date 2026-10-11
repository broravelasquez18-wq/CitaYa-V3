const uploadCenter = document.querySelector('[data-upload-center]');
if (uploadCenter) {
  const choices = Array.from(uploadCenter.querySelectorAll('[data-upload-choice]'));
  const selectUpload = mode => {
    choices.forEach(choice => {
      const selected = choice.dataset.uploadChoice === mode;
      choice.setAttribute('aria-selected', String(selected));
      choice.tabIndex = selected ? 0 : -1;
    });
    uploadCenter.querySelectorAll('[data-upload-panel]').forEach(panel => {
      panel.hidden = panel.dataset.uploadPanel !== mode;
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', `upload-tab-${panel.dataset.uploadPanel}`);
    });
  };
  choices.forEach((choice, index) => {
    choice.addEventListener('click', () => selectUpload(choice.dataset.uploadChoice));
    choice.addEventListener('keydown', event => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? choices.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + choices.length) % choices.length;
      selectUpload(choices[next].dataset.uploadChoice);
      choices[next].focus();
    });
  });
  selectUpload(uploadCenter.dataset.uploadMode === 'bulk' ? 'bulk' : 'single');
  uploadCenter.querySelector('[data-upload-options]').hidden = false;
}
const reopenModal = document.querySelector('#reopen-modal');
if (reopenModal && typeof reopenModal.showModal === 'function') {
  let reopenOpener;
  document.querySelectorAll('[data-reopen-case]').forEach(button => {
    button.disabled = false;
    button.addEventListener('click', () => {
      reopenOpener = button;
      reopenModal.querySelector('[data-reopen-reference]').textContent = button.dataset.reference;
      const form = reopenModal.querySelector('form');
      form.elements.request_id.value = button.dataset.requestId;
      form.elements.expected_job_id.value = button.dataset.jobId;
      reopenModal.showModal();
      document.body.classList.add('delivery-modal-open');
    });
  });
  reopenModal.querySelector('[data-cancel-reopen]').addEventListener('click', () => reopenModal.close());
  reopenModal.addEventListener('click', event => {
    if (event.target !== reopenModal) return;
    const bounds = reopenModal.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) reopenModal.close();
  });
  reopenModal.addEventListener('close', () => {
    document.body.classList.remove('delivery-modal-open');
    reopenOpener?.focus();
  });
}
const bulkForm = document.querySelector('[data-bulk-upload]');
if (bulkForm) {
  const input = bulkForm.elements['bulk_pdfs[]'];
  const notice = bulkForm.querySelector('[data-bulk-error]');
  const validateBulk = () => {
    const files = Array.from(input.files);
    const bytes = files.reduce((total, file) => total + file.size, 0);
    bulkForm.elements.expected_files.value = files.length;
    bulkForm.querySelector('[data-bulk-selection]').textContent = `${files.length} PDF seleccionados · ${(bytes / 1024 ** 2).toFixed(1)} MB`;
    let error = '';
    if (files.length > Number(bulkForm.dataset.maxFiles)) error = `Selecciona hasta ${bulkForm.dataset.maxFiles} PDF por lote.`;
    else if (files.some(file => !file.name.toLowerCase().endsWith('.pdf'))) error = 'Selecciona únicamente archivos PDF.';
    else if (files.some(file => file.size > Number(bulkForm.dataset.maxFileBytes))) error = 'Un archivo supera el tamaño permitido. Revisa los límites indicados.';
    else if (bytes > Number(bulkForm.dataset.maxTotalBytes)) error = 'El lote supera el tamaño total permitido. Selecciona menos archivos.';
    notice.textContent = error;
    notice.hidden = !error;
    input.setCustomValidity(error);
    return !error && files.length > 0;
  };
  input.addEventListener('change', validateBulk);
  bulkForm.addEventListener('submit', event => {
    if (!validateBulk()) { event.preventDefault(); input.reportValidity(); return; }
    bulkForm.querySelector('[data-bulk-progress]').hidden = false;
  });
}
document.querySelectorAll('form').forEach(form => {
  form.addEventListener('submit', event => {
    if (event.defaultPrevented) return;
    if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { event.preventDefault(); return; }
    const button = event.submitter;
    if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
  });
});
const queueProgress = document.querySelector('#queue-progress');
if (queueProgress && typeof queueProgress.showModal === 'function') {
  const messages = {
    queued: ['Preparando tu envío…', 'Tu solicitud está en cola', 'Esperando turno para preparar tu historia…'],
    sending: ['Enviando tu historia…', 'Estamos procesando tu solicitud', 'Preparando los documentos y enviando el correo…'],
    retry: ['Esperando un nuevo intento…', 'Tu solicitud sigue en proceso', 'El envío se reintentará automáticamente.']
  };
  const updateQueue = state => {
    const [title, subtitle, message] = messages[state];
    queueProgress.querySelector('#queue-title').textContent = title;
    queueProgress.querySelector('[data-queue-subtitle]').textContent = subtitle;
    queueProgress.querySelector('#queue-message').textContent = message;
    queueProgress.querySelector('[role="progressbar"]').setAttribute('aria-valuetext', subtitle);
  };
  updateQueue(queueProgress.dataset.queueState);
  queueProgress.showModal();
  document.body.classList.add('queue-progress-open');
  queueProgress.querySelector('button').addEventListener('click', () => queueProgress.close());
  queueProgress.addEventListener('close', () => document.body.classList.remove('queue-progress-open'));
  const pollQueue = async () => {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(window.location.href, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
      if (response.redirected) { window.location.reload(); return; }
      if (!response.ok) throw new Error('queue_unavailable');
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const next = page.querySelector('#queue-progress');
      if (!next) { window.location.reload(); return; }
      updateQueue(next.dataset.queueState);
    } catch {
      queueProgress.querySelector('#queue-message').textContent = 'No pudimos consultar el estado. Volveremos a intentarlo; tu solicitud sigue registrada.';
    } finally {
      clearTimeout(timeout);
    }
    setTimeout(pollQueue, 5000);
  };
  setTimeout(pollQueue, 5000);
} else if (document.querySelector('[data-refresh]')) {
  setTimeout(() => window.location.reload(), 5000);
}
const unknownDate = document.querySelector('[name="date_unknown"]');
if (unknownDate) {
  const dateInput = unknownDate.form.elements.approximate_date;
  const dateHelp = document.querySelector('[data-date-help]');
  const syncDate = () => {
    dateInput.disabled = unknownDate.checked;
    dateInput.required = !unknownDate.checked;
    dateHelp.textContent = unknownDate.checked
      ? 'Te mostraremos la consulta más reciente disponible para que elijas si deseas recibirla.'
      : 'Si no hay historia ese día, te sugeriremos la consulta más reciente disponible.';
  };
  unknownDate.addEventListener('change', syncDate);
  window.addEventListener('pageshow', syncDate);
  syncDate();
}
const resendButton = document.querySelector('[data-resend-wait]');
if (resendButton) {
  const readyAt = Date.now() + Number(resendButton.dataset.resendWait) * 1000;
  const countdown = document.querySelector('[data-resend-countdown]');
  const timer = setInterval(() => {
    const seconds = Math.max(0, Math.ceil((readyAt - Date.now()) / 1000));
    countdown.textContent = seconds ? `Podrás reenviar en ${seconds} segundos.` : 'Puedes solicitar el reenvío ahora.';
    if (!seconds) { resendButton.disabled = false; clearInterval(timer); }
  }, 1000);
}

const deliveryModal = document.querySelector('#delivery-modal');
if (deliveryModal && typeof deliveryModal.showModal === 'function') {
  const content = deliveryModal.querySelector('[data-delivery-content]');
  const modalTitle = deliveryModal.querySelector('#delivery-modal-title');
  const defaultTitle = modalTitle.textContent;
  let pendingDelivery = null;
  let deliveryOpener = null;
  document.querySelectorAll('[data-delivery-modal]').forEach(link => {
    link.addEventListener('click', async event => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      pendingDelivery?.abort();
      const controller = new AbortController();
      pendingDelivery = controller;
      deliveryOpener = link;
      content.textContent = 'Cargando información del envío…';
      content.setAttribute('aria-busy', 'true');
      if (!deliveryModal.open) deliveryModal.showModal();
      document.body.classList.add('delivery-modal-open');
      const url = new URL(link.href);
      url.searchParams.set('fragment', '1');
      try {
        const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin', cache: 'no-store'});
        if (controller.signal.aborted || pendingDelivery !== controller) return;
        if (response.status === 401 || response.redirected) {
          content.textContent = 'Tu sesión administrativa venció. ';
          const login = document.createElement('a');
          login.href = '?page=admin-login';
          login.textContent = 'Volver a iniciar sesión';
          content.append(login);
          return;
        }
        if (!response.ok && response.status !== 404) throw new Error('delivery_unavailable');
        const html = await response.text();
        if (!controller.signal.aborted && pendingDelivery === controller) {
          content.innerHTML = html;
          const caseTitle = content.querySelector('[data-case-title]');
          if (caseTitle) modalTitle.textContent = caseTitle.textContent;
        }
      } catch (error) {
        if (error.name !== 'AbortError' && pendingDelivery === controller) {
          content.textContent = 'No se pudo cargar el envío. Cierra esta ventana e inténtalo nuevamente.';
        }
      } finally {
        if (pendingDelivery === controller) content.removeAttribute('aria-busy');
      }
    });
  });
  deliveryModal.querySelector('[data-close-delivery]').addEventListener('click', () => deliveryModal.close());
  deliveryModal.addEventListener('click', event => {
    if (event.target !== deliveryModal) return;
    const bounds = deliveryModal.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) deliveryModal.close();
  });
  deliveryModal.addEventListener('close', () => {
    pendingDelivery?.abort();
    pendingDelivery = null;
    content.replaceChildren();
    modalTitle.textContent = defaultTitle;
    content.removeAttribute('aria-busy');
    document.body.classList.remove('delivery-modal-open');
    deliveryOpener?.focus();
  });
}

// Botones de copiar (documento, número) en el detalle de la solicitud, también en contenido cargado después.
document.addEventListener('click', event => {
  const button = event.target.closest('[data-copy]');
  if (!button || !navigator.clipboard) return;
  navigator.clipboard.writeText(button.dataset.copy).then(() => {
    button.classList.add('copied');
    button.title = 'Copiado';
    setTimeout(() => { button.classList.remove('copied'); button.title = 'Copiar'; }, 1500);
  }).catch(() => {});
});
