// node --test n8n/citaya-events.test.js — eventos y recuperación de n8n contra una CitaYa simulada.
const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { citayaEvents, citayaRecovery } = require('./citaya-events.js');

const env = { CITAYA_URL: 'https://citaya.example.test', CITAYA_N8N_SECRET: 's3cr3t', CITAYA_ADMIN_WHATSAPP: '+57 300 000 0999' };
const sign = body => 'sha256=' + crypto.createHmac('sha256', env.CITAYA_N8N_SECRET).update(body).digest('hex');
function citaya(handlers = {}) {
  const calls = [];
  const http = async ({ body, headers }) => {
    assert.equal(headers['X-CitaYa-Signature'], sign(body));
    const request = JSON.parse(body); calls.push(request);
    if (handlers[request.action]) return handlers[request.action](request);
    if (request.action === 'delivery.send') return { ok: true, status: 'sent' };
    if (request.action === 'message.send') return { ok: true, status: 'sent' };
    if (request.action === 'delivery.pending') return { ok: true, jobs: [{ job_id: 7 }, { job_id: 8 }] };
    throw new Error('acción inesperada');
  };
  return { http, calls };
}
const event = (name, data, ts = Math.floor(Date.now() / 1000)) => JSON.stringify({ id: 41, event: name, data, created_at: '2026-10-10 10:00:00', ts });
const run = (fake, raw, signature = sign(raw)) => citayaEvents({ raw, signature, env, http: fake.http, crypto });

test('rechaza eventos sin firma válida o vencidos', async () => {
  const fake = citaya();
  assert.equal((await run(fake, event('historia.disponible', { job_id: 3 }), 'sha256=00')).status, 401);
  assert.equal((await run(fake, event('historia.disponible', { job_id: 3 }, 1000))).status, 400);
  assert.equal(fake.calls.length, 0);
});

test('historia disponible: n8n pide a CitaYa el envío autorizado por el QR', async () => {
  const fake = citaya();
  const result = await run(fake, event('historia.disponible', { job_id: 3, reference: 'HC-1' }));
  assert.equal(result.status, 200);
  assert.deepEqual(fake.calls.map(c => [c.action, c.job_id]), [['delivery.send', 3]]);
});

test('solicitud pendiente: entrega el radicado al paciente y avisa al responsable sin datos personales', async () => {
  const fake = citaya();
  await run(fake, event('solicitud.pendiente', { job_id: 4, reference: 'HC-2', plazo_horas: 24 }));
  const notice = fake.calls.find(c => c.action === 'message.send');
  assert.ok(fake.calls.some(c => c.action === 'delivery.send' && c.job_id === 4));
  assert.equal(notice.phone, '573000000999');
  assert.match(notice.text, /HC-2/);
  assert.equal(notice.key, 'evento:41', 'clave idempotente por evento');
});

test('fallo de envío: solo avisa al responsable', async () => {
  const fake = citaya();
  await run(fake, event('envio.fallido', { canal: 'correo', reference: 'HC-3', error: 'transport_error_0' }));
  assert.deepEqual(fake.calls.map(c => c.action), ['message.send']);
  assert.match(fake.calls[0].text, /correo.*HC-3/);
});

test('si CitaYa no responde, pide reintentar el evento (503)', async () => {
  const fake = citaya({ 'delivery.send': () => { const e = new Error('x'); e.response = { body: 'no json' }; throw e; } });
  assert.equal((await run(fake, event('historia.disponible', { job_id: 3 }))).status, 503);
});

test('recuperación: envía los mensajes pendientes', async () => {
  const fake = citaya();
  const result = await citayaRecovery({ env, http: fake.http, crypto });
  assert.equal(result.result, 'enviados: sent, sent');
  assert.deepEqual(fake.calls.filter(c => c.action === 'delivery.send').map(c => c.job_id), [7, 8]);
});

test('no avisa fallos de textos sueltos (evita un bucle de avisos)', async () => {
  const fake = citaya();
  await run(fake, event('envio.fallido', { canal: 'whatsapp', kind: 'text', error: 'qr_send_failed' }));
  assert.equal(fake.calls.length, 0);
});
