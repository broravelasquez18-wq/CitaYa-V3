'use strict';
const http = require('node:http');
const path = require('node:path');
const fs = require('node:fs');
const crypto = require('node:crypto');
const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');
const QRCode = require('qrcode');

const port = Number(process.env.PORT || 3218);
// Secreto compartido con CitaYa (whatsapp_app_secret): firma lo que se envía al webhook y valida /send.
const secret = process.env.WA_SECRET || '';
const webhookUrl = process.env.WA_WEBHOOK_URL || 'http://localhost/CitaYaV3/public/whatsapp.php';
// Números (solo dígitos, con indicativo) a los que responde el bot. Vacío: responde a cualquiera.
const allowed = new Set((process.env.WA_ALLOWED || '').split(',').map(n => n.replace(/\D/g, '')).filter(Boolean));
const sign = body => 'sha256=' + crypto.createHmac('sha256', secret).update(body).digest('hex');
const state = { status: 'Iniciando WhatsApp…', qr: null, connected: false, received: 0 };
const browserPath = process.env.WA_BROWSER_PATH || (process.platform === 'win32'
  ? ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(file => fs.existsSync(file))
  : undefined);
const client = new Client({
  authStrategy: new LocalAuth({ dataPath: path.join(__dirname, '.session') }),
  webVersionCache: { type: 'local', path: path.join(__dirname, '.cache') },
  puppeteer: { headless: true, ...(browserPath ? { executablePath: browserPath } : {}) },
});
let qrGeneration = 0;
client.on('qr', async value => {
  const generation = ++qrGeneration;
  try {
    const data = await QRCode.toDataURL(value, { width: 300, margin: 3 });
    if (generation !== qrGeneration) return;
    state.qr = data;
    state.status = 'Escanea el QR con el WhatsApp del número secundario.';
  } catch { state.status = 'No se pudo generar el QR. Reinicia el servicio.'; }
});
client.on('authenticated', () => {
  ++qrGeneration;
  state.qr = null;
  state.status = 'Vinculado. Esperando que WhatsApp termine de cargar…';
});
client.on('ready', () => {
  ++qrGeneration;
  state.qr = null;
  state.connected = true;
  state.status = secret ? 'Conectado al bot de CitaYa. Escribe «historia» desde tu otro teléfono.' : 'Conectado, pero falta WA_SECRET: el bot no recibirá mensajes.';
});
for (const event of ['auth_failure', 'disconnected']) client.on(event, () => {
  ++qrGeneration;
  state.connected = false;
  state.qr = null;
  state.status = 'Sesión desconectada. Reinicia el servicio para volver a vincular.';
});
// Reenvía cada mensaje de texto al webhook de CitaYa con el mismo formato y firma que usa Meta.
// No se guardan textos aquí: CitaYa decide la respuesta y la pide por POST /send.
client.on('message', async message => {
  if (!state.connected || !secret || message.fromMe || message.type !== 'chat' || !/@(c\.us|lid)$/.test(message.from)) return;
  try {
    // Con identificadores @lid el número real se obtiene del contacto.
    const phone = message.from.endsWith('@c.us') ? message.from.slice(0, -5) : String((await message.getContact()).number || '');
    if (!/^\d{8,20}$/.test(phone) || (allowed.size && !allowed.has(phone))) return;
    const body = JSON.stringify({ entry: [{ changes: [{ value: { messages: [{ id: message.id._serialized, from: phone, type: 'text', text: { body: message.body.slice(0, 4096) } }] } }] }] });
    const response = await fetch(webhookUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Hub-Signature-256': sign(body) }, body, signal: AbortSignal.timeout(20000) });
    state.received++;
    state.status = response.ok ? 'Mensaje entregado al bot de CitaYa.' : 'El webhook de CitaYa respondió ' + response.status + '.';
  } catch { state.status = 'No se pudo entregar el mensaje al webhook de CitaYa. ¿Está encendido Apache?'; }
});

// Envío pedido por CitaYa (bin/worker.php). Solo acepta peticiones firmadas con el secreto compartido.
async function send(payload) {
  const to = String(payload.to || '').replace(/\D/g, '');
  if (!/^\d{8,20}$/.test(to)) return [400, 'invalid_recipient'];
  const id = await client.getNumberId(to);
  if (!id) return [404, 'not_on_whatsapp'];
  if (payload.type === 'text') {
    await client.sendMessage(id._serialized, String(payload.body || '').slice(0, 4096), { linkPreview: true });
  } else if (payload.type === 'document' || payload.type === 'image') {
    const mime = payload.type === 'document' ? 'application/pdf' : 'image/png';
    if (typeof payload.data !== 'string' || !payload.data) return [400, 'missing_file'];
    const media = new MessageMedia(mime, payload.data, String(payload.filename || (payload.type === 'document' ? 'documento.pdf' : 'orden.png')));
    await client.sendMessage(id._serialized, media, { caption: payload.caption ? String(payload.caption) : undefined, sendMediaAsDocument: payload.type === 'document' });
  } else return [400, 'invalid_type'];
  return [200, 'sent'];
}
const html = `<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>CitaYa · Prueba de WhatsApp</title><style>body{font:17px system-ui;background:#edf5f3;color:#183b36;margin:0;padding:40px 20px}main{max-width:650px;margin:auto;background:white;padding:32px;border-radius:20px}h1{margin-top:0}p{line-height:1.6}img{display:block;max-width:100%;margin:20px auto}small{color:#516962}#status{font-weight:600}#count{color:#116a4e}</style>
<main><h1>CitaYa · WhatsApp de prueba</h1><p>En el teléfono del <strong>número secundario</strong>, abre WhatsApp → Dispositivos vinculados → Vincular un dispositivo.</p>
<p>Al escanear, autorizas a este PC a acceder a esa cuenta como dispositivo vinculado. La sesión se conserva en este PC. Puedes revocarla desde Dispositivos vinculados.</p>
<p id="status">Cargando…</p><img id="qr" alt="Código QR para vincular WhatsApp" hidden><p id="count"></p>
<p>Después de conectar, escribe <strong>historia</strong> al número secundario desde tu otro teléfono. El bot de CitaYa responde con el enlace del formulario y, al completarlo, envía la historia o el radicado por este chat. Mantén encendidos Apache, MySQL y <code>php bin/worker.php --loop</code>.</p>
<small>Integración no oficial. Usa solo datos ficticios. El PC debe permanecer encendido.</small></main>
<script>async function update(){try{const r=await fetch('/status',{cache:'no-store'});if(!r.ok)throw Error();const s=await r.json();document.getElementById('status').textContent=s.status;const q=document.getElementById('qr');q.hidden=!s.qr;if(s.qr)q.src=s.qr;else q.removeAttribute('src');document.getElementById('count').textContent=s.received?'Mensajes entregados al bot: '+s.received:'';}catch{document.getElementById('status').textContent='No se puede conectar al servicio local.';document.getElementById('qr').hidden=true;}setTimeout(update,2000)}update();</script></html>`;
const server = http.createServer((req, res) => {
  // No servir el QR mediante hosts ajenos ni permitir lectura desde otros sitios.
  if (!['127.0.0.1:' + port, 'localhost:' + port].includes(req.headers.host)) {
    res.writeHead(403); return res.end();
  }
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
      if (!state.connected) return reply(503, 'whatsapp_disconnected');
      let payload;
      try { payload = JSON.parse(raw.toString('utf8')); } catch { return reply(400, 'invalid_json'); }
      try { const [code, result] = await send(payload); reply(code, result); }
      catch { reply(502, 'send_failed'); }
    });
    return;
  }
  if (req.method !== 'GET') { res.writeHead(405); return res.end(); }
  if (req.url === '/status') { res.setHeader('Content-Type', 'application/json'); return res.end(JSON.stringify(state)); }
  if (req.url !== '/') { res.writeHead(404); return res.end(); }
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.end(html);
});
server.on('error', () => { console.error('No se pudo abrir el puerto local.'); process.exit(1); });
server.listen(port, '127.0.0.1', () => {
  console.log('Panel local: http://127.0.0.1:' + port);
  client.initialize().catch(() => { state.qr = null; state.status = 'No se pudo iniciar WhatsApp. Revisa la instalación del navegador y reinicia.'; });
});
let stopping = false;
async function stop() {
  if (stopping) return;
  stopping = true;
  server.close();
  const timeout = setTimeout(() => process.exit(1), 5000);
  timeout.unref();
  try { await client.destroy(); } finally { process.exit(0); }
}
process.on('SIGINT', stop);
process.on('SIGTERM', stop);
