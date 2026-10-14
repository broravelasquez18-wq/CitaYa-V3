# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Pacientes** de la clínica que necesitan una copia de la historia clínica de una atención. Muchos llegan desde el celular, a menudo desde un enlace del bot de WhatsApp; no tienen cuenta ni contraseña y usan el formulario una o pocas veces.
- **Personal de la clínica** (administradores y empleados identificados por cédula): cargan los PDF de las historias, revisan solicitudes y envíos, gestionan casos sin coincidencia, reportes de error, plantillas de mensajes, el bot de WhatsApp, empleados y análisis mensual. Lo usan a diario en escritorio.

## Product Purpose

CitaYa lee los PDF de historias clínicas (CEDIM / Indigo), crea automáticamente el índice de pacientes y atenciones y permite que el paciente reciba el paquete completo de una atención en su correo (y por WhatsApp) sin que nadie lo digite. Éxito: el paciente obtiene su historia correcta sin llamar a la clínica, y el personal resuelve lo que no coincidió con poco esfuerzo.

## Positioning

El formulario del paciente funciona como un buscador sobre el índice extraído de los propios PDF: no hay registro de pacientes, ni códigos de verificación, ni digitación manual al cargar historias. Entrega el paquete íntegro de una atención (con anexos) o nada.

## Operating Context

- Portal público en tres pasos: tus datos → búsqueda → entrega (estados: en cola, enviando, enviado, simulado, sin coincidencias, no disponible, incierto, fallido; sugerencia de la consulta más reciente; reenvío con espera y límite).
- Acceso interno: repositorio y carga de PDF (una atención / varias atenciones), solicitudes y envíos con detalle en modal, historial de enviadas con reapertura de caso, reportes de error de pacientes, plantillas de mensajes, vinculación del bot de WhatsApp por QR, empleados, análisis mensual, progreso de colas.
- Servidor PHP con plantillas en `templates/`, CSS/JS sin compilación en `public/assets/`; desplegado en un VPS con Docker.

## Capabilities and Constraints

- Debe funcionar sin JavaScript (formularios clásicos con CSRF); el JS solo mejora (modales, progreso de carga, cuenta regresiva).
- Datos clínicos: nada de datos reales en ejemplos, capturas o el repositorio público.
- Idioma: español (Colombia). Fechas `dd/mm/aaaa`.
- «Correo enviado» significa aceptación del servidor SMTP, no lectura; «Aprobado» es validación automática, no aprobación humana.

## Brand Commitments

- Nombre: **CitaYa**. Nada más del aspecto visual anterior es compromiso (confirmado por el titular el 2026-10-13).

## Evidence on Hand

- No hay logotipo, fotografías ni testimonios. No inventar instituciones, cifras ni sellos de certificación.

## Product Principles

1. El paciente nunca debe dudar de qué pasó con su solicitud ni de qué hacer después.
2. Decir la verdad exacta del estado (aceptado por el servidor ≠ recibido; sin coincidencia ≠ no existe).
3. El trabajo diario del personal prima sobre la decoración: escaneo rápido, estados claros, cero ambigüedad.
4. Mejora progresiva: todo flujo funciona sin JavaScript.

## Accessibility & Inclusion

- Pacientes de cualquier edad y alfabetización digital, mayoritariamente en móvil: campos de 16 px, objetivos táctiles amplios, contraste AA, foco visible, navegación por teclado en modales.
