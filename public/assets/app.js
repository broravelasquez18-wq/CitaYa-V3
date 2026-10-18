// Superficies comunes (drawer, modal, aviso) y toasts. Las demás piezas usan window.CitaYa.
// Sin JavaScript los enlaces #id abren la superficie por :target; aquí se convierten en diálogos modales.
window.CitaYa = (() => {
  const supported = typeof HTMLDialogElement === 'function' && typeof HTMLDialogElement.prototype.showModal === 'function';
  const openers = new WeakMap();
  const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const syncBody = () => document.body.classList.toggle('overlay-open', !!document.querySelector('dialog.drawer[open],dialog.modal[open],dialog.alert-modal[open]'));
  const clearHash = id => { if (location.hash === '#' + id) history.replaceState(null, '', location.pathname + location.search); };

  const open = (dialog, opener = document.activeElement) => {
    if (!dialog) return;
    if (!supported) { location.hash = dialog.id; return; }
    if (dialog.open && dialog.matches(':modal')) return;
    if (dialog.open) dialog.close();
    dialog.classList.remove('is-closing');
    if (opener instanceof HTMLElement) openers.set(dialog, opener);
    dialog.showModal();
    syncBody();
    if (!dialog.querySelector('[autofocus]')) dialog.querySelector('.sheet-close,[data-close]')?.focus();
  };

  const close = (dialog, value) => {
    if (!dialog?.open || dialog.classList.contains('is-closing')) return;
    const finish = () => { dialog.classList.remove('is-closing'); dialog.close(value); };
    if (reduced()) { finish(); return; }
    dialog.classList.add('is-closing');
    let done = false;
    const once = () => { if (!done) { done = true; finish(); } };
    dialog.addEventListener('animationend', once, {once: true});
    setTimeout(once, 260);
  };

  document.addEventListener('close', event => {
    const dialog = event.target;
    if (!(dialog instanceof HTMLDialogElement)) return;
    syncBody();
    clearHash(dialog.id);
    if (dialog.dataset.once) try { sessionStorage.setItem('citaya-seen-' + dialog.dataset.once, '1'); } catch {}
    const opener = openers.get(dialog);
    openers.delete(dialog);
    if (opener?.isConnected) opener.focus();
  }, true);

  // Escape y clic en el fondo cierran con animación, salvo los avisos que exigen una acción.
  document.addEventListener('cancel', event => {
    const dialog = event.target;
    if (!dialog.matches?.('.drawer,.modal,.alert-modal')) return;
    event.preventDefault();
    if (!dialog.hasAttribute('data-persistent')) close(dialog);
  }, true);
  document.addEventListener('click', event => {
    const opener = event.target.closest('[data-open]');
    if (opener && supported) {
      event.preventDefault();
      open(document.getElementById(opener.dataset.open), opener);
      return;
    }
    const closer = event.target.closest('[data-close]');
    if (closer) {
      const dialog = closer.closest('dialog');
      if (!dialog) return;
      event.preventDefault();
      close(dialog);
      return;
    }
    const dialog = event.target;
    if (dialog instanceof HTMLDialogElement && dialog.matches('.drawer,.modal,.alert-modal') && !dialog.hasAttribute('data-persistent')) {
      const box = dialog.getBoundingClientRect();
      if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) close(dialog);
    }
  });

  // Toasts. tone: success | error | info | novelty. Los fallos no se ocultan solos.
  const icons = {
    success: '<circle cx="12" cy="12" r="9"/><path d="m8 12.2 2.8 2.8L16 9.6"/>',
    error: '<path d="M12 3.5 2.8 19.5h18.4Z"/><path d="M12 10v4.5M12 17.2h.01"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.8h.01"/>',
    novelty: '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>'
  };
  const svg = path => `<svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg>`;
  const stack = () => {
    let element = document.querySelector('[data-toasts]');
    if (!element) {
      element = document.createElement('div');
      element.className = 'toast-stack';
      element.dataset.toasts = '';
      element.setAttribute('aria-live', 'polite');
      document.body.append(element);
    }
    return element;
  };
  // Los toasts van en la capa superior (popover) para verse también sobre un modal abierto.
  const host = () => {
    const element = stack();
    if (typeof element.showPopover !== 'function') return element;
    element.popover = 'manual';
    const modalOpen = [...document.querySelectorAll('dialog[open]')].some(dialog => dialog.matches(':modal'));
    try {
      if (modalOpen && element.matches(':popover-open')) element.hidePopover();
      if (!element.matches(':popover-open')) element.showPopover();
    } catch {}
    return element;
  };
  const settle = () => {
    const element = stack();
    if (!element.children.length && element.matches?.(':popover-open')) try { element.hidePopover(); } catch {}
  };
  const dismiss = card => {
    if (!card.isConnected || card.classList.contains('is-leaving')) return;
    if (reduced()) { card.remove(); settle(); return; }
    card.classList.add('is-leaving');
    const remove = () => { card.remove(); settle(); };
    card.addEventListener('animationend', remove, {once: true});
    setTimeout(remove, 300);
  };
  const arm = card => {
    card.querySelector('[data-toast-close]')?.addEventListener('click', () => dismiss(card));
    if (card.classList.contains('error')) return;
    let left = Number(card.dataset.timeout || 6000);
    let started = Date.now();
    let timer = setTimeout(() => dismiss(card), left);
    const pause = () => { clearTimeout(timer); left -= Date.now() - started; };
    const resume = () => { started = Date.now(); timer = setTimeout(() => dismiss(card), Math.max(1500, left)); };
    card.addEventListener('mouseenter', pause);
    card.addEventListener('mouseleave', resume);
    card.addEventListener('focusin', pause);
    card.addEventListener('focusout', resume);
  };
  const toast = ({tone = 'info', title = '', text = '', action = null, timeout = 6000} = {}) => {
    const card = document.createElement('div');
    card.className = 'toast ' + tone;
    card.setAttribute('role', tone === 'error' ? 'alert' : 'status');
    card.dataset.toast = '';
    card.dataset.timeout = String(timeout);
    card.innerHTML = svg(icons[tone] || icons.info) + '<div class="toast-body"><strong></strong><p></p></div><button type="button" class="toast-close" data-toast-close aria-label="Cerrar aviso">' + svg('<path d="M6 6l12 12M18 6 6 18"/>') + '</button>';
    card.querySelector('strong').textContent = title;
    const body = card.querySelector('p');
    if (text) body.textContent = text; else body.remove();
    if (action) {
      const link = document.createElement('a');
      link.className = 'button';
      link.href = action.href;
      link.textContent = action.label;
      card.querySelector('.toast-body').append(link);
    }
    const element = host();
    element.append(card);
    while (element.children.length > 4) element.firstElementChild.remove();
    arm(card);
    return card;
  };
  document.querySelectorAll('[data-toasts] [data-toast]').forEach(arm);

  // Confirmación con el aviso común; devuelve una promesa con true si la persona acepta.
  const confirmDialog = ({title = '¿Confirmas esta acción?', text = '', label = 'Confirmar', tone = ''} = {}) => {
    const dialog = document.querySelector('#confirm-dialog');
    if (!dialog || !supported) return Promise.resolve(window.confirm([title, text].filter(Boolean).join('\n\n')));
    dialog.querySelector('[data-confirm-title]').textContent = title;
    dialog.querySelector('[data-confirm-text]').textContent = text;
    dialog.querySelector('[data-confirm-text]').hidden = !text;
    const accept = dialog.querySelector('[data-confirm-accept]');
    accept.textContent = label;
    dialog.classList.toggle('danger', tone === 'danger');
    return new Promise(resolve => {
      let accepted = false;
      const onAccept = () => { accepted = true; close(dialog); };
      accept.addEventListener('click', onAccept, {once: true});
      dialog.addEventListener('close', () => { accept.removeEventListener('click', onAccept); resolve(accepted); }, {once: true});
      open(dialog);
    });
  };

  // Superficies que el servidor pide abrir al cargar (un error reabre su formulario) o enlazadas por #id.
  const boot = () => {
    document.querySelectorAll('dialog[data-auto-open]').forEach(dialog => {
      if (dialog.dataset.once) try { if (sessionStorage.getItem('citaya-seen-' + dialog.dataset.once)) return; } catch {}
      open(dialog, null);
    });
    const target = location.hash.length > 1 && document.getElementById(decodeURIComponent(location.hash.slice(1)));
    if (target?.matches?.('dialog.drawer,dialog.modal')) open(target, null);
  };
  if (supported) boot();
  if (stack().children.length) host();

  return {open, close, toast, confirm: confirmDialog};
})();

// Formularios: confirmación previa (data-confirm) y botón ocupado mientras se envía. También en contenido cargado después.
document.addEventListener('submit', event => {
  const form = event.target;
  if (event.defaultPrevented || !(form instanceof HTMLFormElement)) return;
  if (form.dataset.confirm && form.dataset.confirmed !== '1') {
    event.preventDefault();
    const submitter = event.submitter;
    window.CitaYa.confirm({title: form.dataset.confirmTitle, text: form.dataset.confirm, label: form.dataset.confirmLabel, tone: form.dataset.confirmTone}).then(ok => {
      if (!ok) return;
      form.dataset.confirmed = '1';
      form.requestSubmit(submitter && form.contains(submitter) ? submitter : undefined);
      delete form.dataset.confirmed;
    });
    return;
  }
  const button = event.submitter;
  if (button) setTimeout(() => { if (!event.defaultPrevented) { button.disabled = true; button.setAttribute('aria-busy', 'true'); } });
});
window.addEventListener('pageshow', event => {
  if (event.persisted) document.querySelectorAll('[aria-busy="true"]').forEach(button => { button.disabled = false; button.removeAttribute('aria-busy'); });
});

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
const bulkForm = document.querySelector('[data-bulk-upload]');
if (bulkForm) {
  const input = bulkForm.elements['bulk_pdfs[]'];
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
    if (error) window.CitaYa.toast({tone: 'error', title: 'Revisa los PDF seleccionados', text: error});
    input.setCustomValidity(error);
    return !error && files.length > 0;
  };
  input.addEventListener('change', validateBulk);
  bulkForm.addEventListener('submit', event => {
    if (!validateBulk()) { event.preventDefault(); if (!input.files.length) input.reportValidity(); return; }
    bulkForm.querySelector('[data-bulk-progress]').hidden = false;
  });
}
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
// Formulario de solicitud del paciente: validación en lenguaje claro, ayudas al escribir y revisión antes de radicar.
(() => {
  const form = document.querySelector('[data-request-form]');
  if (!form) return;
  const el = form.elements;
  const field = name => form.querySelector(`[data-field="${name}"]`);
  const today = el.approximate_date?.max || '';
  const numericTypes = ['CC', 'TI', 'RC'];
  const docType = () => el.document_type.value || 'CC';
  const typeLabel = () => el.document_type.selectedOptions[0]?.textContent || '';

  // Mismas reglas que el servidor; devuelven el mensaje de error o ''.
  const rules = {
    document_number: () => {
      const value = el.document_number.value.trim();
      if (!value) return 'Escribe el número de documento.';
      const numeric = numericTypes.includes(docType());
      if (numeric ? !/^[0-9]{3,20}$/.test(value) : !/^[A-Za-z0-9]{3,24}$/.test(value)) return numeric ? 'El número debe tener solo dígitos (mínimo 3).' : 'Usa solo letras y números (mínimo 3).';
      return '';
    },
    full_name: () => {
      const value = el.full_name.value.trim();
      if (value.length < 3) return 'Escribe el nombre completo del paciente.';
      if (value.split(/\s+/).length < 2) return 'Escribe nombres y apellidos, no solo el nombre.';
      return '';
    },
    approximate_date: () => {
      if (el.date_unknown.checked) return '';
      const value = el.approximate_date.value;
      if (!value) return 'Elige la fecha o marca «No recuerdo la fecha».';
      if (today && value > today) return 'La fecha no puede ser posterior a hoy.';
      return '';
    },
    specialty_id: () => el.specialty_id.value === '' ? 'Elige la especialidad o «No recuerdo la especialidad».' : '',
    email: () => {
      const value = el.email.value.trim();
      if (!value) return 'Escribe el correo donde quieres recibir la historia.';
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) return 'Revisa el correo: debe tener la forma nombre@dominio.com.';
      return '';
    },
    whatsapp: () => {
      if (!el.whatsapp) return '';
      const digits = el.whatsapp.value.replace(/\D/g, '');
      if (!digits || (digits.length === 10 && digits[0] === '3')) return '';
      if (digits.length < 10 || digits.length > 15) return 'Escribe un celular de 10 dígitos (ej.: 300 123 4567) o el número con indicativo.';
      return '';
    }
  };
  const order = ['document_number', 'full_name', 'approximate_date', 'specialty_id', 'email', 'whatsapp'];
  const touched = new Set();
  const show = (name, message) => {
    const box = field(name);
    if (!box) return true;
    const error = box.querySelector('.field-error');
    const input = box.querySelector('input,select');
    error.textContent = message;
    error.hidden = !message;
    box.classList.toggle('has-error', !!message);
    box.classList.toggle('is-valid', !message && input.value.trim() !== '' && !input.disabled);
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
    return !message;
  };
  const check = name => show(name, rules[name]());
  const validateAll = () => {
    const bad = order.filter(name => { touched.add(name); return !check(name); });
    if (bad.length) {
      el[bad[0]].focus({preventScroll: true});
      field(bad[0]).scrollIntoView({block: 'center', behavior: 'smooth'});
    }
    return bad.length === 0;
  };

  // Ayudas al escribir.
  const syncDocument = () => {
    const numeric = numericTypes.includes(docType());
    el.document_number.inputMode = numeric ? 'numeric' : 'text';
    el.document_number.placeholder = numeric ? 'Ej.: 1023456789' : 'Ej.: AB123456';
    form.querySelector('[data-document-help]').textContent = numeric ? 'Solo números. Quitamos puntos y espacios por ti.' : 'Letras y números, sin espacios ni guiones.';
  };
  el.document_number.addEventListener('input', () => {
    const clean = numericTypes.includes(docType()) ? el.document_number.value.replace(/\D/g, '') : el.document_number.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    if (clean !== el.document_number.value) el.document_number.value = clean;
  });
  el.document_type.addEventListener('change', () => { syncDocument(); if (touched.has('document_number')) check('document_number'); });
  el.full_name.addEventListener('blur', () => { el.full_name.value = el.full_name.value.replace(/\s+/g, ' ').trim(); });

  const domains = ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com', 'yahoo.es', 'icloud.com', 'live.com', 'hotmail.es', 'outlook.es'];
  const distance = (a, b) => {
    const row = Array.from({length: b.length + 1}, (_, i) => i);
    for (let i = 1; i <= a.length; i++) {
      let prev = row[0]; row[0] = i;
      for (let j = 1; j <= b.length; j++) { const tmp = row[j]; row[j] = Math.min(row[j] + 1, row[j - 1] + 1, prev + (a[i - 1] === b[j - 1] ? 0 : 1)); prev = tmp; }
    }
    return row[b.length];
  };
  const suggestBox = form.querySelector('[data-email-suggest]');
  el.email.addEventListener('blur', () => {
    const [user, domain] = el.email.value.trim().toLowerCase().split('@');
    suggestBox.hidden = true;
    if (!user || !domain || domains.includes(domain)) return;
    const [best, cost] = domains.map(d => [d, distance(domain, d)]).sort((a, b) => a[1] - b[1])[0];
    if (cost === 0 || cost > 2) return;
    const fix = Object.assign(document.createElement('button'), {type: 'button', textContent: `${user}@${best}`});
    fix.addEventListener('click', () => { el.email.value = `${user}@${best}`; suggestBox.hidden = true; check('email'); el.email.focus(); });
    suggestBox.replaceChildren('¿Quisiste decir ', fix, '?');
    suggestBox.hidden = false;
  });

  const dateHelp = form.querySelector('[data-date-help]');
  const syncDate = () => {
    const unknown = el.date_unknown.checked;
    el.approximate_date.disabled = unknown;
    el.approximate_date.required = !unknown;
    dateHelp.textContent = unknown ? 'Te mostraremos la consulta más reciente disponible para que elijas si deseas recibirla.' : 'Buscaremos la consulta más reciente del paciente.';
    if (unknown || touched.has('approximate_date')) check('approximate_date');
  };
  el.date_unknown.addEventListener('change', syncDate);
  window.addEventListener('pageshow', syncDate);

  // Validación al salir del campo; después, mientras se corrige.
  order.forEach(name => {
    const input = el[name];
    if (!input) return;
    const instant = input.tagName === 'SELECT' || input.type === 'date';
    input.addEventListener('blur', () => { if (input.value.trim() !== '') touched.add(name); if (touched.has(name)) check(name); });
    input.addEventListener(instant ? 'change' : 'input', () => { if (instant) touched.add(name); if (touched.has(name)) check(name); });
  });
  form.addEventListener('reset', () => setTimeout(() => {
    touched.clear();
    order.forEach(name => field(name) && show(name, ''));
    form.querySelectorAll('.field').forEach(box => box.classList.remove('is-valid'));
    suggestBox.hidden = true;
    syncDocument(); syncDate();
    el.document_number.focus();
  }));

  // Revisión antes de radicar.
  const modal = form.querySelector('#review-modal');
  const fill = (key, text) => { form.querySelector(`[data-review="${key}"]`).textContent = text; };
  let confirmed = false;
  form.noValidate = true;
  form.addEventListener('submit', event => {
    if (confirmed) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (!validateAll()) { window.CitaYa?.toast({tone: 'error', title: 'Falta completar un dato', text: 'Revisa el campo marcado en rojo.'}); return; }
    fill('full_name', el.full_name.value.trim());
    fill('document', `${typeLabel()} ${el.document_number.value.trim()}`);
    fill('date', el.date_unknown.checked ? 'No recuerda la fecha' : el.approximate_date.value.split('-').reverse().join('/'));
    fill('specialty', el.specialty_id.selectedOptions[0]?.textContent || '');
    fill('email', el.email.value.trim());
    const phone = el.whatsapp?.value.trim();
    fill('whatsapp', phone ? `WhatsApp ${phone}` : (el.wa ? 'También por este chat de WhatsApp' : 'Sin WhatsApp'));
    if (window.CitaYa) window.CitaYa.open(modal, event.submitter); else { confirmed = true; form.submit(); }
  }, true);
  modal.addEventListener('click', event => {
    const edit = event.target.closest('[data-review-edit]');
    if (edit) {
      const section = form.querySelector(`[data-section="${edit.dataset.reviewEdit}"]`);
      window.CitaYa.close(modal);
      setTimeout(() => { section.scrollIntoView({block: 'start', behavior: 'smooth'}); section.querySelector('input:not([type=checkbox]),select').focus({preventScroll: true}); }, 220);
      return;
    }
    const accept = event.target.closest('[data-review-confirm]');
    if (accept) {
      confirmed = true;
      accept.disabled = true;
      accept.setAttribute('aria-busy', 'true');
      accept.lastChild.textContent = 'Radicando…';
      form.submit();
    }
  });

  syncDocument();
  syncDate();
  // Tras un error del servidor se marcan los datos que hay que corregir.
  if (document.querySelector('[data-toasts] .toast.error')) setTimeout(validateAll, 0);
})();

// Cambio de tema claro u oscuro en el inicio de sesión; la elección queda guardada en este navegador.
document.querySelectorAll('[data-theme-toggle]').forEach(button => {
  const root = document.documentElement;
  const label = button.querySelector('[data-theme-label]');
  const sync = () => {
    const light = root.dataset.theme === 'light';
    button.setAttribute('aria-pressed', String(light));
    label.textContent = light ? 'Modo oscuro' : 'Modo claro';
    button.setAttribute('aria-label', light ? 'Cambiar a modo oscuro' : 'Cambiar a modo claro');
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', light ? '#ffffff' : '#16273a');
  };
  button.hidden = false;
  button.addEventListener('click', () => {
    root.dataset.theme = root.dataset.theme === 'light' ? 'dark' : 'light';
    try { localStorage.setItem('citaya-theme', root.dataset.theme); } catch {}
    sync();
  });
  sync();
});

// Fondo animado: se pausa mientras se escribe y se reanuda un momento después de la última tecla.
if (document.body.classList.contains('ambient')) {
  let typingTimer;
  document.addEventListener('input', () => {
    document.body.classList.add('is-typing');
    clearTimeout(typingTimer);
    typingTimer = setTimeout(() => document.body.classList.remove('is-typing'), 1200);
  });
}

// Contraseña manual al editar una cuenta: si se escribe una nueva, la confirmación es obligatoria y debe coincidir.
document.addEventListener('input', event => {
  const form = event.target.form;
  const fresh = form?.querySelector('[data-password-new]');
  const confirm = form?.querySelector('[data-password-confirm]');
  if (!fresh || !confirm) return;
  confirm.required = fresh.value !== '';
  confirm.setCustomValidity(confirm.value !== fresh.value && (confirm.value !== '' || fresh.value !== '') ? 'La confirmación no coincide con la contraseña nueva.' : '');
});

// Mostrar u ocultar la contraseña (inicio de sesión y edición de cuentas).
document.querySelectorAll('[data-toggle-password]').forEach(button => {
  const input = document.getElementById(button.getAttribute('aria-controls'));
  button.hidden = false;
  button.addEventListener('click', () => {
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(show));
    button.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
    input.focus();
  });
});
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

// Modales de detalle que cargan su contenido del servidor: detalle del envío e historial del caso.
// El historial puede abrirse encima del detalle; cada uno conserva su propio enlace de origen para el foco.
const remoteModal = (selector, trigger, loading, failed, onLoad) => {
  const dialog = document.querySelector(selector);
  if (!dialog || typeof dialog.showModal !== 'function') return;
  const content = dialog.querySelector('[data-remote-content]');
  let pending = null;
  document.addEventListener('click', async event => {
    const link = event.target.closest(trigger);
    if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    pending?.abort();
    const controller = new AbortController();
    pending = controller;
    content.textContent = loading;
    content.setAttribute('aria-busy', 'true');
    onLoad(dialog, null);
    window.CitaYa.open(dialog, link);
    const url = new URL(link.href);
    url.searchParams.set('fragment', '1');
    try {
      const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin', cache: 'no-store'});
      if (pending !== controller) return;
      if (response.status === 401 || response.redirected) {
        content.textContent = 'Tu sesión venció. ';
        const login = document.createElement('a');
        login.href = '?page=admin-login';
        login.textContent = 'Volver a iniciar sesión';
        content.append(login);
        window.CitaYa.toast({tone: 'error', title: 'Sesión vencida', text: 'Vuelve a iniciar sesión para continuar.'});
        return;
      }
      if (!response.ok && response.status !== 404) throw new Error('unavailable');
      content.innerHTML = await response.text();
      onLoad(dialog, content);
    } catch (error) {
      if (error.name === 'AbortError' || pending !== controller) return;
      content.textContent = failed;
      window.CitaYa.toast({tone: 'error', title: 'No se pudo cargar', text: failed});
    } finally {
      if (pending === controller) content.removeAttribute('aria-busy');
    }
  });
  dialog.addEventListener('close', () => {
    pending?.abort();
    pending = null;
    content.replaceChildren();
    onLoad(dialog, null);
  });
};
remoteModal('#delivery-modal', '[data-delivery-modal]', 'Cargando la solicitud…', 'No se pudo cargar la solicitud. Inténtalo nuevamente.', (dialog, content) => {
  const title = dialog.querySelector('h2');
  title.dataset.default ??= title.textContent;
  title.textContent = content?.querySelector('[data-case-title]')?.textContent || title.dataset.default;
});
remoteModal('#history-modal', '[data-history-modal]', 'Cargando el historial…', 'No se pudo cargar el historial. Inténtalo nuevamente.', (dialog, content) => {
  dialog.querySelector('[data-history-reference]').textContent = content?.querySelector('[data-case-title]')?.textContent || '';
});

// Botones de copiar (documento, número) en el detalle de la solicitud, también en contenido cargado después.
document.addEventListener('click', event => {
  const button = event.target.closest('[data-copy]');
  if (!button || !navigator.clipboard) return;
  navigator.clipboard.writeText(button.dataset.copy).then(() => {
    button.classList.add('copied');
    button.title = 'Copiado';
    setTimeout(() => { button.classList.remove('copied'); button.title = 'Copiar'; }, 1500);
    window.CitaYa.toast({tone: 'success', title: 'Copiado al portapapeles', timeout: 2500});
  }).catch(() => window.CitaYa.toast({tone: 'error', title: 'No se pudo copiar', text: 'Selecciona el texto y cópialo manualmente.'}));
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
      template.closest('details').open = false;
      const apply = () => { comment.value = template.dataset.templateText; selectDecision(form, template.dataset.decision); comment.focus(); };
      if (current && !templateTexts(form).includes(current)) {
        window.CitaYa.confirm({title: '¿Reemplazar tu comentario?', text: 'El texto que escribiste se cambiará por la plantilla seleccionada.', label: 'Usar la plantilla'}).then(ok => { if (ok) apply(); });
      } else apply();
      return;
    }
    const close = event.target.closest('[data-close-case]');
    if (close) {
      const dialog = close.closest('dialog');
      if (dialog) window.CitaYa.close(dialog); else location.href = '?page=deliveries';
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
      window.CitaYa.toast({tone: 'error', title: 'Falta información para responder', text: problem});
      if (!decision) form.querySelector('[data-decision-menu]').open = true;
      return;
    }
    form.querySelector('button[type="submit"]').disabled = true;
  });
})();

// Análisis: un solo aviso flotante para las donas (por segmento) y la gráfica de crecimiento (cruz que busca el mes).
// Los textos se insertan con textContent; los mismos datos están en las tablas de «Ver los datos».
(() => {
  const tip = document.querySelector('.chart-tooltip');
  if (!tip) return;
  const place = (x, y) => {
    tip.hidden = false;
    const box = tip.getBoundingClientRect();
    tip.style.left = Math.min(window.innerWidth - box.width - 8, x + 14) + 'px';
    tip.style.top = Math.max(8, y - box.height - 12) + 'px';
  };
  const fill = (title, rows) => {
    tip.replaceChildren();
    const strong = document.createElement('strong'); strong.textContent = title; tip.append(strong);
    rows.forEach(([text, value, key]) => {
      const row = document.createElement('span');
      if (key) { row.className = 'tip-row'; row.style.setProperty('--key', key); }
      const b = document.createElement('b'); b.textContent = value;
      row.append(b, document.createTextNode(' ' + text));
      tip.append(row);
    });
  };
  const hide = () => { tip.hidden = true; };
  document.querySelectorAll('.donut-segment').forEach(segment => {
    const show = (x, y) => { fill(segment.dataset.tip, [['', segment.dataset.tipValue]]); place(x, y); };
    segment.addEventListener('pointermove', event => show(event.clientX, event.clientY));
    segment.addEventListener('focus', () => { const r = segment.getBoundingClientRect(); show(r.left + r.width / 2, r.top + r.height / 2); });
    segment.addEventListener('pointerleave', hide);
    segment.addEventListener('blur', hide);
  });
  document.querySelectorAll('[data-growth]').forEach(frame => {
    const data = JSON.parse(frame.dataset.growth);
    const svg = frame.querySelector('svg'), hit = frame.querySelector('.hit-area'), cross = frame.querySelector('.crosshair');
    let index = data.points.length - 1;
    const show = (i, x, y) => {
      index = i;
      const p = data.points[i];
      cross.setAttribute('x1', data.x[i]); cross.setAttribute('x2', data.x[i]); cross.classList.add('on');
      fill(p.label, [['solicitudes', p.requests.toLocaleString('es-CO'), 'var(--series-1)'], ['enviadas', p.sent.toLocaleString('es-CO'), 'var(--series-2)']]);
      place(x, y);
    };
    const nearest = clientX => {
      const r = svg.getBoundingClientRect(), vx = (clientX - r.left) / r.width * data.width;
      return data.x.reduce((best, x, i) => Math.abs(x - vx) < Math.abs(data.x[best] - vx) ? i : best, 0);
    };
    hit.addEventListener('pointermove', event => show(nearest(event.clientX), event.clientX, event.clientY));
    const keyShow = i => { const r = svg.getBoundingClientRect(); show(i, r.left + data.x[i] / data.width * r.width, r.top + 20); };
    hit.addEventListener('focus', () => keyShow(index));
    hit.addEventListener('keydown', event => {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
      event.preventDefault();
      keyShow(Math.max(0, Math.min(data.points.length - 1, index + (event.key === 'ArrowRight' ? 1 : -1))));
    });
    const leave = () => { cross.classList.remove('on'); hide(); };
    hit.addEventListener('pointerleave', leave);
    hit.addEventListener('blur', leave);
  });
})();

// Tablas de solicitudes: un clic en cualquier parte de la fila abre el detalle (igual que el botón del ojo).
// Los botones, enlaces y formularios de la fila conservan su propia acción; seleccionar texto no abre nada.
document.addEventListener('click', event => {
  const row = event.target.closest('tr[data-row-open]');
  if (!row || event.target.closest('a, button, form, input, label, select, textarea')) return;
  if (String(window.getSelection?.() || '').trim()) return;
  row.querySelector('[data-row-primary]')?.click();
});

// Chat de un reporte de error: baja al último mensaje, se actualiza cada 8 s y Ctrl+Enter envía.
(() => {
  const thread = document.querySelector('[data-chat-thread]');
  if (!thread) return;
  const bottom = () => { thread.scrollTop = thread.scrollHeight; };
  bottom();
  // Las fotos cambian el alto al cargar: se vuelve a bajar (load no burbujea, se escucha en captura).
  thread.addEventListener('load', bottom, true);
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
  // PDF adjunto: se muestra su nombre y permite enviar sin texto. Se valida tipo y tamaño antes de subirlo.
  const file = document.querySelector('[data-chat-file]');
  const picked = document.querySelector('[data-chat-file-name]');
  const showFile = () => {
    const chosen = file?.files[0];
    if (chosen && (!/\.pdf$/i.test(chosen.name) || chosen.size > 10 * 1024 * 1024)) {
      file.value = '';
      window.CitaYa?.toast?.({tone: 'error', title: 'No se puede adjuntar', text: 'Solo se pueden enviar archivos PDF de hasta 10 MB.'});
      return showFile();
    }
    if (!picked) return;
    picked.hidden = !chosen;
    picked.querySelector('span').textContent = chosen ? 'Adjunto: ' + chosen.name : '';
  };
  file?.addEventListener('change', showFile);
  picked?.querySelector('[data-chat-file-clear]').addEventListener('click', () => { file.value = ''; showFile(); });
  input?.form.addEventListener('submit', event => {
    if (!input.value.trim() && !file?.files.length) { event.preventDefault(); input.focus(); return; }
    input.form.querySelector('[data-chat-send]').disabled = true;
  });
})();

// Avisos de reportes de error en el panel (sin WhatsApp al empleado): cada 15 s se actualiza el contador de la pestaña
// y, si llega un reporte nuevo o un mensaje del paciente, aparece un aviso con el enlace al chat.
(() => {
  const tab = document.querySelector('.rail-nav a[href="?page=reports"]');
  if (!tab) return;
  const openReport = new URLSearchParams(location.search).get('page') === 'report' ? new URLSearchParams(location.search).get('id') : null;
  let known = null;
  const toast = item => window.CitaYa.toast({
    tone: 'novelty',
    title: item.isNew ? 'Nuevo reporte de error' : 'Nuevo mensaje del paciente',
    text: item.reference + ' · ' + item.label,
    action: {href: '?page=report&id=' + item.id, label: 'Abrir chat'},
    timeout: 15000
  });
  const setCount = count => {
    let badge = tab.querySelector('.tab-count');
    if (!count) { badge?.remove(); return; }
    if (!badge) { badge = document.createElement('span'); badge.className = 'tab-count'; tab.append(' ', badge); }
    badge.textContent = count;
    badge.setAttribute('aria-label', count + ' por atender');
  };
  const check = async () => {
    if (document.hidden) return;
    try {
      const response = await fetch('?page=notifications', {credentials: 'same-origin', cache: 'no-store'});
      if (!response.ok || response.redirected) return;
      const {items} = await response.json();
      setCount(items.length);
      const current = new Map(items.map(item => [item.id, item]));
      if (known) {
        for (const item of items) {
          if (String(item.id) === openReport) continue;
          const before = known.get(item.id);
          if (!before) toast({...item, isNew: true});
          else if (item.unread > before.unread) toast({...item, isNew: false});
        }
      }
      known = current;
    } catch {}
  };
  check();
  setInterval(check, 15000);
})();

// En pantallas angostas el rail es una barra deslizable: deja visible la sección actual.
(() => {
  const nav = document.querySelector('.rail-nav');
  const current = nav?.querySelector('a.selected');
  if (!current || nav.scrollWidth <= nav.clientWidth) return;
  nav.scrollLeft = current.offsetLeft - (nav.clientWidth - current.offsetWidth) / 2;
})();
