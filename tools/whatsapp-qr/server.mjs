// Servicio de WhatsApp por QR para pruebas de CitaYa, con Baileys (WhatsApp Web sin navegador).
// Mismo contrato que usa CitaYa: reenvía cada mensaje al webhook con la firma de Meta (X-Hub-Signature-256)
// y envía lo que CitaYa pide por POST /send firmado con el secreto compartido (whatsapp_app_secret).
import http from 'node:http';
import path from 'node:path';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import QRCode from 'qrcode';
import { createInbox } from './inbox.mjs';
import pino from 'pino';
import makeWASocket, { useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion, Browsers, jidNormalizedUser, isJidGroup } from '@whiskeysockets/baileys';

const dir = path.dirname(fileURLToPath(import.meta.url));
const port = Number(process.env.PORT || 3218);
const host = process.env.WA_HOST || '127.0.0.1';
const secret = process.env.WA_SECRET || '';
const webhookUrl = process.env.WA_WEBHOOK_URL || 'http://localhost/CitaYaV3/public/whatsapp.php';
// Números (solo dígitos, con indicativo) a los que responde el bot. Vacío: responde a cualquiera.
const allowed = new Set((process.env.WA_ALLOWED || '').split(',').map(n => n.replace(/\D/g, '')).filter(Boolean));
const sessionDir = path.join(dir, '.session', 'baileys');
const sign = body => 'sha256=' + crypto.createHmac('sha256', secret).update(body).digest('hex');
const state = { status: 'Iniciando WhatsApp…', qr: null, connected: false, received: 0, pending: 0 };
const logger = pino({ level: 'silent' });

// Número reenviado a CitaYa → chat (jid) al que se responde. Se guarda junto a la sesión.
const chatsFile = path.join(dir, '.session', 'chats.json');
const chats = new Map(Object.entries((() => { try { return JSON.parse(fs.readFileSync(chatsFile, 'utf8')); } catch { return {}; } })()));
const rememberChat = (phone, jid) => {
  if (chats.get(phone) === jid) return;
  chats.set(phone, jid);
  try { fs.mkdirSync(path.dirname(chatsFile), { recursive: true }); fs.writeFileSync(chatsFile, JSON.stringify(Object.fromEntries(chats))); } catch {}
};

let sock = null;
let pairingRequested = false;

// Bandeja persistente (inbox.mjs): cada mensaje se guarda antes de entregarse al webhook y se reintenta si falla.
const inbox = createInbox({
  file: path.join(dir, '.session', 'inbox.json'),
  deliver: async body => (await fetch(webhookUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Hub-Signature-256': sign(body) }, body, signal: AbortSignal.timeout(20000) })).ok,
});
const enqueueInbound = (id, body) => inbox.add(id, body);
async function flushInbound() {
  const result = await inbox.flush();
  state.pending = inbox.size;
  if (result.delivered) state.status = 'Mensaje entregado al webhook.';
  if (result.failed) state.status = 'El webhook no respondió. Se reintentará (' + inbox.size + ' pendiente' + (inbox.size === 1 ? '' : 's') + ').';
  if (result.dropped) console.error(result.dropped + ' mensaje(s) descartado(s) tras 24 h sin poder entregarse al webhook.');
}
setInterval(flushInbound, 10000);
const digits = jid => (jid || '').split('@')[0].split(':')[0].replace(/\D/g, '');

/** Número real del remitente: el jid @s.whatsapp.net, el alternativo que trae la clave o el mapeo LID→número. */
async function senderPhone(key) {
  const jid = key.remoteJid || '';
  if (jid.endsWith('@s.whatsapp.net')) return digits(jid);
  if (key.remoteJidAlt?.endsWith('@s.whatsapp.net')) return digits(key.remoteJidAlt);
  try {
    const pn = await sock.signalRepository?.lidMapping?.getPNForLID(jid);
    if (pn) return digits(pn);
  } catch {}
  return '';
}

function textOf(message) {
  const m = message?.ephemeralMessage?.message || message?.viewOnceMessage?.message || message || {};
  return m.conversation || m.extendedTextMessage?.text || m.buttonsResponseMessage?.selectedButtonId
    || m.listResponseMessage?.singleSelectReply?.selectedRowId || m.templateButtonReplyMessage?.selectedId || '';
}

async function connect() {
  fs.mkdirSync(sessionDir, { recursive: true });
  const { state: auth, saveCreds } = await useMultiFileAuthState(sessionDir);
  let version;
  try { ({ version } = await fetchLatestBaileysVersion()); } catch {}
  sock = makeWASocket({ auth, logger, browser: Browsers.windows('CitaYa'), markOnlineOnConnect: false, syncFullHistory: false, ...(version ? { version } : {}) });
  sock.ev.on('creds.update', saveCreds);
  sock.ev.on('connection.update', async update => {
    // Vinculación con código (sin escanear): con WA_PAIR_PHONE, WhatsApp entrega un código de 8 caracteres que se
    // escribe en el teléfono (Dispositivos vinculados → Vincular con el número de teléfono). Útil en el VPS.
    const pairPhone = (process.env.WA_PAIR_PHONE || '').replace(/\D/g, '');
    if (update.qr && pairPhone && !auth.creds.registered && !pairingRequested) {
      pairingRequested = true;
      try {
        const code = await sock.requestPairingCode(pairPhone);
        state.pairingCode = code;
        state.status = 'Escribe el código ' + code + ' en WhatsApp → Dispositivos vinculados → Vincular con el número de teléfono.';
        console.log('CÓDIGO DE VINCULACIÓN: ' + code + '  (WhatsApp → Dispositivos vinculados → Vincular un dispositivo → Vincular con el número de teléfono)');
      } catch (error) { console.error('No se pudo pedir el código de vinculación:', String(error?.message || error).slice(0, 160)); }
    }
    if (update.qr) {
      state.qr = await QRCode.toDataURL(update.qr, { margin: 1, width: 300 });
      // En el VPS el panel no es público: el QR también se imprime en los registros del contenedor.
      if (process.env.WA_PRINT_QR === '1') console.log('Escanea este QR desde WhatsApp → Dispositivos vinculados:\n' + await QRCode.toString(update.qr, { type: 'terminal', small: true }));
      state.status = 'Escanea el QR desde WhatsApp → Dispositivos vinculados.';
    }
    if (update.connection === 'open') {
      state.qr = null; state.connected = true; state.pairingCode = null; pairingRequested = false;
      state.status = secret ? 'Conectado al bot de CitaYa. Escribe «historia» desde tu otro teléfono.' : 'Conectado, pero falta WA_SECRET: el bot no recibirá mensajes.';
      console.log('Conectado a WhatsApp.');
    }
    if (update.connection === 'close') {
      state.connected = false;
      pairingRequested = false; // al reconectar se pide un código nuevo
      const code = update.lastDisconnect?.error?.output?.statusCode;
      if (code === DisconnectReason.loggedOut) {
        // La sesión se cerró desde el teléfono: se borra y se pide un QR nuevo.
        fs.rmSync(sessionDir, { recursive: true, force: true });
        state.status = 'Sesión cerrada desde el teléfono. Generando un QR nuevo…';
      } else {
        state.status = 'Reconectando con WhatsApp…';
      }
      setTimeout(() => connect().catch(err => { state.status = 'No se pudo conectar: ' + String(err?.message || err).slice(0, 120); }), 3000);
    }
  });
  sock.ev.on('messages.upsert', async ({ messages, type }) => {
    if (type !== 'notify' || !secret || !state.connected) return;
    for (const message of messages) {
      const key = message.key || {};
      if (key.fromMe || !key.remoteJid || isJidGroup(key.remoteJid) || key.remoteJid === 'status@broadcast') continue;
      const text = textOf(message.message);
      if (!text) continue;
      try {
        const phone = await senderPhone(key);
        if (!/^\d{8,20}$/.test(phone) || (allowed.size && !allowed.has(phone))) continue;
        rememberChat(phone, key.remoteJid);
        const id = key.id || 'qr-' + crypto.createHash('sha256').update(key.remoteJid + ':' + message.messageTimestamp + ':' + text).digest('hex');
        const body = JSON.stringify({ entry: [{ changes: [{ value: { messages: [{ id, from: phone, type: 'text', text: { body: text.slice(0, 4096) } }] } }] }] });
        // Primero se guarda (bandeja persistente) y luego se entrega: si el webhook no responde, se reintenta.
        enqueueInbound(id, body);
        state.received++;
      } catch { state.status = 'No se pudo registrar un mensaje recibido.'; }
    }
    flushInbound();
  });
}

// Envío pedido por CitaYa (bin/worker.php).
async function send(payload) {
  const to = String(payload.to || '').replace(/\D/g, '');
  if (!/^\d{8,20}$/.test(to)) return [400, 'invalid_recipient'];
  let jid = chats.get(to);
  if (!jid) {
    const [found] = await sock.onWhatsApp(to);
    if (!found?.exists) return [404, 'not_on_whatsapp'];
    jid = jidNormalizedUser(found.jid);
  }
  const caption = payload.caption ? String(payload.caption) : undefined;
  if (payload.type === 'text') {
    await sock.sendMessage(jid, { text: String(payload.body || '').slice(0, 4096) });
  } else if (payload.type === 'document' || payload.type === 'image') {
    if (typeof payload.data !== 'string' || !payload.data) return [400, 'missing_file'];
    const data = Buffer.from(payload.data, 'base64');
    await sock.sendMessage(jid, payload.type === 'document'
      ? { document: data, mimetype: 'application/pdf', fileName: String(payload.filename || 'documento.pdf'), caption }
      : { image: data, mimetype: 'image/png', caption });
  } else return [400, 'invalid_type'];
  return [200, 'sent'];
}

const html = `<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>CitaYa · WhatsApp por QR</title><style>body{font:17px system-ui;background:#edf5f3;color:#183b36;margin:0;padding:40px 20px}main{max-width:650px;margin:auto;background:white;padding:32px;border-radius:20px}h1{margin-top:0}p{line-height:1.6}img{display:block;max-width:100%;margin:20px auto}small{color:#516962}#status{font-weight:600}#count{color:#116a4e}</style>
<main><h1>CitaYa · WhatsApp por QR</h1><p>En el teléfono del número del bot abre WhatsApp → <strong>Dispositivos vinculados</strong> → Vincular un dispositivo, y escanea el código.</p>
<p>La sesión queda guardada en este equipo. Puedes cerrarla cuando quieras desde Dispositivos vinculados.</p>
<p id="status">Cargando…</p><img id="qr" alt="Código QR para vincular WhatsApp" hidden><p id="count"></p>
<p>Después de conectar, escribe <strong>historia</strong> al número del bot desde otro teléfono. Mantén encendidos Apache, MySQL y el procesador (<code>php bin/worker.php --loop</code>).</p>
<small>Integración no oficial. Usa solo datos ficticios.</small></main>
<script>async function update(){try{const r=await fetch('/status',{cache:'no-store'});if(!r.ok)throw Error();const s=await r.json();document.getElementById('status').textContent=s.status;const q=document.getElementById('qr');q.hidden=!s.qr;if(s.qr)q.src=s.qr;else q.removeAttribute('src');document.getElementById('count').textContent=s.received?'Mensajes entregados al bot: '+s.received:'';}catch{document.getElementById('status').textContent='No se puede conectar al servicio local.';document.getElementById('qr').hidden=true;}setTimeout(update,2000)}update();</script></html>`;

const server = http.createServer((req, res) => {
  // El panel con el QR solo se sirve en este equipo; /send lo usa CitaYa con firma.
  const localHost = ['127.0.0.1:' + port, 'localhost:' + port].includes(req.headers.host);
  if (req.headers['sec-fetch-site'] === 'cross-site') { res.writeHead(403); return res.end(); }
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('Content-Security-Policy', "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src 'self'; frame-ancestors 'none'");
  if (req.method === 'POST' && req.url === '/send') {
    const chunks = []; let size = 0;
    req.on('data', chunk => { size += chunk.length; if (size > 25 * 1024 * 1024) req.destroy(); else chunks.push(chunk); });
    req.on('end', async () => {
      const raw = Buffer.concat(chunks);
      const given = Buffer.from(String(req.headers['x-signature'] || ''));
      const expected = Buffer.from(secret ? sign(raw) : '');
      const reply = (code, result) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify({ result })); };
      if (!secret || given.length !== expected.length || !crypto.timingSafeEqual(given, expected)) return reply(401, 'invalid_signature');
      if (!state.connected || !sock) return reply(503, 'whatsapp_disconnected');
      let payload;
      try { payload = JSON.parse(raw.toString('utf8')); } catch { return reply(400, 'invalid_json'); }
      try { const [code, result] = await send(payload); reply(code, result); }
      catch (error) { console.error('Envío fallido (' + (payload.type || '?') + '):', String(error?.message || error).slice(0, 300)); reply(502, 'send_failed'); }
    });
    return;
  }
  // Estado para el monitoreo de CitaYa (por la red interna): sin QR ni datos de mensajes.
  if (req.method === 'GET' && req.url === '/health') { res.setHeader('Content-Type', 'application/json'); return res.end(JSON.stringify({ connected: state.connected, pending: inbox.size })); }
  if (!localHost) { res.writeHead(403); return res.end(); }
  if (req.method !== 'GET') { res.writeHead(405); return res.end(); }
  if (req.url === '/status') { res.setHeader('Content-Type', 'application/json'); return res.end(JSON.stringify(state)); }
  if (req.url !== '/') { res.writeHead(404); return res.end(); }
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.end(html);
});
server.on('error', () => { console.error('No se pudo abrir el puerto ' + port + '.'); process.exit(1); });
server.listen(port, host, () => {
  console.log('Panel local: http://127.0.0.1:' + port);
  connect().catch(err => { state.status = 'No se pudo iniciar WhatsApp: ' + String(err?.message || err).slice(0, 120); });
});
