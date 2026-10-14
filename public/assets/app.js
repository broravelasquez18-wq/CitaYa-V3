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

// Cuadro de gestión de la solicitud (detalle del envío): estado, plantillas, adjuntos y envío.
(() => {
  const formatSize = bytes => bytes >= 1048576 ? (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
  const templateTexts = form => [...form.querySelectorAll('[data-template-text]')].map(button => button.dataset.templateText);
  const fillTemplate = (form, decision, force) => {
    const comment = form.querySelector('[data-case-comment]');
    // Al elegir el estado se sugiere la primera plantilla de ese estado (Aprobado → historia aprobada).
    const button = form.querySelector(`[data-template][data-decision="${decision}"][data-default]`) || form.querySelector(`[data-template][data-decision="${decision}"]`);
    if (!comment || !button) return;
    const current = comment.value.trim();
    if (force || current === '' || templateTexts(form).includes(current)) comment.value = button.dataset.templateText;
  };
  const selectDecision = (form, decision) => {
    const radio = form.querySelector(`input[name="decision"][value="${decision}"]`);
    if (!radio) return;
    radio.checked = true;
    const menu = form.querySelector('[data-decision-menu]');
    menu.querySelector('[data-decision-label]').textContent = radio.closest('label').querySelector('[data-option-label]').textContent;
    menu.dataset.decision = decision;
    menu.open = false;
  };
  const listFiles = form => {
    const input = form.querySelector('[data-drop-input]');
    const list = form.querySelector('[data-drop-list]');
    const max = Number(form.querySelector('[data-drop]').dataset.max || 0);
    const files = [...input.files];
    const total = files.reduce((sum, file) => sum + file.size, 0);
    list.replaceChildren(...files.map(file => {
      const item = document.createElement('li');
      item.textContent = file.name;
      const size = document.createElement('small');
      size.textContent = formatSize(file.size);
      item.append(size);
      return item;
    }));
    if (max && total > max) {
      const warning = document.createElement('li');
      warning.className = 'case-files-warning';
      warning.textContent = `Los adjuntos suman ${formatSize(total)} y el límite del correo es ${formatSize(max)}. Quita alguno.`;
      list.append(warning);
    }
    return {files, total, max};
  };
  document.addEventListener('change', event => {
    const form = event.target.closest('[data-case-respond]');
    if (!form) return;
    if (event.target.name === 'decision') { selectDecision(form, event.target.value); fillTemplate(form, event.target.value, false); }
    if (event.target.matches('[data-drop-input]')) listFiles(form);
  });
  document.addEventListener('click', event => {
    const template = event.target.closest('[data-template]');
    if (template) {
      const form = template.closest('[data-case-respond]');
      const comment = form.querySelector('[data-case-comment]');
      const current = comment.value.trim();
      if (current && !templateTexts(form).includes(current) && !confirm('¿Reemplazar el comentario que escribiste por la plantilla?')) return;
      comment.value = template.dataset.templateText;
      selectDecision(form, template.dataset.decision);
      template.closest('details').open = false;
      comment.focus();
      return;
    }
    const close = event.target.closest('[data-close-case]');
    if (close) {
      const dialog = close.closest('dialog');
      if (dialog) dialog.close(); else location.href = '?page=deliveries';
      return;
    }
    // Cierra los menús desplegables al hacer clic fuera de ellos.
    document.querySelectorAll('.case-menu[open]').forEach(menu => { if (!menu.contains(event.target)) menu.open = false; });
  });
  ['dragenter', 'dragover'].forEach(type => document.addEventListener(type, event => {
    const zone = event.target.closest?.('[data-drop]');
    if (!zone) return;
    event.preventDefault();
    zone.classList.add('dragging');
  }));
  ['dragleave', 'drop'].forEach(type => document.addEventListener(type, event => {
    const zone = event.target.closest?.('[data-drop]');
    if (!zone) return;
    zone.classList.remove('dragging');
    if (type !== 'drop') return;
    event.preventDefault();
    const input = zone.querySelector('[data-drop-input]');
    const transfer = new DataTransfer();
    [...input.files, ...event.dataTransfer.files].forEach(file => transfer.items.add(file));
    input.files = transfer.files;
    listFiles(zone.closest('[data-case-respond]'));
  }));
  document.addEventListener('submit', event => {
    const form = event.target.closest('[data-case-respond]');
    if (!form) return;
    const decision = form.querySelector('input[name="decision"]:checked');
    const {files, total, max} = listFiles(form);
    let problem = '';
    if (!decision) problem = 'Selecciona el estado: Aprobado o No Registra.';
    else if (decision.value === 'approved' && !files.length) problem = 'Para «Aprobado» adjunta los documentos que recibirá el paciente.';
    else if (max && total > max) problem = 'Los adjuntos superan el límite del correo. Quita alguno.';
    if (problem) {
      event.preventDefault();
      alert(problem);
      if (!decision) form.querySelector('[data-decision-menu]').open = true;
      return;
    }
    form.querySelector('button[type="submit"]').disabled = true;
  });
})();

// Ventana «Historial del caso»: se abre desde la lista de solicitudes o desde el detalle (encima de él).
(() => {
  const modal = document.querySelector('#history-modal');
  if (!modal || typeof modal.showModal !== 'function') return;
  const content = modal.querySelector('[data-history-content]');
  const reference = modal.querySelector('[data-history-reference]');
  let pending = null;
  let opener = null;
  document.addEventListener('click', async event => {
    const link = event.target.closest('[data-history-modal]');
    if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    pending?.abort();
    const controller = new AbortController();
    pending = controller;
    opener = link;
    content.textContent = 'Cargando el historial…';
    content.setAttribute('aria-busy', 'true');
    reference.textContent = '';
    if (!modal.open) modal.showModal();
    document.body.classList.add('delivery-modal-open');
    const url = new URL(link.href);
    url.searchParams.set('fragment', '1');
    try {
      const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin', cache: 'no-store'});
      if (pending !== controller) return;
      if (response.status === 401 || response.redirected) { content.textContent = 'Tu sesión administrativa venció. Vuelve a iniciar sesión.'; return; }
      if (!response.ok && response.status !== 404) throw new Error('history_unavailable');
      content.innerHTML = await response.text();
      const title = content.querySelector('[data-case-title]');
      if (title) reference.textContent = title.textContent;
    } catch (error) {
      if (error.name !== 'AbortError' && pending === controller) content.textContent = 'No se pudo cargar el historial. Cierra esta ventana e inténtalo nuevamente.';
    } finally {
      if (pending === controller) content.removeAttribute('aria-busy');
    }
  });
  modal.addEventListener('click', event => {
    if (event.target.closest('[data-close-history]')) { modal.close(); return; }
    if (event.target !== modal) return;
    const bounds = modal.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) modal.close();
  });
  modal.addEventListener('close', () => {
    pending?.abort();
    pending = null;
    content.replaceChildren();
    if (!document.querySelector('#delivery-modal[open]')) document.body.classList.remove('delivery-modal-open');
    opener?.focus();
  });
})();

// Chat de un reporte de error: baja al último mensaje, se actualiza cada 8 s y Ctrl+Enter envía.
(() => {
  const thread = document.querySelector('[data-chat-thread]');
  if (!thread) return;
  const bottom = () => { thread.scrollTop = thread.scrollHeight; };
  bottom();
  let last = thread.innerHTML;
  setInterval(async () => {
    if (document.hidden) return;
    try {
      const response = await fetch(thread.dataset.chatUrl, {credentials: 'same-origin', cache: 'no-store'});
      if (!response.ok || response.redirected) return;
      const html = await response.text();
      if (html.trim() && html !== last) { last = html; thread.innerHTML = html; bottom(); }
    } catch {}
  }, 8000);
  const input = document.querySelector('[data-chat-input]');
  input?.addEventListener('keydown', event => {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) { event.preventDefault(); input.form.requestSubmit(); }
  });
  input?.form.addEventListener('submit', () => { input.form.querySelector('button').disabled = true; });
})();
