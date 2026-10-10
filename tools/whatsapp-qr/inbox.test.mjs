// node --test inbox.test.mjs — bandeja persistente con un webhook simulado (sin WhatsApp).
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

import { createInbox } from './inbox.mjs';

function setup(deliver) {
  const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'citaya-inbox-')), 'inbox.json');
  let clock = 1_000_000;
  const options = { file, deliver, now: () => clock };
  return { file, options, inbox: createInbox(options), advance: ms => { clock += ms; } };
}

test('guarda antes de entregar y no duplica el mismo id', async () => {
  const { inbox, file } = setup(async () => true);
  assert.equal(inbox.add('m1', '{"a":1}'), true);
  assert.equal(inbox.add('m1', '{"a":1}'), false);
  assert.equal(JSON.parse(fs.readFileSync(file, 'utf8')).length, 1);
  assert.deepEqual(await inbox.flush(), { delivered: 1, failed: 0, dropped: 0 });
  assert.equal(inbox.size, 0);
});

test('si el webhook falla, conserva el mensaje y reintenta con espera creciente', async () => {
  let up = false; const calls = [];
  const { inbox, advance } = setup(async body => { calls.push(body); return up; });
  inbox.add('m1', 'x');
  assert.deepEqual(await inbox.flush(), { delivered: 0, failed: 1, dropped: 0 });
  assert.deepEqual(await inbox.flush(), { delivered: 0, failed: 0, dropped: 0 }, 'no reintenta antes de tiempo');
  advance(10_000);
  await inbox.flush();
  assert.equal(calls.length, 2);
  advance(10_000);
  assert.equal((await inbox.flush()).failed, 0, 'la segunda espera es de 20 s');
  advance(10_000); up = true;
  assert.deepEqual(await inbox.flush(), { delivered: 1, failed: 0, dropped: 0 });
  assert.equal(inbox.size, 0);
});

test('sobrevive a un reinicio del servicio', async () => {
  const { inbox, options } = setup(async () => false);
  inbox.add('m1', 'x');
  await inbox.flush();
  const restarted = createInbox({ ...options, deliver: async () => true, now: () => options.now() + 60_000 });
  assert.equal(restarted.size, 1);
  assert.equal((await restarted.flush()).delivered, 1);
});

test('descarta tras 24 h sin poder entregar', async () => {
  const { inbox, advance } = setup(async () => false);
  inbox.add('m1', 'x');
  advance(86_400_001);
  assert.deepEqual(await inbox.flush(), { delivered: 0, failed: 0, dropped: 1 });
  assert.equal(inbox.size, 0);
});

test('un error de red cuenta como fallo, no rompe la bandeja', async () => {
  const { inbox } = setup(async () => { throw new Error('ECONNREFUSED'); });
  inbox.add('m1', 'x');
  assert.equal((await inbox.flush()).failed, 1);
  assert.equal(inbox.size, 1);
});
