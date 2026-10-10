// Seguimiento administrativo (fase 6, SCRUM-43). Flujo «CitaYa · Seguimiento», cada hora.
// Avisa al responsable (CITAYA_ADMIN_WHATSAPP) por WhatsApp: procesador detenido, QR desconectado, solicitudes cerca
// del plazo o vencidas, y a las 7:00 (hora de Colombia) el resumen del día anterior. Solo cantidades y radicados.
// Cada aviso lleva una clave: CitaYa no repite un aviso ya enviado aunque el flujo se ejecute de nuevo.
async function citayaFollowUp({ env, http, crypto, now = new Date() }) {
  if (!env.CITAYA_URL || !env.CITAYA_N8N_SECRET) return { result: 'not_configured' };
  const admin = String(env.CITAYA_ADMIN_WHATSAPP || '').replace(/\D/g, '');
  if (!admin) return { result: 'sin_responsable' };
  const hmac = (body, secret) => 'sha256=' + crypto.createHmac('sha256', secret).update(body).digest('hex');
  async function api(action, data) {
    const body = JSON.stringify(Object.assign({ action, ts: Math.floor(Date.now() / 1000) }, data || {}));
    try {
      const response = await http({ method: 'POST', url: env.CITAYA_URL.replace(/\/$/, '') + '/n8n.php', body,
        headers: { 'Content-Type': 'application/json', 'X-CitaYa-Signature': hmac(body, env.CITAYA_N8N_SECRET) } });
      return typeof response === 'string' ? JSON.parse(response) : response;
    } catch { return { ok: false, error: 'unreachable' }; }
  }
  // Fecha y hora de Colombia (UTC-5, sin horario de verano).
  const local = new Date(now.getTime() - 5 * 3600 * 1000);
  const day = local.toISOString().slice(0, 10);
  const hour = local.getUTCHours();
  const notices = [];
  const notify = (text, key) => notices.push({ text, key });

  const health = await api('system.health');
  if (!health.ok) notify('⚠️ n8n no pudo comunicarse con CitaYa. Revisa que el sitio esté en línea.', 'salud:citaya:' + day + ':' + hour);
  else {
    if (health.worker_seconds === null || health.worker_seconds > 300) notify('⚠️ El procesador de CitaYa no está activo' + (health.worker_seconds ? ' desde hace ' + Math.round(health.worker_seconds / 60) + ' min' : '') + '. Los correos y la cola no avanzan.', 'salud:worker:' + day + ':' + hour);
    if (health.qr && !health.qr.connected) notify('⚠️ El WhatsApp por QR está desconectado. Vuelve a vincularlo desde los registros del servicio.', 'salud:qr:' + day + ':' + hour);
    if (health.failed_24h && health.failed_24h.eventos > 0) notify('⚠️ Hay ' + health.failed_24h.eventos + ' evento(s) de CitaYa que no llegaron a n8n en las últimas 24 h.', 'salud:eventos:' + day);
  }
  const overdue = await api('requests.overdue');
  if (overdue.ok) for (const r of overdue.requests) {
    notify(r.stage === 'vencida'
      ? '⏰ Solicitud *' + r.reference + '* VENCIDA: lleva ' + r.age_hours + ' h sin respuesta (plazo ' + overdue.hours + ' h).'
      : '⏰ Solicitud *' + r.reference + '* por vencer: lleva ' + r.age_hours + ' h (plazo ' + overdue.hours + ' h).', 'plazo:' + r.reference + ':' + r.stage);
  }
  if (hour === 7) {
    const s = await api('stats.summary');
    if (s.ok) notify('📊 Resumen de CitaYa del ' + s.date + ':\n• Solicitudes: ' + s.created + ' (' + s.from_whatsapp + ' por WhatsApp)\n• Historias entregadas: ' + s.delivered + '\n• Pendientes ahora: ' + s.pending_now + (s.overdue_now ? ' (' + s.overdue_now + ' vencidas)' : ''), 'resumen:' + s.date);
  }
  const results = [];
  for (const n of notices) results.push((await api('message.send', { phone: admin, text: n.text, key: n.key })).status || 'error');
  return { result: notices.length ? 'avisos: ' + results.join(', ') : 'sin novedades' };
}

if (typeof module !== 'undefined') module.exports = { citayaFollowUp };
