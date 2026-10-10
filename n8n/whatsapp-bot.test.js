// node --test n8n/whatsapp-bot.test.js — lógica del bot de n8n contra una CitaYa simulada (sin red).
const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { citayaWhatsAppBot } = require('./whatsapp-bot.js');

const env = { CITAYA_URL: 'https://citaya.example.test', CITAYA_N8N_SECRET: 'n8n-secret', CITAYA_WA_SECRET: 'wa-secret' };
const sign = (body, secret) => 'sha256=' + crypto.createHmac('sha256', secret).update(body).digest('hex');

// CitaYa simulada: verifica la firma de cada llamada y responde según la acción.
function citaya(overrides = {}) {
  const calls = [];
  const seen = new Set();
  const http = async ({ url, body, headers }) => {
    assert.equal(url, 'https://citaya.example.test/n8n.php');
    assert.equal(headers['X-CitaYa-Signature'], sign(body, env.CITAYA_N8N_SECRET), 'firma hacia CitaYa');
    const request = JSON.parse(body);
    assert.ok(Math.abs(request.ts - Date.now() / 1000) < 5, 'marca de tiempo');
    calls.push(request);
    if (overrides[request.action]) return overrides[request.action](request);
    switch (request.action) {
      case 'inbound.register': { const isNew = !seen.has(request.message_id); seen.add(request.message_id); return { ok: true, new: isNew, allowed: true, released: 0 }; }
      case 'template.render': return { ok: true, text: request.code === 'menu' ? 'MENÚ: escribe HISTORIA' : 'Tu enlace: ' + request.values.enlace };
      case 'link.create': return { ok: true, url: 'https://citaya.example.test/?page=home&wa=abc', hours: 6 };
      case 'code.confirm': return request.code.toUpperCase() === 'K7MP2Q' ? { ok: true, valid: true, reference: 'HC-1', next: 'history' } : { ok: true, valid: false };
      case 'message.send': return { ok: true, status: 'sent' };
      default: throw new Error('acción inesperada ' + request.action);
    }
  };
  return { http, calls, sent: () => calls.filter(c => c.action === 'message.send') };
}
function inbound(id, from, text) { return JSON.stringify({ entry: [{ changes: [{ value: { messages: [{ id, from, type: 'text', text: { body: text } }] } }] }] }); }
async function run(fake, raw, signature = sign(raw, env.CITAYA_WA_SECRET), customEnv = env) {
  return citayaWhatsAppBot({ raw, signature, env: customEnv, http: fake.http, crypto });
}

test('rechaza mensajes sin la firma del QR y no llama a CitaYa', async () => {
  const fake = citaya();
  assert.equal((await run(fake, inbound('m1', '573001112233', 'hola'), 'sha256=00')).status, 401);
  assert.equal((await run(fake, inbound('m1', '573001112233', 'hola'), sign('otro', env.CITAYA_WA_SECRET))).status, 401);
  assert.equal(fake.calls.length, 0);
});

test('sin configuración no procesa', async () => {
  const fake = citaya();
  assert.equal((await run(fake, inbound('m1', '573001112233', 'hola'), undefined, { CITAYA_URL: 'x' })).status, 500);
});

test('saludo: un solo menú, con el texto editable de CitaYa', async () => {
  const fake = citaya();
  const result = await run(fake, inbound('m1', '573001112233', 'Hola buenas'));
  assert.deepEqual(result, { status: 200, result: 'menu:sent' });
  assert.deepEqual(fake.sent().map(s => [s.phone, s.text, s.key]), [['573001112233', 'MENÚ: escribe HISTORIA', 'm1:menu']]);
});

test('HISTORIA (con tildes o mayúsculas) envía el enlace personal', async () => {
  const fake = citaya();
  assert.equal((await run(fake, inbound('m2', '573001112233', 'Necesito mi HISTORIA clínica'))).result, 'history:sent');
  assert.deepEqual(fake.calls.map(c => c.action), ['inbound.register', 'link.create', 'template.render', 'message.send']);
  assert.match(fake.sent()[0].text, /wa=abc/);
});

test('un reintento del mismo mensaje no se responde dos veces', async () => {
  const fake = citaya();
  await run(fake, inbound('m3', '573001112233', 'historia'));
  assert.equal((await run(fake, inbound('m3', '573001112233', 'historia'))).result, 'duplicate');
  assert.equal(fake.sent().length, 1);
});

test('CONFIRMAR con código válido e inválido', async () => {
  const fake = citaya();
  assert.equal((await run(fake, inbound('m4', '573001112233', 'confirmar k7mp2q'))).result, 'confirm:sent');
  assert.match(fake.sent()[0].text, /radicado \*HC-1\*/);
  await run(fake, inbound('m5', '573001112233', 'CONFIRMAR ZZZZZZ'));
  assert.match(fake.sent()[1].text, /no es válido/);
});

test('límite de abuso de CitaYa: no responde', async () => {
  const fake = citaya({ 'inbound.register': () => ({ ok: true, new: true, allowed: false }) });
  assert.equal((await run(fake, inbound('m6', '573001112233', 'hola'))).result, 'rate_limited');
  assert.equal(fake.sent().length, 0);
});

test('si CitaYa no responde al registrar, pide al QR reintentar (503)', async () => {
  const fake = citaya({ 'inbound.register': () => { throw new Error('ECONNREFUSED'); } });
  assert.equal((await run(fake, inbound('m7', '573001112233', 'hola'))).status, 503);
});

test('si había mensajes esperando la ventana, avisa en lugar del menú', async () => {
  const fake = citaya({ 'inbound.register': () => ({ ok: true, new: true, allowed: true, released: 1 }) });
  await run(fake, inbound('m8', '573001112233', 'hola'));
  assert.match(fake.sent()[0].text, /Te estamos enviando ahora/);
});

test('ignora cargas sin mensaje o con número inválido', async () => {
  const fake = citaya();
  assert.equal((await run(fake, JSON.stringify({ entry: [] }))).result, 'ignored');
  assert.equal((await run(fake, inbound('m9', '12', 'hola'))).result, 'ignored');
  assert.equal(fake.calls.length, 0);
});

test('ESTADO: muestra solo las solicitudes de este chat', async () => {
  const fake = citaya({ 'requests.list': r => ({ ok: true, requests: r.phone === '573001112233' ? [{ reference: 'HC-9', label: 'Pendiente de revisión' }] : [] }) });
  assert.equal((await run(fake, inbound('e1', '573001112233', 'Estado'))).result, 'status:sent');
  assert.match(fake.sent()[0].text, /\*HC-9\*: Pendiente de revisión/);
  await run(fake, inbound('e2', '573009998877', 'estado'));
  assert.match(fake.sent()[1].text, /No encontramos solicitudes/);
});
