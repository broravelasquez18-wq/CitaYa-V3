// node --test n8n/citaya-errors.test.js — aviso de errores de n8n contra una CitaYa simulada.
const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { citayaErrorAlert } = require('./citaya-errors.js');

const env = { CITAYA_URL: 'https://citaya.example.test/', CITAYA_N8N_SECRET: 's', CITAYA_ADMIN_WHATSAPP: '+57 300 000 0999' };
function citaya() {
  const calls = [];
  const http = async ({ url, body, headers }) => { calls.push({ url, body: JSON.parse(body), raw: body, headers }); return '{"ok":true,"status":"sent"}'; };
  return { http, calls };
}

test('falla de ejecución: avisa al responsable con flujo, nodo y enlace, firmado', async () => {
  const fake = citaya();
  const error = { execution: { id: '231', url: 'https://n8n.example.test/workflow/1/executions/231', lastNodeExecuted: 'Procesar', error: { message: 'HTTP 502' } }, workflow: { id: '1', name: 'CitaYa · Bot WhatsApp' } };
  assert.equal((await citayaErrorAlert({ error, env, http: fake.http, crypto })).result, 'aviso: sent');
  const [call] = fake.calls;
  assert.equal(call.url, 'https://citaya.example.test/n8n.php');
  assert.equal(call.body.action, 'message.send');
  assert.equal(call.body.phone, '573000000999');
  assert.equal(call.body.key, 'n8n-error:231');
  assert.match(call.body.text, /CitaYa · Bot WhatsApp.*Procesar[\s\S]*HTTP 502[\s\S]*executions\/231/);
  assert.equal(call.headers['X-CitaYa-Signature'], 'sha256=' + crypto.createHmac('sha256', 's').update(call.raw).digest('hex'));
});

test('falla del disparador: usa el error del trigger', async () => {
  const fake = citaya();
  const error = { trigger: { error: { message: 'Schedule failed', timestamp: 1760000000, node: { name: 'Cada hora' } } }, workflow: { name: 'CitaYa · Seguimiento' } };
  await citayaErrorAlert({ error, env, http: fake.http, crypto });
  assert.equal(fake.calls[0].body.key, 'n8n-error:1760000000');
  assert.match(fake.calls[0].body.text, /Seguimiento.*Cada hora[\s\S]*Schedule failed/);
});

test('oculta números largos (teléfonos, documentos) y recorta el detalle', async () => {
  const fake = citaya();
  const error = { execution: { id: '9', error: { message: 'No se pudo enviar a 573001234567 documento 1088000000 ' + 'x'.repeat(400) } }, workflow: { name: 'W' } };
  await citayaErrorAlert({ error, env, http: fake.http, crypto });
  const text = fake.calls[0].body.text;
  assert.doesNotMatch(text, /573001234567|1088000000/);
  assert.ok(text.split('\n')[1].length <= 160);
});

test('sin configuración o sin responsable no llama a CitaYa', async () => {
  const fake = citaya();
  assert.equal((await citayaErrorAlert({ error: {}, env: {}, http: fake.http, crypto })).result, 'not_configured');
  assert.equal((await citayaErrorAlert({ error: {}, env: { ...env, CITAYA_ADMIN_WHATSAPP: '' }, http: fake.http, crypto })).result, 'sin_responsable');
  assert.equal(fake.calls.length, 0);
});

test('CitaYa caída: no lanza', async () => {
  const http = async () => { throw new Error('ECONNREFUSED'); };
  assert.equal((await citayaErrorAlert({ error: {}, env, http, crypto })).result, 'aviso: unreachable');
});
