// Bot de WhatsApp de CitaYa para n8n (fase 4, SCRUM-41). Se ejecuta en el nodo Code del flujo «CitaYa · Bot WhatsApp».
// Recibe el mensaje que reenvía el servicio QR, valida su firma y conversa usando la interfaz privada de CitaYa
// (/n8n.php). No guarda datos del paciente en n8n: el estado vive en CitaYa (enlaces, códigos, solicitudes).
// Variables de entorno de n8n: CITAYA_URL, CITAYA_N8N_SECRET (firma hacia CitaYa) y CITAYA_WA_SECRET (firma del QR).
// Devuelve { status, result }: status es la respuesta HTTP para el QR (no 2xx = el QR lo reintentará).
async function citayaWhatsAppBot({ raw, signature, env, http, crypto }) {
  const KEYWORDS = ['historia', 'clinica', 'hc', 'epicrisis', 'copia', 'resultado', '1'];
  const hmac = (body, secret) => 'sha256=' + crypto.createHmac('sha256', secret).update(body).digest('hex');
  const same = (a, b) => typeof a === 'string' && a.length === b.length && crypto.timingSafeEqual(Buffer.from(a), Buffer.from(b));

  if (!env.CITAYA_WA_SECRET || !env.CITAYA_N8N_SECRET || !env.CITAYA_URL) return { status: 500, result: 'not_configured' };
  if (!same(signature, hmac(raw, env.CITAYA_WA_SECRET))) return { status: 401, result: 'invalid_signature' };
  let payload;
  try { payload = JSON.parse(raw); } catch { return { status: 400, result: 'invalid_json' }; }
  const message = payload && payload.entry && payload.entry[0] && payload.entry[0].changes && payload.entry[0].changes[0]
    && payload.entry[0].changes[0].value && payload.entry[0].changes[0].value.messages && payload.entry[0].changes[0].value.messages[0];
  if (!message) return { status: 200, result: 'ignored' };
  const phone = String(message.from || '').replace(/\D/g, '');
  const id = String(message.id || '');
  const text = String((message.text && message.text.body) || '');
  if (!/^\d{8,20}$/.test(phone) || !id) return { status: 200, result: 'ignored' };

  // Llamada firmada a CitaYa. Los rechazos (4xx) vuelven como { ok:false, error } para decidir la respuesta.
  async function api(action, data) {
    const body = JSON.stringify(Object.assign({ action, ts: Math.floor(Date.now() / 1000) }, data));
    try {
      const response = await http({ method: 'POST', url: env.CITAYA_URL.replace(/\/$/, '') + '/n8n.php', body,
        headers: { 'Content-Type': 'application/json', 'X-CitaYa-Signature': hmac(body, env.CITAYA_N8N_SECRET) } });
      return typeof response === 'string' ? JSON.parse(response) : response;
    } catch (error) {
      const detail = error && (error.response && error.response.body || error.description || error.message);
      try { return typeof detail === 'string' ? JSON.parse(detail) : Object.assign({ ok: false, error: 'request_failed' }, detail); }
      catch { return { ok: false, error: 'unreachable' }; }
    }
  }
  const send = (body, suffix) => api('message.send', { phone, text: body, key: id + ':' + suffix });

  // 1. Registro antes de procesar: un reintento del QR (mismo id) no se responde dos veces.
  const registered = await api('inbound.register', { phone, message_id: id });
  if (!registered.ok) return { status: registered.error === 'unreachable' || registered.error === 'request_failed' ? 503 : 200, result: 'register_failed' };
  if (!registered.new) return { status: 200, result: 'duplicate' };
  if (registered.allowed === false) return { status: 200, result: 'rate_limited' };

  // 2. Acción: CONFIRMAR código, HISTORIA o menú.
  const words = text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().split(/[^a-z0-9]+/).filter(Boolean);
  const confirm = text.match(/\bconfirmar\s+([a-z0-9]{6})\b/i);
  let action, sent;
  if (confirm) {
    action = 'confirm';
    const result = await api('code.confirm', { phone, code: confirm[1] });
    if (!result.ok || !result.valid) {
      sent = await send('El código no es válido o ya venció. Revisa el código de la página de tu solicitud, o escribe *HISTORIA* para iniciar una nueva.', 'confirm-fail');
    } else {
      sent = await send('Listo, confirmamos este WhatsApp para tu solicitud con radicado *' + result.reference + '*.', 'confirm-ok');
      if (result.next === 'select') await send('Encontramos varias atenciones con tus datos. Elige la que necesitas en la página de tu solicitud y te la enviaremos por aquí.', 'confirm-select');
    }
  } else if (words.includes('estado')) {
    // Consulta de estado: CitaYa solo devuelve las solicitudes vinculadas a este chat.
    action = 'status';
    const list = await api('requests.list', { phone });
    const requests = list.ok ? list.requests : [];
    sent = await send(requests.length
      ? 'Estas son tus solicitudes:\n\n' + requests.map(r => '*' + r.reference + '*: ' + r.label).join('\n') + '\n\nSi necesitas otra historia, escribe *HISTORIA*.'
      : 'No encontramos solicitudes vinculadas a este WhatsApp. Escribe *HISTORIA* para solicitar tu historia clínica.', 'status');
  } else if (words.some(word => KEYWORDS.includes(word))) {
    action = 'history';
    const link = await api('link.create', { phone });
    if (!link.ok) sent = await send('No pudimos generar tu enlace en este momento. Intenta de nuevo en unos minutos.', 'link-fail');
    else {
      const rendered = await api('template.render', { code: 'form_link', values: { enlace: link.url, vigencia: link.hours } });
      sent = await send(rendered.ok ? rendered.text : 'Completa tus datos en este enlace: ' + link.url, 'link');
    }
  } else {
    action = 'menu';
    if (registered.released > 0) sent = await send('Gracias por escribirnos. Te estamos enviando ahora tu historia clínica.', 'release');
    else {
      const rendered = await api('template.render', { code: 'menu' });
      sent = await send(rendered.ok ? rendered.text : 'Hola, soy el asistente de *CitaYa*. Para solicitar tu historia clínica escribe *HISTORIA*.', 'menu');
    }
  }
  return { status: 200, result: action + (sent && sent.ok && sent.status === 'sent' ? ':sent' : ':send_failed') };
}

if (typeof module !== 'undefined') module.exports = { citayaWhatsAppBot };
