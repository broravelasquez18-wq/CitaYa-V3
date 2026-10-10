// node --test n8n/citaya-followup.test.js — seguimiento administrativo contra una CitaYa simulada.
const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { citayaFollowUp } = require('./citaya-followup.js');

const env = { CITAYA_URL: 'https://citaya.example.test', CITAYA_N8N_SECRET: 's', CITAYA_ADMIN_WHATSAPP: '573000000999' };
const healthy = { ok: true, worker_seconds: 30, qr: { connected: true, pending: 0 }, failed_24h: { whatsapp: 0, correo: 0, eventos: 0 } };
function citaya(handlers) {
  const sent = [];
  const http = async ({ body }) => {
    const r = JSON.parse(body);
    if (r.action === 'message.send') { sent.push(r); return { ok: true, status: 'sent' }; }
    return handlers[r.action] ? handlers[r.action](r) : { ok: true, requests: [], hours: 24 };
  };
  return { http, sent };
}
const at = iso => new Date(iso); // hora UTC; Colombia = UTC-5

test('sin novedades no escribe', async () => {
  const fake = citaya({ 'system.health': () => healthy });
  assert.equal((await citayaFollowUp({ env, http: fake.http, crypto, now: at('2026-10-10T15:00:00Z') })).result, 'sin novedades');
  assert.equal(fake.sent.length, 0);
});

test('procesador detenido y QR desconectado: avisa con clave por hora', async () => {
  const fake = citaya({ 'system.health': () => ({ ...healthy, worker_seconds: 900, qr: { connected: false } }) });
  await citayaFollowUp({ env, http: fake.http, crypto, now: at('2026-10-10T15:20:00Z') });
  assert.equal(fake.sent.length, 2);
  assert.ok(fake.sent.every(s => s.phone === '573000000999'));
  assert.deepEqual(fake.sent.map(s => s.key), ['salud:worker:2026-10-10:10', 'salud:qr:2026-10-10:10']);
});

test('plazos: por vencer y vencida, una clave por etapa', async () => {
  const fake = citaya({ 'system.health': () => healthy, 'requests.overdue': () => ({ ok: true, hours: 24, requests: [{ reference: 'HC-1', age_hours: 19, stage: 'por_vencer' }, { reference: 'HC-2', age_hours: 30, stage: 'vencida' }] }) });
  await citayaFollowUp({ env, http: fake.http, crypto, now: at('2026-10-10T15:00:00Z') });
  assert.deepEqual(fake.sent.map(s => s.key), ['plazo:HC-1:por_vencer', 'plazo:HC-2:vencida']);
  assert.match(fake.sent[1].text, /VENCIDA.*30 h/);
});

test('resumen diario solo a las 7:00 de Colombia', async () => {
  const fake = citaya({ 'system.health': () => healthy, 'stats.summary': () => ({ ok: true, date: '2026-10-09', created: 5, from_whatsapp: 3, delivered: 4, pending_now: 1, overdue_now: 0 }) });
  await citayaFollowUp({ env, http: fake.http, crypto, now: at('2026-10-10T11:00:00Z') });
  await citayaFollowUp({ env, http: fake.http, crypto, now: at('2026-10-10T12:00:00Z') });
  assert.equal(fake.sent.length, 1);
  assert.equal(fake.sent[0].key, 'resumen:2026-10-09');
  assert.match(fake.sent[0].text, /Solicitudes: 5 \(3 por WhatsApp\)/);
});

test('si CitaYa no responde, avisa una vez por hora', async () => {
  const fake = citaya({ 'system.health': () => { throw new Error('caído'); }, 'requests.overdue': () => { throw new Error('caído'); } });
  await citayaFollowUp({ env, http: fake.http, crypto, now: at('2026-10-10T15:00:00Z') });
  assert.deepEqual(fake.sent.map(s => s.key), ['salud:citaya:2026-10-10:10']);
});
