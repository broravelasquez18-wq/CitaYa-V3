# WhatsApp por QR (Baileys)

Canal de WhatsApp para pruebas de CitaYa sin la API oficial de Meta. Se vincula como un dispositivo más del número del bot (Dispositivos vinculados) usando [Baileys](https://github.com/WhiskeySockets/Baileys), sin navegador.

Hace dos cosas:

- **Entrada:** reenvía cada mensaje de texto al webhook de CitaYa (`WA_WEBHOOK_URL`, por defecto `http://localhost/CitaYaV3/public/whatsapp.php`). Usa el mismo formato y la misma firma `X-Hub-Signature-256` que Meta.
- **Salida:** atiende `POST /send` (firmado con `X-Signature`) para enviar texto, PDF e imágenes. Los envíos los pide el procesador de CitaYa (`php bin/worker.php --loop`) con `whatsapp_transport = qr`.

Requiere Node.js 20 o superior. Desde esta carpeta:

```powershell
npm.cmd ci
npm.cmd start
```

Variables (archivo `.env`, fuera del repositorio):

| Variable | Uso |
|---|---|
| `WA_SECRET` | Secreto compartido; debe ser igual a `whatsapp_app_secret` de CitaYa |
| `WA_WEBHOOK_URL` | Webhook de CitaYa |
| `WA_ALLOWED` | Opcional: números (con indicativo, separados por coma) a los que responde; vacío = todos |
| `PORT` / `WA_HOST` | Por defecto `3218` en `127.0.0.1` |

Abre http://127.0.0.1:3218 y escanea el QR desde WhatsApp → **Dispositivos vinculados** → Vincular un dispositivo. Aparece como «CitaYa (Windows)». La sesión se reconecta sola al reiniciar.

Detalles de funcionamiento:

- Si el contacto llega con un identificador interno de WhatsApp (`@lid`), se obtiene su número real. El servicio recuerda de qué chat vino cada número (`.session/chats.json`) y responde ahí.
- Si la sesión se cierra desde el teléfono, el servicio borra la sesión local y muestra un QR nuevo.
- En Windows, `bin/start-whatsapp-qr.ps1` lo inicia sin duplicarlo. `bin/install-worker-task.ps1` crea la tarea «CitaYaV3 - Procesador y WhatsApp QR», que lo mantiene encendido junto con el procesador.

`.session/` contiene las credenciales de la sesión de WhatsApp: no la compartas ni la incluyas en copias del código. El panel con el QR solo responde en este equipo. Para revocar el acceso, cierra la sesión desde el teléfono.

Integración **no oficial**: puede provocar el bloqueo del número. Úsala solo para pruebas con datos ficticios, nunca con pacientes reales. Antes se usaba whatsapp-web.js, que dejó de poder enviar adjuntos (SCRUM-36).
