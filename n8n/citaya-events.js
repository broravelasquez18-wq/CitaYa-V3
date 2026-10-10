// Eventos de CitaYa en n8n (fases 5 y 6, SCRUM-42/43). Se ejecuta en el nodo Code del flujo «CitaYa · Eventos».
// CitaYa avisa cada mensaje nuevo de su cola (historia.disponible, solicitud.pendiente, solicitud.no_registra,
// whatsapp.mensaje) y n8n pide su envío por el QR (delivery.send): CitaYa arma y autoriza el contenido, así los PDF
// nunca pasan por n8n. Con WHATSAPP_DISPATCH=n8n en CitaYa, n8n es el único responsable de la cola de WhatsApp.
// Variables de n8n: CITAYA_URL, CITAYA_N8N_SECRET y CITAYA_ADMIN_WHATSAPP (avisos al responsable, fase 6).
const CITAYA_DELIVERY_EVENTS = ['historia.disponible', 'solicitud.pendiente', 'solicitud.no_registra', 'whatsapp.mensaje'];

function citayaClient({ env, http, crypto }) {
  const hmac = (body, secret) => 'sha256=' + crypto.createHmac('sha256', secret).update(body).digest('hex');
  return async function api(action, data) {
    const body = JSON.stringify(Object.assign({ action, ts: Math.floor(Date.now() / 1000) }, data || {}));
    try {
      const response = await http({ method: 'POST', url: env.CITAYA_URL.replace(/\/$/, '') + '/n8n.php', body,
        headers: { 'Content-Type': 'application/json', 'X-CitaYa-Signature': hmac(body, env.CITAYA_N8N_SECRET) } });
      return typeof response === 'string' ? JSON.parse(response) : response;
    } catch (error) {
      const detail = error && error.response && error.response.body;
      try { return typeof detail === 'string' ? JSON.parse(detail) : { ok: false, error: 'unreachable' }; } catch { return { ok: false, error: 'unreachable' }; }
    }
  };
}

// Avisos administrativos al responsable (fase 6). Solo radicados y estados: nunca historias ni datos del paciente.
function citayaAdminNotice(event, data) {
  const ref = data.reference ? ' (radicado *' + data.reference + '*)' : '';
  if (event === 'solicitud.pendiente') return '📋 Nueva solicitud pendiente' + ref + '. El paciente espera respuesta en ' + (data.plazo_horas || 24) + ' h. Revísala en CitaYa → Solicitudes → Pendientes.';
  // Los fallos de textos sueltos (respuestas del bot y estos mismos avisos) no se notifican: evita bucles de avisos.
  if (event === 'envio.fallido' && data.kind === 'text') return null;
  if (event === 'envio.fallido') return '⚠️ Falló un envío por ' + (data.canal === 'correo' ? 'correo' : 'WhatsApp') + ref + (data.error ? ' · ' + data.error : '') + '. Revísalo en CitaYa.';
  return null;
}

async function citayaEvents({ raw, signature, env, http, crypto }) {
  if (!env.CITAYA_URL || !env.CITAYA_N8N_SECRET) return { status: 500, result: 'not_configured' };
  const expected = 'sha256=' + crypto.createHmac('sha256', env.CITAYA_N8N_SECRET).update(raw).digest('hex');
  if (typeof signature !== 'string' || signature.length !== expected.length || !crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected))) return { status: 401, result: 'invalid_signature' };
  let payload;
  try { payload = JSON.parse(raw); } catch { return { status: 400, result: 'invalid_json' }; }
  if (Math.abs(Date.now() / 1000 - Number(payload.ts || 0)) > 300) return { status: 400, result: 'expired' };
  const event = String(payload.event || '');
  const data = payload.data || {};
  const api = citayaClient({ env, http, crypto });
  const done = [];

  // Fase 5: entrega coordinada por n8n. CitaYa no repite lo ya enviado si el evento llega dos veces.
  if (CITAYA_DELIVERY_EVENTS.includes(event) && Number(data.job_id) > 0) {
    const delivery = await api('delivery.send', { job_id: Number(data.job_id) });
    if (!delivery.ok && delivery.error === 'unreachable') return { status: 503, result: 'citaya_unreachable' };
    done.push('delivery:' + (delivery.status || delivery.error));
  }
  // Fase 6: aviso al responsable.
  const notice = citayaAdminNotice(event, data);
  const admin = String(env.CITAYA_ADMIN_WHATSAPP || '').replace(/\D/g, '');
  if (notice && admin) {
    const sent = await api('message.send', { phone: admin, text: notice, key: 'evento:' + payload.id });
    done.push('admin:' + (sent.status || sent.error));
  }
  return { status: 200, result: event + (done.length ? ' → ' + done.join(', ') : ' (sin acción)') };
}

// Recuperación periódica (flujo «CitaYa · Recuperación»): envía lo que quedó pendiente, por ejemplo si n8n o el QR
// estuvieron caídos cuando llegó el evento. CitaYa solo lista lo que ya está disponible según sus reintentos.
async function citayaRecovery({ env, http, crypto }) {
  if (!env.CITAYA_URL || !env.CITAYA_N8N_SECRET) return { result: 'not_configured' };
  const api = citayaClient({ env, http, crypto });
  const pending = await api('delivery.pending');
  if (!pending.ok) return { result: 'citaya_unreachable' };
  const results = [];
  for (const job of pending.jobs.slice(0, 20)) results.push((await api('delivery.send', { job_id: job.job_id })).status || 'error');
  return { result: results.length ? 'enviados: ' + results.join(', ') : 'sin pendientes' };
}

if (typeof module !== 'undefined') module.exports = { citayaEvents, citayaRecovery, citayaAdminNotice };
