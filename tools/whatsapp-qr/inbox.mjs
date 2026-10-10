// Bandeja persistente de mensajes recibidos pendientes de entregar al webhook (n8n o CitaYa).
// Se guarda antes de entregar; si el webhook no responde, se reintenta con espera creciente (10 s, 20 s, … hasta 10 min)
// durante máximo 24 h. Al entregarse se borra. El archivo vive en .session/ (privada, fuera del repositorio).
import fs from 'node:fs';
import path from 'node:path';

export function createInbox({ file, deliver, now = () => Date.now(), maxAgeMs = 86400000 }) {
  let items = (() => { try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch { return []; } })();
  let flushing = false;
  const save = () => {
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file + '.tmp', JSON.stringify(items));
    fs.renameSync(file + '.tmp', file);
  };
  return {
    get size() { return items.length; },
    /** Guarda un mensaje (una sola vez por id). */
    add(id, body) {
      if (items.some(item => item.id === id)) return false;
      items.push({ id, body, attempts: 0, next: now(), created: now() });
      save();
      return true;
    },
    /** Intenta entregar lo que ya toca. Devuelve { delivered, failed, dropped }. */
    async flush() {
      const result = { delivered: 0, failed: 0, dropped: 0 };
      if (flushing) return result;
      flushing = true;
      try {
        for (const item of [...items]) {
          if (item.next > now()) continue;
          let ok = false;
          try { ok = await deliver(item.body); } catch { ok = false; }
          if (ok) { items = items.filter(other => other.id !== item.id); result.delivered++; }
          else if (now() - item.created > maxAgeMs) { items = items.filter(other => other.id !== item.id); result.dropped++; }
          else { item.attempts++; item.next = now() + Math.min(600000, 10000 * 2 ** (item.attempts - 1)); result.failed++; }
          save();
        }
      } finally { flushing = false; }
      return result;
    },
  };
}
