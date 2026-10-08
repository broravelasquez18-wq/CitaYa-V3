# Prueba de WhatsApp por QR

Servicio aislado de la integración Cloud API existente. Primera etapa: vincular un número secundario y comprobar que llega `!citaya` desde otro teléfono. No envía respuestas, no descarga adjuntos y no conecta aún con n8n ni con el repositorio clínico.

Requiere Node.js 22 o superior. Desde esta carpeta:

```powershell
npm.cmd ci
npm.cmd start
```

En Windows se detecta Chrome o Edge instalado y se usa un perfil independiente. Si ya tienes uno, puedes ejecutar `$env:PUPPETEER_SKIP_DOWNLOAD='true'` antes de `npm.cmd ci` para evitar descargar otro navegador. En otros sistemas se usa el navegador instalado por Puppeteer; `WA_BROWSER_PATH` permite indicar una ruta explícita.

Abre http://127.0.0.1:3218 y vincula el número secundario desde WhatsApp → Dispositivos vinculados. El panel actualiza el QR automáticamente. Envía `!citaya` desde otro teléfono para comprobar recepción.

La sesión de WhatsApp Web se conserva en `.session/`, que puede contener credenciales e historial sincronizado. No compartir, publicar ni incluir esa carpeta en copias del código. El panel solo escucha en loopback y no sirve archivos de sesión. Para revocar acceso, cierra la sesión desde el teléfono. Ctrl+C detiene el servicio.

Usa datos ficticios. whatsapp-web.js es una integración no oficial y puede provocar bloqueos del número. Este servicio no levanta la restricción de la cuenta de Meta y no está preparado para pacientes reales. Antes de n8n hay que acordar el webhook y limitar los remitentes de prueba. No exponer este panel a Internet.
