// Flujo de errores de n8n. «CitaYa · Errores» se asigna como Error workflow de los flujos de CitaYa.
// Cuando una ejecución falla, avisa al responsable (CITAYA_ADMIN_WHATSAPP) por WhatsApp con el flujo, el nodo y un
// resumen corto del error. La clave por ejecución evita avisos repetidos. No incluye datos de los mensajes procesados.
async function citayaErrorAlert({ error, env, http, crypto }) {
  if (!env.CITAYA_URL || !env.CITAYA_N8N_SECRET) return { result: 'not_configured' };
  const admin = String(env.CITAYA_ADMIN_WHATSAPP || '').replace(/\D/g, '');
  if (!admin) return { result: 'sin_responsable' };
  const execution = (error && error.execution) || {};
  const trigger = (error && error.trigger) || {};
  const workflow = (error && error.workflow && error.workflow.name) || 'Flujo sin nombre';
  const node = execution.lastNodeExecuted || (trigger.error && trigger.error.node && trigger.error.node.name) || '';
  const detail = String((execution.error && execution.error.message) || (trigger.error && trigger.error.message) || 'Error sin descripción')
    .replace(/\d{7,}/g, '…').replace(/\s+/g, ' ').trim().slice(0, 160);
  const text = '🚨 Falló el flujo *' + workflow + '* en n8n' + (node ? ' (nodo «' + node + '»)' : '') + '.\n' + detail
    + (execution.url ? '\n' + execution.url : '');
  const key = 'n8n-error:' + (execution.id || (trigger.error && trigger.error.timestamp) || Date.now());
  const body = JSON.stringify({ action: 'message.send', ts: Math.floor(Date.now() / 1000), phone: admin, text, key });
  const signature = 'sha256=' + crypto.createHmac('sha256', env.CITAYA_N8N_SECRET).update(body).digest('hex');
  try {
    const response = await http({ method: 'POST', url: env.CITAYA_URL.replace(/\/$/, '') + '/n8n.php', body,
      headers: { 'Content-Type': 'application/json', 'X-CitaYa-Signature': signature } });
    const parsed = typeof response === 'string' ? JSON.parse(response) : response;
    return { result: 'aviso: ' + (parsed.status || parsed.error || 'error') };
  } catch { return { result: 'aviso: unreachable' }; }
}

if (typeof module !== 'undefined') module.exports = { citayaErrorAlert };
