# Flujo vigente: solicitud como buscador

Adaptación móvil: estilos comunes en `public/assets/responsive.css`, formularios de una columna, campos de 16 px y controles táctiles, navegación administrativa en cuadrícula y tablas convertidas en tarjetas con etiquetas a partir de 700 px. Los encabezados conservan sus roles y asociaciones; en escritorio se mantiene la tabla. Las cargas y modales se ajustan al ancho y alto disponibles. Se verificaron 10 vistas con datos ficticios a 320, 390, 768 y 1280 px (40 combinaciones), ausencia de desbordamiento y funcionamiento de fecha desconocida, selector de carga y confirmación/cancelación de reapertura. Se revisaron visualmente el formulario real y un modal ficticio a 390 px. No se enviaron correos en esta validación.

Reapertura administrativa: confirmación modal con radicado, Cancelar y Confirmar. Guarda fecha, administrador y el último trabajo existente como límite del ciclo; conserva los estados de entrega. Mientras no exista un envío real posterior a ese límite, el caso figura pendiente de gestión fuera del historial de cerrados y primero en Solicitudes y envíos. Reabrir no encola mensajes. La operación es transaccional, exige administrador activo y CSRF, bloquea envíos activos y detecta formularios obsoletos. Pruebas con datos ficticios cubren persistencia, auditoría, no duplicación, ausencia de envíos y cierre por un envío posterior incluso en el mismo segundo.

Historial de enviadas: vista administrativa paginada con radicado/fecha, paciente, atención, tipo, cierre, aprobación automática y ojo para detalle en modal. Se deriva de solicitudes con coincidencia validada (o verificación del flujo anterior) y al menos un trabajo de historia enviado con fecha de aceptación SMTP. No requiere migración ni intervención manual. Conserva una fila por solicitud y la fecha del primer envío exitoso aunque un reenvío posterior falle. Excluye simulaciones y trabajos pendientes; no implica verificación de identidad ni recepción en la bandeja del paciente. Se probaron inclusión/exclusión, búsquedas, persistencia del cierre, deduplicación, acceso autenticado y navegación al modal.

Carga masiva: selector múltiple con límites de cantidad y tamaño, comprobación de recepción completa, lectura previa de todos los PDF y agrupación por paciente e ingreso. Un archivo no identificable impide publicar el lote; los conflictos de datos identificables se informan por atención. Conserva integridad, transacciones, anexos y versiones. Se verifica agrupación, deduplicación con diferente orden, errores y carga multipart autenticada con documentos ficticios, sin envío de correos.

El detalle administrativo de envío se abre en un modal desde el botón de ojo, conservando búsqueda y paginación. Usa diálogo nativo con cierre por botón, Escape y fondo, carga autenticada sin caché y cancelación de peticiones al cerrar. La vista completa queda disponible como alternativa sin JavaScript.

Gestión administrativa: se añade búsqueda paginada por nombre, radicado y documento, y detalle mediante botón de ojo. El detalle muestra todos los envíos de historia (original y reenvíos), destinatario, estado, tiempos, intentos, errores y PDF asociados. Solo permite lectura con sesión administrativa. Las nuevas solicitudes conservan el documento declarado para buscar también solicitudes sin coincidencia. Pruebas de consultas, paginación, historial, acceso HTTP, escape de entradas y respuesta 404.

Se añade **No recuerdo la fecha** al formulario. El calendario deja de ser obligatorio al marcar la opción; la fecha se almacena como NULL y la solicitud presenta la última consulta disponible para elección explícita. La validación de paciente, especialidad, integridad y destinatario se mantiene. La migración permite fechas nulas sin alterar datos existentes.

La fecha de las solicitudes nuevas se compara primero por día exacto. Si no hay una historia disponible, se recomienda la consulta más reciente con paquete íntegro del mismo paciente y especialidad; sin especialidad seleccionada, se consideran todas. La fecha alternativa puede quedar fuera del rango anterior de 30 días. El paciente debe elegirla para generar el envío. La selección vuelve a validar la recomendación y conserva el correo declarado.

Se incorpora el botón **Reenviar historia clínica** en el estado de entrega: mismo paquete y destinatario, espera de 60 segundos, máximo de tres reenvíos manuales y bloqueo mientras haya un envío activo. Conserva cada intento y vuelve a comprobar la integridad del paquete. Las pruebas cubren espera, solicitudes repetidas, paquetes retirados o alterados, límite de reenvíos, estados de la interfaz, sesión y CSRF.

El formulario inicia directamente la comparación con los datos extraídos de los PDF. Compara tipo/número de documento, nombre normalizado, fecha aproximada y especialidad. Una coincidencia encola el paquete automáticamente para el correo declarado; varias requieren elegir la atención; ninguna muestra el resultado sin crear un envío. No se generan códigos para solicitudes nuevas y no se registra una identidad verificada por el mero hecho de coincidir.

Por decisión del usuario, el envío SMTP de las solicitudes nuevas usa el correo declarado sin registro ni autorización previa. El modo demo tampoco limita estas entregas a test_recipient. Se mantienen los controles de coincidencia, integridad y destinatario de la solicitud, sin marcar identidad ni correo como verificados. SMTP está configurado y Gmail aceptó una prueba sin datos clínicos. Las simulaciones anteriores no se reenvían automáticamente.

Validación de este cambio: suite de dominio con destinatarios SMTP no registrados, dirección inválida, paciente desactivado y manipulación del destino en la cola. El transporte de prueba utiliza exclusivamente 127.0.0.1 con credenciales ficticias.

---

## Historial de decisiones previas

# Cambio de alcance: indexación automática desde PDF

La implementación vigente reemplaza el registro manual de pacientes y atenciones por extracción local del PDF. El panel solicita solo los documentos del paquete y la confirmación de completitud. Se agregó el lector CEDIM, el conteo de páginas, detección de inconsistencias y deduplicación/versionado. El primer ejemplo real fue reindexado y está disponible para pruebas locales.

En modo local, los códigos usan el correo declarado sin exigir registro previo del paciente. Esa excepción no aplica a SMTP externo: la extracción del PDF no verifica identidad ni autorización del destinatario. El envío externo sigue pendiente de configuración y validación de destinatario.

Se verificaron ambos ejemplos reales sin enviarlos, 12 comprobaciones del lector, 47 de dominio, 18 HTTP y 10 de carga. La guía vigente está en README.md.

---

## Registro histórico de la fase inicial (sustituido donde contradiga el cambio anterior)

# Seguimiento SDLC · versión de desarrollo 0.1

## Fases 1–3: alcance, requisitos y diseño

Objetivo: solicitud y entrega automática de la historia completa de una atención, después de verificar acceso al correo previamente registrado. No hay aprobación humana por solicitud. Un repositorio local simula el sistema clínico de origen.

Estructura: paciente → atenciones (fecha, especialidad, ingreso) → versión del paquete → uno o varios PDF originales. El paciente solicita solo historias clínicas; no hay selección independiente de resultados de estudios.

El formulario recoge tipo y número de identificación, nombre, fecha aproximada, especialidad (o «No recuerdo») y correo electrónico obligatorio. Este correo se guarda en la solicitud; el destino de verificación y entrega procede del registro confiable del paciente. La carga y asociación inicial del repositorio es administrativa.

## Fase 4: implementación

| Requisito | Implementación | Evidencia |
|---|---|---|
| RF-01 Repositorio simulado | Repository, panel interno, seed-demo | Prueba de carga multipart |
| RF-02 Paquetes disponibles | Publicación inicial completa y retiro | Integridad y retiro antes de envío |
| RF-03 Radicado | Application::createRequest | Solicitud en navegador y BD |
| RF-04 Verificación | Código al correo registrado | Código válido y acceso sin verificar |
| RF-05 Control de códigos | Hash, cifrado de cola, caducidad, intentos y reenvío | Código vencido, usado y agotado |
| RF-06 Búsqueda | Paciente + rango ±30 días + especialidad | Filtros y exclusión de otras personas |
| RF-07 Selección de atención | Pantalla de coincidencias | Recorrido con dos atenciones |
| RF-08 Sin coincidencias | Ajustar filtros sin cambiar identidad | Restricciones de filtros y estado |
| RF-09 Preparación | Verificación de archivos, hash y tamaño | Adjuntos completos, faltante y alterado |
| RF-10 Correo automático | MailWorker + PHPMailer + cola | MIME local con todos los PDF; SMTP externo pendiente |
| RF-11 Fallos y reintentos | Hasta tres intentos, estado incierto | Conexión SMTP local fallida y worker abandonado |
| RF-12 Estado | Pantalla vinculada a sesión verificada | Entrega simulada observada en navegador |
| RF-13 Trazabilidad | Eventos y trabajos de correo | Radicado y estado visibles en panel |

Se crearon la base MySQL, formularios, catálogo de especialidades, acceso administrativo, almacenamiento privado, verificador, procesador de correo y datos ficticios. La búsqueda no extrae ni analiza el contenido médico del PDF.

## Comprobaciones durante el desarrollo

- 40 comprobaciones de dominio e integración MySQL/PHPMailer.
- 18 comprobaciones HTTP de rutas, CSRF, sesiones y archivos privados.
- 7 comprobaciones de acceso administrativo y carga de PDF.
- Recorrido visual de escritorio: solicitud → código local → dos atenciones → entrega simulada → bandeja con código y correo de historia.
- Sintaxis PHP verificada.

Se corrigieron dos problemas específicos de Windows: clasificación de errores de socket como errores SMTP permanentes y permisos heredados al mover archivos temporales de Apache. La carga ahora copia los bytes a la carpeta privada, conservando sus permisos de destino.

La revisión visual móvil no pudo completarse por un tiempo de espera de la herramienta de navegador. El CSS contiene adaptación para pantallas pequeñas, pendiente de confirmar visualmente en la fase de pruebas.

## Ejemplos proporcionados

Se revisaron un paquete de cinco páginas y otro de once páginas. El segundo incluye reporte clínico, medicamentos y control, con numeraciones internas que reinician. Se mantiene el PDF íntegro. La fecha de impresión puede diferir de la fecha de atención; la búsqueda usa esta última. Se añadió Alergología al catálogo.

Inicialmente solo se probaron documentos ficticios. Por solicitud posterior se preparó el primer PDF real en el repositorio privado, con paciente deshabilitado y paquete pendiente de destinatario autorizado y SMTP; no se ha enviado. El segundo ejemplo evidencia la necesidad futura de diseñar el flujo de representantes de menores; no se supone que un código de correo pruebe esa representación.

## Fase 5: validación pendiente

- Prueba de aceptación con el usuario y revisión móvil.
- Configurar SMTP en el archivo privado y comprobar recepción efectiva en el destinatario autorizado.
- Casos ampliados de errores SMTP, concurrencia y recuperación operativa.
- Decidir proceso de registro y cambio confiable del correo y autorización de representantes antes de introducir datos reales.

## Fases 6–7

No se ha desplegado a Internet ni se ha instalado una tarea permanente. La cola local puede ejecutarse con el script de arranque. Para producción faltan configuración HTTPS, permisos de cuenta DB, almacenamiento fuera de raíces públicas, respaldos, política de retención, supervisión y la integración con el origen clínico real.

## Ventana de progreso del envío

Mientras una solicitud del paciente está en cola, enviándose o esperando reintento, se abre una ventana con fondo sombreado, encabezado celeste e indicador circular animado. Consulta el estado cada cinco segundos sin recargar la pantalla durante la espera; al salir de esos estados, carga el resultado de la solicitud. La ventana puede cerrarse sin cancelar el envío y respeta la preferencia de movimiento reducido. El círculo es indeterminado porque la cola no mide un porcentaje real. Sin soporte de diálogo se conserva la actualización anterior. Comprobados los tres mensajes y el cierre en navegador con datos sintéticos; sintaxis PHP y JavaScript validada, sin enviar correos.

## Progreso de carga de PDF

La carga individual y la masiva muestran una ventana con fondo sombreado y un círculo con el porcentaje real de transferencia. Al terminar la subida, el indicador pasa a indeterminado mientras PHP lee, valida y guarda los PDF. Las peticiones de carga con Accept: application/json reciben el destino de navegación o el error; el formulario tradicional mantiene sus redirecciones. Se conservan CSRF, autenticación, archivos seleccionados tras errores y el informe de carga masiva. No se reintenta automáticamente una petición cuyo resultado se desconoce. Validación: node tests/upload-progress.cjs; sintaxis PHP/JS; comprobación HTTP de CSRF y acceso protegido en JSON y HTML; revisión visual al 56% con transferencia simulada, sin documentos ni correos reales.

## Límites de carga en Docker

El contenedor carga docker/uploads.ini: 12 MiB por PDF, post_max_size de 34 MiB (margen para los formularios de lotes de hasta 32 MiB), 20 archivos y 180 segundos de procesamiento. Los avisos PHP se registran sin contaminar las respuestas JSON. Antes de iniciar la aplicación se rechazan con HTTP 413 las peticiones que exceden post_max_size; se reconoce una carga JSON por su tipo multipart incluso cuando PHP descarta POST y FILES. El modal diferencia errores 413, sesión y timeout del proxy. Se reprodujo una petición multipart de 2290 bytes contra PHP con límite de 1 KiB y se verificó la respuesta JSON 413. Pruebas: php tests/upload-http.php y node tests/upload-progress.cjs. El cambio de límites requiere reconstruir la imagen en Dokploy. La causa concreta de una incidencia en el VPS debe confirmarse con tamaño de archivos y registros del servidor.

Corrección del destino de subida: los formularios incluyen un input oculto llamado action, que oculta la propiedad form.action en el DOM. La ventana de progreso utilizaba esa propiedad como URL y podía enviar a [object HTMLInputElement], recibiendo una respuesta HTML inesperada incluso con PDF pequeños. Ahora obtiene el atributo mediante getAttribute('action') y usa la URL actual cuando no existe. La regresión simula el campo oculto y comprueba el destino POST; también se verificó en navegador con un formulario sintético y transporte simulado. La captura del caso muestra dos PDF de 1 MB en total, dentro de los límites existentes, por lo que el ajuste de límites es independiente de este fallo.

## Modal de resultado de carga

Al completar una carga se marca en sesión la apertura del resultado para la siguiente visita al panel. La carga masiva conserva resumen y filas por PDF; la individual muestra su confirmación y cantidad de archivos. El indicador de apertura se consume al renderizar para no abrir otra vez el modal en cada recarga. El botón Ver resultado de la última carga permite consultarlo de nuevo. Cierra con el botón, Escape o clic en el fondo, y devuelve el foco al botón de apertura. Sin soporte JavaScript/dialog el informe permanece en la página. Verificados en navegador con datos sintéticos: apertura automática, informe mixto con un archivo guardado y otro con error, cierre por teclado, reapertura y carga individual; sintaxis PHP y JavaScript comprobada.

## Análisis mensual de atenciones

Acceso interno → Análisis mensual muestra un pastel de porcentajes por especialidad y una tabla ordenada por cantidad, con selector de mes y año. Se basa en la fecha de atención y especialidad ya extraídas de los PDF; cuenta una vez cada registro de atención con archivos asociados, sin multiplicarlo por anexos ni versiones. Incluye historias retiradas como datos históricos. El universo son las atenciones documentadas, no citas solicitadas, canceladas o pendientes. Por defecto abre el mes más reciente con PDF; muestra estado vacío si el mes elegido no tiene datos. Los porcentajes se redondean a un decimal. Funciona sin JavaScript y no consulta servicios externos. Pruebas: php tests/analytics.php (tablas temporales aisladas, porcentajes, deduplicación, límites mensuales/anuales, meses inválidos, casos vacío y especialidad única, acceso HTTP protegido); revisión visual con datos ficticios. No requiere migración de la base.

## Totales de solicitudes y envíos

Análisis mensual incorpora tarjetas de día, mes y año según una fecha de referencia (hoy por defecto, hora de Colombia). Cada período completo cuenta solicitudes por created_at sin filtrar estado, envíos reales de historias por sent_at y status=sent, y simulaciones separadas. Los reenvíos exitosos cuentan individualmente; no se cuentan códigos OTP, trabajos pendientes, fallidos ni cantidad de anexos. El filtro conserva el mes del gráfico de especialidades y viceversa. Pruebas ampliadas en php tests/analytics.php: límites de día/mes/año, bisiestos, períodos vacíos, reenvíos, separación de simulaciones y exclusión de OTP/fallos. Revisión visual con cifras ficticias. No requiere migración.

## Canal de WhatsApp y órdenes médicas

**Fase 1 · Alcance.** El paciente inicia la solicitud desde WhatsApp. El bot responde con un enlace al formulario; el sistema busca la historia en los PDF cargados y la envía al chat si existe. Si no existe, registra un radicado, informa una espera estimada y la envía sola cuando la persona encargada carga el PDF. Además, el bot detecta las órdenes del especialista y orienta al paciente sobre qué hacer con cada una. Fuera de alcance: verificar la identidad por el número de teléfono, conversación libre e interpretación clínica del contenido.

**Fase 2 · Requisitos.**

| Requisito | Descripción |
|---|---|
| RF-14 Entrada por WhatsApp | Un mensaje con intención de historia (*historia*, *clínica*, *HC*, *epicrisis*, *copia*) recibe un enlace personal; otro mensaje recibe el menú |
| RF-15 Enlace seguro | Vence en 6 h, se usa una sola vez, se guarda como hash y vincula el número a la solicitud |
| RF-16 Historia encontrada | Se envían por el chat el radicado y cada PDF como documento, además del correo |
| RF-17 Radicado | Sin historia, se informa el radicado por WhatsApp con un tiempo estimado de 24 h (`whatsapp_estimated_hours`) y la solicitud queda en Solicitudes y envíos |
| RF-18 Envío diferido | Al cargar el PDF del paciente, las solicitudes pendientes de WhatsApp de los últimos 30 días se reprocesan y se envían solas |
| RF-19 Órdenes médicas | Se detectan fórmula, laboratorio, imágenes, procedimiento, terapias, remisión e incapacidad, con una guía por tipo y la imagen de su página |
| RF-20 Ventana de 24 h | Fuera de la ventana el mensaje espera sin perder lo enviado y sale cuando el paciente vuelve a escribir; opcionalmente se avisa con una plantilla aprobada |
| RNF | Firma `X-Hub-Signature-256`, deduplicación de reintentos de Meta, límite de 20 mensajes por número/hora, cuerpos cifrados en la cola y modo local sin envíos |

**Fase 3 · Diseño.** Webhook `public/whatsapp.php` → `WhatsApp::receive` → tablas `whatsapp_inbound` (deduplicación), `whatsapp_links` y `whatsapp_outbox`, procesadas por `bin/worker.php`. La búsqueda reutiliza `Application::processSearch`, sin un flujo paralelo. Las órdenes se detectan al importar (`MedicalOrders::detect` sobre el texto de cada página, solo por títulos al inicio de línea para no confundirlas con menciones en notas) y se guardan en `clinical_orders` (archivo, tipo, página). La imagen de la página se genera en el momento del envío con Imagick, pdftoppm o Ghostscript, y el PNG temporal se borra después; sin conversor, la guía indica documento y página. `Repository::importPackage` llama a `Application::resolveWaitingRequests` después de confirmar la transacción: un fallo al reprocesar no deshace la carga. Estado `waiting_window` en `whatsapp_outbox`, con vencimiento a los 7 días.

**Fase 4 · Implementación.** Nuevos: `src/WhatsApp.php`, `src/WhatsAppTransportError.php`, `src/MedicalOrders.php`, `src/PdfPageImage.php`, `public/whatsapp.php`, `bin/detect-orders.php` (órdenes en historias ya cargadas). Modificados: `Application` (vínculo del enlace, envío diferido), `Repository` y `ClinicalPdfReader` (órdenes), `schema.sql` y `setup.php` (tablas y columna `requests.whatsapp_phone`), `worker.php`, configuración, etiquetas de estado, `Dockerfile` (`poppler-utils`) y README. Los textos de guía son administrativos y editables en `MedicalOrders::TYPES`; no sustituyen la indicación del especialista.

**Fase 5 · Validación.** `php tests/whatsapp.php`: 32 comprobaciones en base temporal y transporte local (firma, menú, enlace, reintentos de webhook, enlace vencido y reutilizado, historia encontrada y no encontrada, envío diferido al cargar el PDF, órdenes con tildes y falsos positivos en notas, guía e imagen por página, no duplicación al recargar, espera, liberación y vencimiento de la ventana de 24 h). Regresión: `tests/run.php` 155, `tests/pdf-reader.php` 17, `tests/analytics.php` correcto. Migración aplicada en la base local; `bin/detect-orders.php` revisó 10 archivos de demostración, sin órdenes. Sin envíos a Meta ni datos reales.

**Planificación.** Seguimiento en Jira: https://citaya.atlassian.net, proyecto CitaYa (clave SCRUM), épica SCRUM-5. Requisitos RF-14 a RF-20 = SCRUM-6 a SCRUM-12 (finalizados). Pendientes = SCRUM-13 a SCRUM-18. SCRUM-19 (tiempo estimado de 24 h en el radicado) se corrigió durante la planificación: faltaba en el mensaje.

**Pendiente antes de producción.**
- Calibrar los títulos de órdenes con PDF reales anonimizados de CEDIM/Indigo; solo se probaron PDF sintéticos.
- Probar en Meta: webhook, envío de documentos e imágenes, y la plantilla de aviso (requiere aprobación).
- Verificar la conversión de páginas a imagen en el contenedor; en el equipo local no hay conversor instalado.
- Exponer en `docker-compose.yml` las variables `WHATSAPP_*` y `PUBLIC_URL`; hoy solo se configuran con `config/local.php`, que no entra en la imagen.
- Decidir la gestión cuando la fecha indicada por el paciente no coincide con la del PDF: hoy la solicitud queda pendiente para revisión manual.
- Validar con la política de privacidad (`docs/politica-privacidad.md`) el envío de documentos clínicos por WhatsApp, porque el número no prueba la identidad del titular.

## Transporte de WhatsApp por Zernio

**Fase 1 · Alcance.** Enviar y recibir los mensajes del bot a través de [Zernio](https://docs.zernio.com/platforms/whatsapp), una API unificada sobre la Cloud API de Meta, como alternativa a la cuenta de Meta restringida (SCRUM-20). El flujo del bot no cambia: menú, enlace, historia, radicado, «No registra», órdenes y ventana de 24 h. Siguen disponibles los transportes `local`, `cloud` y `qr`. Fuera de alcance: estados de entrega (`message.delivered`/`read`), mensajes de voz y conexión del número desde CitaYa (se hace en el panel de Zernio). Jira: SCRUM-29, dentro de la épica SCRUM-5. La investigación previa está en SCRUM-22.

**Fase 2 · Requisitos.**

| Requisito | Descripción |
|---|---|
| RF-27 Transporte Zernio | Con `whatsapp_transport = zernio` los mensajes entran por el webhook de Zernio y salen por su API, con el mismo comportamiento que con Meta |
| RNF | Firma `X-Zernio-Signature` obligatoria, respuesta del webhook en menos de 5 s (solo registra y encola), deduplicación por `platformMessageId`, `Idempotency-Key` por mensaje, solo la cuenta configurada, credenciales únicamente en `config/local.php` |

**Fase 3 · Diseño.**
- Para responder, Zernio exige la conversación, no el número. La tabla `whatsapp_conversations` (teléfono → `conversationId`) se llena con cada `message.received`.
- `public/whatsapp.php` deriva a `WhatsApp::receiveZernio` cuando llega la cabecera de Zernio o el transporte es `zernio`. Solo atiende mensajes entrantes de WhatsApp de `zernio_account_id`, e ignora usuarios sin teléfono (solo BSUID), estados y `webhook.test`. La lógica común (deduplicación, límite por número, liberar la ventana, menú o enlace) quedó en `handleInbound`, compartida con Meta.
- Envío: `POST /v1/inbox/conversations/{id}/messages`. El texto va en JSON. El PDF y la imagen de la orden van en multipart (`attachment`, con el nombre del archivo que ve el paciente).
- Clave de idempotencia: `citaya-<huella de app_key>-<id de cola>-<índice>`, para que otra instalación con la misma cuenta no choque.
- Errores: Meta 131047 → `waiting_window`. 409, 429 y 5xx → reintento. Otros 4xx → fallo. Sin conversación previa → `zernio_no_conversation`.
- Ventana cerrada: la plantilla `whatsapp_template_ready` se envía con `POST /v1/inbox/conversations` (`templateName`, `templateParams` = radicado).
- `bin/zernio.php`: `accounts` lista los números conectados; `webhook` registra `<public_url>/whatsapp.php` (solo `message.received` y la cuenta configurada) y lanza la prueba de entrega.

**Fase 4 · Implementación.**
- Nuevos: `bin/zernio.php` y `tests/whatsapp-zernio.php`.
- Modificados: `src/WhatsApp.php`, `public/whatsapp.php`, `config/default.php` (`zernio_api_url`, `zernio_api_key`, `zernio_account_id`, `zernio_webhook_secret`), `config/local.example.php`, `database/schema.sql` (tabla `whatsapp_conversations`) y README («Conectar con Zernio»).
- Se usó la especificación OpenAPI oficial de Zernio (v1.244.2).

**Fase 5 · Validación.**
- `php tests/whatsapp-zernio.php`: 22 comprobaciones contra una API de Zernio simulada (servidor PHP local), en base temporal. Cubre:
  - firma válida, alterada y sin secreto;
  - registro de la conversación y deduplicación de reintentos;
  - eventos ignorados: otra cuenta, mensajes salientes, otras redes, estados, BSUID y `webhook.test`;
  - menú con token, cuenta y clave de idempotencia;
  - historia completa con el PDF íntegro y su nombre, y claves distintas por mensaje;
  - reintento ante un 500, fallo definitivo ante un 400 (con borrado del texto cifrado);
  - ventana de 24 h con plantilla y liberación cuando el paciente vuelve a escribir;
  - número sin conversación previa, token inválido y configuración incompleta.
- Regresión: `whatsapp` 32, `whatsapp-qr` 8, `requests-admin` 25, `run` 155, `pdf-reader` 17 y `analytics`, todas correctas.
- Migración aplicada en `citaya_v3` (tabla creada).
- HTTP sobre Apache local: el webhook responde 401 a firmas inválidas y cuando no hay secreto configurado.
- Sin envíos a Zernio ni a WhatsApp reales.

**Fases 6–7 · Pendiente para el despliegue.**
- El titular debe crear la cuenta en Zernio, conectar el número y configurar `config/local.php`. Después: `php bin/zernio.php accounts`, `php bin/setup.php` y `php bin/zernio.php webhook` con un túnel HTTPS.
- Prueba real de punta a punta con datos ficticios (SCRUM-14).
- Confirmar al conectar si la restricción de la cuenta de Meta (SCRUM-20) también afecta la WABA que se conecte por Zernio.
- Aprobar la plantilla «historia lista» en esa WABA (SCRUM-15).
- Docker/Dokploy preparado (SCRUM-16, sin desplegar todavía):
  - `docker/configure.php` lee `WHATSAPP_TRANSPORT`, `ZERNIO_*`, `WHATSAPP_TEMPLATE_*` y `PUBLIC_URL`.
  - `docker-compose.yml` las pasa a `app` y `worker`, con `PUBLIC_URL=https://${DOMAIN}`.
  - Las rutas de Traefik se renombraron de `citayav2` a `citayav3` para no chocar con V2 en el mismo servidor.
  - `entrypoint.sh` registra el webhook al arrancar si el transporte es `zernio`.
  - Se validó la generación de la configuración con variables de ejemplo, en una copia aparte, y la estructura del compose.
- Configuración local (2026-10-08):
  - Número «Citas Ya» conectado a Zernio en modo coexistencia con la app WhatsApp Business, en el porfolio de Meta «Cita Ya», sin verificación del negocio.
  - `whatsapp_transport = zernio` en `config/local.php`.
  - Procesador propio de V3 en ejecución, separado del de V2.
  - Falta un túnel HTTPS para registrar el webhook local.

## Gestión de solicitudes por la persona encargada

**Fase 1 · Alcance.** Completar el flujo «la persona encargada responde el radicado». Ver las solicitudes pendientes con su plazo de 24 h y resolverlas desde el panel: asignar una historia ya cargada, cargar el PDF desde la solicitud o responder «No registra». Los mensajes al paciente se manejan con plantillas editables. Planificación en Jira: épica SCRUM-23.

**Fase 2 · Requisitos y decisiones del titular.**

| Requisito | Jira | Descripción |
|---|---|---|
| RF-22 | SCRUM-24 | Pestaña única «Solicitudes» (reemplaza «Solicitudes y envíos»), filtro Pendientes / Todas, contador y plazo: a tiempo, por vencer (menos de 6 h), vencida |
| RF-23 | SCRUM-25 | Asignar una atención cargada del mismo documento aunque la fecha no coincida; solo si el nombre coincide. Resuelve SCRUM-17 |
| RF-24 | SCRUM-26 | Cargar el PDF desde la solicitud; si documento y nombre coinciden, se asigna y se envía |
| RF-25 | SCRUM-27 | «No registra»: si el nombre no coincide con el documento o el paciente no existe, se pide verificar el número de documento. Aviso por WhatsApp (si aplica) y correo |
| RF-26 | SCRUM-28 | Plantillas de mensajes editables, con variables permitidas, vista previa y opción de restaurar |

Por ahora, «No registra» es el único motivo de cierre sin historia.

**Fase 3 · Diseño.**
- Columnas `requests.resolved_by`, `resolved_at` y `resolution`, y estado `not_registered`.
- Tabla `message_templates`; los textos predeterminados viven en `MessageTemplates::DEFINITIONS`.
- `Application::assignEncounter()` valida documento, nombre normalizado e integridad del paquete. Reutiliza `confirm()`, que encola correo y WhatsApp. Si el envío no se prepara, la solicitud vuelve a pendiente.
- `Application::markNotRegistered()` encola WhatsApp (`not_registered`) y correo (nuevo trabajo `notice`, solo texto, sin adjuntos y sin exigir paciente en el repositorio).
- Pendiente = búsqueda directa en `no_matches`, `unavailable` o `not_eligible`, o caso reabierto. Plazo = creación + `whatsapp_estimated_hours`.
- Corrección: el vencimiento de la sesión del paciente ya no marca como `expired` las solicitudes con radicado que esperan gestión.

**Fase 4 · Implementación.**
- Nuevos: `src/MessageTemplates.php`, `templates/templates.php`, `tests/requests-admin.php`.
- Modificados: `Application`, `WhatsApp` (textos desde plantillas), `MailWorker` (tipo `notice`), `AdminDeliveries` (pendientes, orden por antigüedad, contador, quién respondió), `public/index.php` (acciones `assign-encounter`, `not-registered`, `request-upload`, `save-template` y `reset-template`), plantillas `admin`, `deliveries`, `delivery` y `layout`, `app.css`, `schema.sql` y `setup.php`.
- Todas las acciones exigen administrador y CSRF, son transaccionales y quedan auditadas en `events`.

**Fase 5 · Validación.**
- `php tests/requests-admin.php`: 25 comprobaciones en base temporal. Cubre orden de pendientes, plantilla con 24 h, candidatas con nombre igual y distinto, rechazo por nombre, asignación con auditoría, envío por correo y WhatsApp, solicitud web solo por correo, doble gestión, paquete alterado que vuelve a pendiente, «No registra» con aviso sin adjuntos ni asteriscos (también para documentos inexistentes), validación de variables, plantilla personalizada y restaurada, y versión de correo.
- Regresión: `run` 155, `whatsapp` 32, `whatsapp-qr` 8, `pdf-reader` 17, `analytics`, `upload-http` y `resend-ui` 19, todas correctas.
- `tests/http.php` y `tests/admin-http.php` apuntan al proyecto CitaYaV2.
- `tests/upload.php` exige correo local; la configuración actual usa SMTP.
- Prueba HTTP con sesión administrativa sobre CitaYaV3: Solicitudes (Pendientes / Todas), Plantillas, Historial, Repositorio y detalle cargan sin errores. Revisión visual con capturas.
- Migración aplicada en la base local: 6 solicitudes pendientes de 10, todas vencidas (septiembre de 2026).

**Pendiente:** aceptación del titular en uso real, y decidir si las solicitudes antiguas sin documento conservado se responden con «No registra».

## Carga de historias clínicas más clara (RF-28)

**Fase 1 · Alcance.** La pantalla de carga no explicaba qué hace el sistema con los PDF, y el resultado solo decía cuántos se recibieron. Se agrega la explicación previa y un resultado detallado. Las reglas de lectura y de validación no cambian. Jira: SCRUM-34, dentro de la épica SCRUM-23.

**Fase 2 · Requisitos.**
- **RF-28.** Antes de cargar, se explican en cinco pasos: lectura, identificación de la atención, verificación, detección de órdenes, y publicación y entrega automática a las solicitudes pendientes.
- El resultado individual muestra:
  - lo leído: paciente, documento, ingreso, fecha, especialidad, PDF, páginas y versión;
  - lo hecho: si la historia es nueva, reemplaza a una versión anterior o ya estaba registrada, y qué órdenes se detectaron;
  - a qué solicitudes pendientes se envió la historia (con su radicado), cuáles quedaron por revisar, o que no había ninguna.
- La carga masiva agrega, por cada atención guardada, la especialidad, el ingreso, las páginas, las órdenes y los envíos.

**Fase 3 · Diseño.**
- `Application::resolveWaitingRequests` devuelve `[radicado, enviada]` por cada solicitud reprocesada.
- `Repository` recuerda si la última importación reutilizó un paquete y qué resolvió. `Repository::summary(paquete)` arma el resumen.
- La sesión guarda el resumen en lugar del número de PDF. Si encuentra un resultado anterior que solo tiene el número, lo sigue mostrando.
- El bloque reutilizable `templates/upload-summary.php` muestra el resumen.

**Fase 4 · Implementación.**
- Nuevo: `templates/upload-summary.php`.
- Modificados:
  - `src/Application.php`, `src/Repository.php` y `src/BulkImport.php`;
  - `public/index.php` (acción `upload`);
  - las plantillas `upload-center`, `single-upload`, `upload-result`, `bulk-report` y `admin` (singular «página»);
  - `public/assets/app.css` (clases `upload-flow` y `load-*`, responsive);
  - `tests/whatsapp.php`.

**Fase 5 · Validación.**
- `php tests/whatsapp.php`: 36 comprobaciones, 4 nuevas. El resumen trae el paciente, el ingreso y los archivos, las órdenes detectadas y la solicitud pendiente enviada. Recargar los mismos archivos se informa como ya registrada, sin nuevos envíos.
- Regresión: `run` 155, `whatsapp-qr` 8, `whatsapp-zernio` 22, `requests-admin` 25, `pdf-reader` 17, `analytics` y `upload-http`, todas correctas.
- Prueba en el navegador sobre V3 local: se cargó un PDF ficticio (CC 1099000333) y el modal mostró los datos leídos, las órdenes detectadas (fórmula médica e imágenes diagnósticas) y el aviso de que no había solicitudes pendientes.

## Número de WhatsApp en el formulario (RF-29)

**Fase 1 · Alcance.** El formulario no mostraba ni pedía el número de WhatsApp. Si el paciente llega desde el bot, el número ya está vinculado al enlace; si llega por la web, la historia solo salía por correo. Jira: SCRUM-35, dentro de la épica SCRUM-5. Decisión del titular: mostrarlo si viene del bot y pedirlo, opcional y confirmado, si viene de la web. No se agregan otros campos.

**Fase 2 · Requisitos.**
- **RF-29.**
  - Desde el enlace del bot: se muestra el número vinculado, enmascarado.
  - Desde la web: campo opcional. Un celular de 10 dígitos que empieza por 3 recibe el indicativo 57; un número inválido muestra un error.
  - Nada se envía por WhatsApp a un número escrito en la web hasta confirmarlo con «CONFIRMAR código» desde ese chat.
  - Al confirmar:
    - si la historia ya salió por correo, se envía también por WhatsApp;
    - si está pendiente, se envía el radicado, y la historia llega sola al cargarse el PDF;
    - si hay varias atenciones, el bot indica que se elija en la página.
- **RNF.** El código es de 6 caracteres sin ambigüedades, de un solo uso, vence según `whatsapp_link_seconds` (6 h) y se guarda como hash. Límite de 10 intentos de confirmación por número y hora.

**Fase 3 · Diseño.**
- Columnas `requests.whatsapp_claimed`, `whatsapp_code_hash` y `whatsapp_code_expires`.
- Métodos en `WhatsApp`: `normalizePhone`, `claimNumber` y `confirmNumber`. Este último lo llama `handleInbound` cuando el texto trae «confirmar XXXXXX», antes del menú.
- Al confirmar, el chat que envía el código queda como `whatsapp_phone`, que funciona también con contactos `@lid` del transporte QR.
- La página del radicado muestra el código y un botón `wa.me` hacia `whatsapp_bot_number`. Nueva variable `WHATSAPP_BOT_NUMBER` en Docker.

**Fase 4 · Implementación.** Modificados: `src/WhatsApp.php`, `public/index.php` (acción `request`), `templates/layout.php` (formulario y página de estado), `public/assets/app.css`, `database/schema.sql`, `bin/setup.php`, `config/default.php`, `docker/configure.php`, `docker-compose.yml` y `tests/whatsapp.php`. Migración aplicada en `citaya_v3`.

**Fase 5 · Validación.**
- `php tests/whatsapp.php`: 44 comprobaciones, 8 nuevas. Cubren:
  - normalización y rechazo de números inválidos;
  - que no se envíe nada antes de confirmar y que el código se guarde como hash;
  - código incorrecto, reutilizado o vencido;
  - confirmación con historia ya enviada, que sale por WhatsApp;
  - confirmación con solicitud pendiente, que envía el radicado.
- Regresión: `run` 155, `whatsapp-qr` 8, `whatsapp-zernio` 22, `requests-admin` 25, `pdf-reader` 17 y `analytics`, todas correctas.
- Revisión visual del formulario web en V3 local.
- Pendiente: probarlo con WhatsApp real por QR (dentro de SCRUM-32).

## Bot QR con Baileys y prueba real de punta a punta

**Contexto.** Durante la prueba real con el bot QR (SCRUM-32, radicado HC-261009-E3FFA0F9F1) aparecieron varios fallos. Se registraron en SCRUM-21 y SCRUM-36:
- Algunos mensajes llegaban sin identificador y los contactos `@lid` traían un identificador interno en lugar del número.
- Con whatsapp-web.js 1.34.7, que era la versión más reciente, **ningún adjunto se podía enviar** («Data passed to getter must include an id property»).
- Además, Gmail rechaza las credenciales SMTP locales.

**Decisión del titular.** Cambiar el servicio QR a Baileys (opción A de SCRUM-36).

**Diseño e implementación.**
- `tools/whatsapp-qr/server.mjs` (ESM) usa `@whiskeysockets/baileys` 7.0.0-rc14 y `pino`, y reemplaza a `server.cjs` y a whatsapp-web.js. Ya no hay Chrome oculto y npm no reporta vulnerabilidades.
- El contrato con CitaYa no cambia: webhook con firma de Meta y `POST /send` firmado. No hay cambios en PHP.
- El número real se toma de `remoteJid`, `remoteJidAlt` o del mapeo LID→número. Las respuestas van al chat de origen (`.session/chats.json`).
- Se reconecta solo; si la sesión se cierra desde el teléfono, pide un QR nuevo.
- `bin/start-whatsapp-qr.ps1` arranca el servicio sin duplicarlo.
- `bin/install-worker-task.ps1` crea su propia tarea de Windows, «CitaYaV3 - Procesador y WhatsApp QR». Antes usaba el mismo nombre que la de V2 y la habría reemplazado. Esta instalación la ejecuta el titular.

**Validación.**
- Prueba real: «hola» recibe el menú, «historia» recibe el enlace, el formulario muestra el número real enmascarado, y la historia llega por WhatsApp con texto y **PDF** (confirmado por el titular el 2026-10-09).
- `tests/whatsapp-qr.php`: 8 comprobaciones correctas.

**Pendiente.**
- Correo SMTP: el titular debe renovar la contraseña de aplicación de Gmail en `config/local.php`.
- Llevar el servicio QR al VPS como contenedor.

## Automatización con n8n y WhatsApp por QR (épica SCRUM-37)

Plan del titular «Plan secuencial CitaYa n8n WhatsApp QR» (10 de octubre de 2026). El flujo es WhatsApp ↔ servicio QR ↔ n8n ↔ CitaYa, en 7 fases: SCRUM-38 a SCRUM-44.

### Fase 1 · Dónde se ejecuta cada componente (SCRUM-38)

Decisiones del titular: todo corre en el VPS, y las excepciones las atiende el titular en su WhatsApp personal.

| Componente | Ubicación | Acceso |
|---|---|---|
| n8n | VPS, proyecto Dokploy «CitaYa-n8n», `https://n8n.broa18.online` (n8n 2.41.6) | Webhooks públicos protegidos con firma HMAC |
| CitaYa V3 | VPS, `https://citaya-v3.broa18.online` | Panel y formulario públicos; interfaz privada `/n8n.php` con firma HMAC (`N8N_SECRET`) |
| Servicio QR (Baileys) | VPS, servicio `whatsapp-qr` del mismo compose de CitaYa V3 | **Sin puerto ni dominio públicos**; solo en la red interna del compose (`http://whatsapp-qr:3218`) |

Rutas autenticadas:
- **Entrada:** el QR reenvía cada mensaje al webhook de n8n (`https://n8n.broa18.online/webhook/citaya-whatsapp`), firmado con `WA_SECRET`, que comparten el QR y n8n.
- **n8n → CitaYa:** HTTPS a `/n8n.php` con la cabecera `X-CitaYa-Signature` (HMAC-SHA256 del cuerpo con `N8N_SECRET`).
- **Salida:** n8n pide los envíos a CitaYa, y CitaYa los hace llegar al QR por la red interna, firmados. Así el QR nunca queda expuesto y CitaYa sigue autorizando cada envío.
- **CitaYa → n8n (eventos):** HTTPS al webhook de eventos de n8n, firmado con `N8N_SECRET`.
- **QR inicial:** se escanea desde los registros del contenedor en Dokploy, que lo imprimen en texto.

Ruta de regreso: con `WA_WEBHOOK_URL` apuntando al webhook de CitaYa (`/whatsapp.php`), el bot vuelve a funcionar sin n8n. Responsable de las excepciones: el titular, en su WhatsApp personal (`ADMIN_WHATSAPP`).

### Fase 2 · Interfaz privada de CitaYa para n8n (SCRUM-39)

**Diseño.**
- `public/n8n.php` recibe peticiones POST en JSON con `action` y `ts`, firmadas con `X-CitaYa-Signature: sha256=HMAC(cuerpo, n8n_secret)`. Rechaza las peticiones sin firma válida y las que tienen más de 5 minutos.
- `src/N8nApi.php` implementa las operaciones:

| Acción | Qué hace |
|---|---|
| `inbound.register` | Registra el mensaje recibido antes de procesarlo; `new=false` si es un reintento. Aplica el límite de 20 por hora y libera los mensajes que esperaban la ventana |
| `link.create` | Crea el enlace personal de un solo uso (6 h) para ese chat; solo guarda el hash |
| `code.confirm` | Valida «CONFIRMAR código», vincula el chat y encola la historia o el radicado. Responde `next`: history, notice, select o none |
| `requests.list` | Consulta de estado: solo las solicitudes vinculadas a ese chat, con estados en lenguaje claro |
| `template.render` | Textos editables del panel (`menu` y `form_link`) |
| `message.send` | Respuesta de texto del bot. Solo a chats que escribieron en las últimas 24 h o a `admin_whatsapp`; la clave `key` evita duplicados |
| `delivery.pending` / `delivery.send` | Mensajes listos para salir y envío de uno concreto por el QR. CitaYa arma y autoriza el contenido y conserva el progreso por archivo |

- **Eventos hacia n8n** (`src/Automation.php`, tabla `automation_events`). Eventos: `historia.disponible`, `solicitud.pendiente`, `solicitud.no_registra`, `whatsapp.mensaje` y `envio.fallido` (WhatsApp y correo).
  - Solo llevan el radicado y los identificadores, sin nombres ni documentos.
  - El procesador los entrega a `n8n_events_url` con firma y los reintenta con espera creciente (hasta 6 intentos).
  - Sin URL configurada no se registran.
- `whatsapp_dispatch` (worker | n8n) define quién procesa la cola de WhatsApp, para que haya un solo responsable (se usa en la fase 5).
- Refactor de `WhatsApp`: `createFormLink`, `confirmCode`, `queueText` pública y `tick(jobId)`. El bot propio de CitaYa usa las mismas funciones.
- Configuración nueva: `n8n_secret`, `n8n_events_url`, `whatsapp_dispatch` y `admin_whatsapp`. En Docker: `N8N_SECRET`, `N8N_EVENTS_URL`, `WHATSAPP_DISPATCH`, `ADMIN_WHATSAPP`, `WA_SECRET` y `WHATSAPP_QR_URL`.

**Validación.**
- `php tests/n8n-api.php`: 23 comprobaciones contra un QR y un n8n simulados. Cubren:
  - firma, acciones y números inválidos, y duplicados de recepción;
  - plantillas y respuestas: destinatarios permitidos, clave idempotente y aviso al responsable;
  - envío de un mensaje concreto sin tocar otros;
  - enlace y consulta de estado;
  - entrega de la historia con el PDF íntegro, sin repetirla;
  - código inválido y válido;
  - eventos sin datos personales, su entrega firmada, el reintento y el modo sin n8n.
- Esta prueba encontró y corrigió un error: `tick(jobId)` procesaba el siguiente mensaje de la cola en lugar del indicado.
- Regresión: `whatsapp` 44, `run` 155, `whatsapp-qr` 8, `whatsapp-zernio` 22, `requests-admin` 25, `pdf-reader` 17 y `analytics`, todas correctas.
- Migración aplicada en `citaya_v3`. `/n8n.php` responde 401 sin firma válida y 405 con GET.

### Fase 3 · Entrada del QR hacia n8n (SCRUM-40)

**Diseño e implementación.**
- **Destino configurable:** el servicio QR reenvía cada mensaje a `WA_WEBHOOK_URL`, firmado con `X-Hub-Signature-256` (HMAC con `WA_SECRET`). En el VPS apuntará al webhook de n8n. El valor por defecto es `/whatsapp.php` de CitaYa, que sirve como ruta de regreso. La firma la valida n8n como primer paso del flujo (fase 4).
- **Guardar antes de procesar:** `tools/whatsapp-qr/inbox.mjs` es una bandeja persistente en `.session/inbox.json`, con escritura atómica. Cada mensaje se guarda, una sola vez por id, antes de entregarse.
- **Recuperación:** si el webhook no responde, se reintenta cada 10 s, 20 s, 40 s… hasta 10 minutos entre intentos, durante 24 h, también después de reiniciar el servicio. `/status` informa los pendientes. Antes había un solo intento.
- **Contenedor:** `tools/whatsapp-qr/Dockerfile` (Node 22 alpine, usuario sin privilegios) y el servicio `whatsapp-qr` en `docker-compose.yml`.
  - Solo red interna, sin puertos ni dominio.
  - Volumen `whatsapp_session` para la sesión, la bandeja y los chats.
  - Con `WA_PRINT_QR=1` el QR se imprime en los registros.
  - La aplicación lo usa en `http://whatsapp-qr:3218`, y las variables nuevas pasan a `app` y `worker`.

**Validación.**
- `node --test tools/whatsapp-qr/inbox.test.mjs`: 5 pruebas correctas (guarda sin duplicar, reintenta con espera creciente, sobrevive a un reinicio, descarta a las 24 h y tolera errores de red).
- El servicio local se reinició con la bandeja y se reconectó solo.
- `npm ci --omit=dev` es válido.
- La imagen Docker no se construyó en este equipo porque no tiene Docker; se verifica al desplegar (fase 7).

### Fase 5 · Salida coordinada por n8n (SCRUM-42)

**Diseño e implementación.**
- **Un solo responsable de la cola de WhatsApp.** Con `WHATSAPP_DISPATCH=n8n`, `bin/worker.php` ya no envía WhatsApp. Sigue entregando correos y eventos.
- **Flujo «CitaYa · Eventos»** (`n8n/citaya-events.js`, webhook `citaya-eventos`): valida `X-CitaYa-Signature` y la antigüedad del evento. Para historia.disponible, solicitud.pendiente, solicitud.no_registra y whatsapp.mensaje pide `delivery.send` con el `job_id`.
  - CitaYa arma y autoriza el contenido y lo pasa al QR por la red interna: **los PDF nunca pasan por n8n**.
  - Se conserva el progreso por archivo, y un evento repetido no reenvía nada.
  - Si CitaYa no responde, devuelve 503 y CitaYa reintenta el evento.
- **Flujo «CitaYa · Recuperación de envíos»** (cada 5 minutos): `delivery.pending` → `delivery.send`, para lo que no salió porque n8n o el QR estaban caídos.
- **Registros de n8n:** no se guardan los datos de las ejecuciones correctas. Los fallos solo registran el radicado y el código de error.
- Eventos con espera de hasta 120 s, porque n8n responde después de pedir el envío.
- Se corrigió un error de esta fase: un comentario anulaba `CURLOPT_POSTFIELDS` en `Automation::tick`.

**Validación.** `node --test n8n/citaya-events.test.js`: 7 pruebas. Cubren la firma, el vencimiento, la entrega por `job_id`, el aviso al responsable, el reintento con 503, la recuperación y que no haya bucle de avisos.

### Fase 6 · Seguimiento administrativo (SCRUM-43)

**Diseño e implementación.**
- **Consulta de estado:** el bot reconoce «ESTADO» y responde con `requests.list`, que CitaYa limita a las solicitudes vinculadas a ese chat, con estados en lenguaje claro.
- **Avisos al responsable** (`CITAYA_ADMIN_WHATSAPP`, por WhatsApp):
  - solicitud pendiente nueva, con radicado y plazo;
  - envío fallido por WhatsApp o correo. Los fallos de textos sueltos no se avisan, para evitar bucles.
- **Flujo «CitaYa · Seguimiento»** (cada hora, al minuto 5):
  - `system.health`: aviso si el procesador no da latido desde hace más de 5 minutos, si el QR está desconectado o si hay eventos que no llegaron a n8n;
  - `requests.overdue`: aviso por radicado cuando la solicitud está por vencer (desde 3/4 del plazo) o vencida;
  - a las 7:00, hora de Colombia, `stats.summary` del día anterior: solicitudes (cuántas por WhatsApp), historias entregadas, pendientes y vencidas.
  - Cada aviso lleva una clave, así que no se repite.
- **En CitaYa:**
  - tabla `system_state`, con el latido del procesador como máximo una vez por minuto;
  - operaciones `system.health`, `stats.summary` y `requests.overdue`, sin nombres ni documentos;
  - `GET /health` del servicio QR, que devuelve solo `connected` y `pending`.
- **Limitación conocida:** si el QR está desconectado, el aviso «QR desconectado» no puede salir por WhatsApp. Queda registrado en CitaYa y en n8n.

**Validación.**
- `node --test n8n`: 23 pruebas en total (bot 11, eventos 7, seguimiento 5).
- `php tests/n8n-api.php`: 27 comprobaciones (4 nuevas: salud, latido, vencidas y resumen).
- Regresión completa correcta. Migración aplicada en `citaya_v3`.
- Los cuatro flujos importables están en `n8n/*.json`, generados con `python n8n/build-workflows.py`.

### Fase 7 · Puesta en marcha en el VPS (SCRUM-44)

**Corrección: firma del servicio QR.** El bot no respondía en el VPS porque `WA_SECRET` (servicio QR) no coincidía con `CITAYA_WA_SECRET` (n8n): el webhook devolvía 401 y el mensaje quedaba en la bandeja. El titular igualó el secreto en Dokploy. Tras el despliegue, el mensaje pendiente se entregó (HTTP 200) y el bot respondió con el menú.
- El servicio QR ahora registra cada mensaje recibido (solo los últimos 4 dígitos del número), el motivo cuando ignora uno (sin número, `WA_ALLOWED`, sin conexión) y la respuesta HTTP del webhook.

**Flujo de errores «CitaYa · Errores»** (`n8n/citaya-errors.js`, `n8n/citaya-errores.json`).
- Error Trigger → aviso al responsable por WhatsApp (`message.send`, firmado) con el flujo, el nodo, el error resumido (máx. 160 caracteres, sin números de 7 o más dígitos) y el enlace a la ejecución. Clave por ejecución, así que no se repite.
- Publicado y asignado como *Error workflow* de Bot WhatsApp, Eventos, Recuperación de envíos y Seguimiento.
- Validación: `node --test n8n/*.test.js` 28 pruebas (5 nuevas). Prueba real con un flujo temporal que falla a propósito (borrado después): el aviso llegó al WhatsApp del responsable.

**Pruebas en el VPS (datos ficticios).**
- Saludo → menú: correcto.
- HISTORIA → enlace al formulario: correcto.

**Cambio pedido por el titular: WhatsApp de la web sin confirmación.**
- Antes, el número escrito en el formulario web quedaba pendiente hasta que el paciente enviara «CONFIRMAR código» desde ese chat. Ahora se valida el formato y queda vinculado al crear la solicitud (`createRequest(..., $whatsappPhone)`): la historia o el radicado salen directamente a ese número.
- Se quitó el recuadro de confirmación de la página y el texto de ayuda dice que también se enviará el radicado.
- Riesgo aceptado: igual que con el correo escrito en la búsqueda directa, quien conozca nombre y documento del paciente puede pedir que la historia vaya a un número que no es suyo. «CONFIRMAR» sigue funcionando en el bot para solicitudes anteriores.
- Validación: `tests/n8n-api.php` 28 (1 nueva); `whatsapp` 44, `whatsapp-qr` 8, `requests-admin` 25 y `run` 155 correctas.

**Corrección: rechazar la consulta sugerida y buscar otra fecha.**
- Problema (reportado en la prueba): con «No recuerdo la fecha», la página sugería la consulta más reciente y solo dejaba aceptarla; no había forma de decir que no era esa.
- Ahora, cuando el paciente fue encontrado y no hay coincidencia, la página de estado muestra «No es esta consulta: buscar con otra fecha» (o «Buscar con otra fecha o especialidad» si no hay sugerencia), con fecha y especialidad. Usa `changeFilters` y la misma búsqueda: si hay historia de esa fecha se envía; si no, la solicitud sigue pendiente para el personal y la página vuelve al estado.
- Validación: `tests/run.php` 158 (3 nuevas: sugerencia sin fecha, otra fecha con historia, otra fecha sin historia).

**Corrección: chats con identificador privado (LID).** Un segundo número escribió al bot y el servicio QR lo descartó («no se pudo obtener el número (lid)»): WhatsApp no envió el número real y el mapeo LID→número no estaba disponible. Ahora el servicio reintenta el mapeo a los 2 s y, si sigue sin número, usa el identificador del chat (dígitos del LID) como número para CitaYa y n8n. Las respuestas salen al mismo chat por el mapa `chats.json`.

**Cambio: fecha aproximada indicada por el paciente.** Primero se probó una lista de consultas por periodo («hace 1 a 3 meses»…); el titular la descartó: quiere que la persona elija un día, mes y año aproximados y que la solicitud quede registrada con esa fecha para verificarla él en el otro sistema.
- «No es esta consulta: indicar la fecha aproximada» (o «Indicar la fecha aproximada de la atención» sin sugerencia) pide día, mes y año, y la especialidad si la recuerda. Usa `changeFilters`: si hay historia de ese día se envía sola; si no, la solicitud queda pendiente con esa fecha, que el panel muestra en «Fecha indicada / elegida».
- Después de indicar la fecha no se vuelve a sugerir otra consulta: la página dice «Solicitud registrada con la fecha aproximada dd/mm/aaaa» y que la persona encargada la verificará.
- Se quitó el código de periodos. Se conserva `availableEncounters`, ahora usado por la sugerencia.
- Validación: `tests/run.php` 158, `whatsapp` 44, `n8n-api` 28 y `requests-admin` 25 correctas. Prueba manual en local con un paciente ficticio: sin fecha → sugerencia → fecha aproximada 15/09/2026 → «Solicitud registrada».

**Diseño: detalle de la solicitud como expediente.** A pedido del titular (con una imagen de referencia), el «Detalle del envío» se reorganizó:
- Encabezado «Caso #radicado · Expediente de atención» con icono y botón × (el título lo toma `app.js` del contenido cargado).
- Dos columnas: a la izquierda «Información de la solicitud» en tres bloques con banda de color (Paciente: nombre, documento con botón copiar, fecha de atención, especialidad y nombre en los PDF; Contacto: correo y WhatsApp con botón copiar; Requerimiento: tipo, estado, fecha de radicación, atención seleccionada, respuesta y caso reabierto). A la derecha, la gestión (responder, cargar PDF, «No registra») y el historial de envíos y WhatsApp, que ahora también se ve en el modal.
- En pantallas angostas las columnas se apilan. La página sin modal usa el mismo diseño.
- Validación: pruebas completas correctas y revisión visual en local con un caso ficticio.

**Cuadro de gestión de la solicitud (Aprobado / No Registra).** A pedido del titular, con imágenes de referencia.
- **Requisito:** en el detalle, a la derecha, «Comentario de gestión / respuesta» con selector de estado (Aprobado, No Registra), menú de plantillas, área para adjuntar documentos (PDF, JPG, PNG) y botones «Cerrar» y «Guardar y notificar».
- **Diseño:**
  - `Application::respondCase`: valida estado, comentario (10-3000 caracteres), tipo real del archivo (finfo) y tamaño total (`max_response_bytes`, 14 MB, para que quepa en un correo de `max_mail_bytes`). «Aprobado» exige al menos un adjunto.
  - Guarda los adjuntos en `storage/responses` (tabla `response_files`, con hash) y el comentario cifrado en `requests.response_note`.
  - Correo: «Aprobado» sale como trabajo `response` (actualiza el estado de la solicitud como una historia y aparece en el historial de enviadas); «No Registra» sale como `notice`. Ambos llevan el comentario y los adjuntos verificados.
  - WhatsApp: el comentario y luego cada adjunto (PDF como documento, JPG/PNG como imagen, también por el servicio QR). A n8n se avisa como `historia.disponible` o `solicitud.no_registra`, así que la entrega por n8n funciona sin cambios en los flujos.
  - Plantilla nueva «Historia aprobada» (editable en Plantillas). El menú de plantillas inserta «Historia aprobada» o «No registra» y elegir el estado llena la plantilla si el comentario está vacío.
  - Las historias del repositorio con el mismo documento y la carga de PDF al repositorio quedan en una sección desplegable debajo. Después de responder, el detalle muestra «Respuesta enviada» con el comentario.
  - Límite de PHP en el contenedor: `upload_max_filesize` 15M y `post_max_size` 40M.
- **Validación:** `tests/requests-admin.php` 36 (11 nuevas: rechazos, archivos guardados y enlazados, comentario cifrado, correo con adjuntos, WhatsApp con documento e imagen, detalle y «No Registra» sin adjuntos). Las demás suites siguen correctas. Prueba en local con un caso ficticio: «Aprobado» con un PDF y un PNG sale de pendientes y queda «Respuesta enviada». `tests/admin-http.php` sigue apuntando a CitaYaV2 y falla igual que antes del cambio.

**Historial del caso en ventana aparte.** A pedido del titular (con imagen de referencia):
- Se quitó del detalle de la solicitud el «Historial de envíos y reenvíos», los «Mensajes de WhatsApp» y la «Respuesta enviada». En su lugar hay un botón de reloj, «Historial del caso». El mismo botón está en cada fila de Solicitudes y del Historial de enviadas, junto al ojo.
- La ventana «Historial del caso #radicado» muestra el paciente y el estado actual, y una línea de tiempo: radicación (por formulario o por enlace del bot, con la fecha indicada), búsqueda automática, respuesta de la persona encargada (estado y comentario), reapertura y cada envío por correo y WhatsApp (estado, archivos, fecha, responsable y a quién se notificó).
- `AdminDeliveries::timeline` arma la línea de tiempo solo con metadatos y el comentario de respuesta. Ruta `?page=case-history` (fragmento para la ventana; página completa sin JavaScript). `processSearch` ahora registra también «paciente no encontrado» y «entrega no habilitada».
- Validación: `tests/requests-admin.php` 38 (2 nuevas), las demás suites correctas y revisión visual en local, incluida la apertura sobre el detalle.

**Ajuste: sin historias del repositorio en el detalle.** A pedido del titular se quitó del detalle la sección «Historias del repositorio con este documento» (asignar una historia y «Cargar el PDF de esta solicitud»). La respuesta se gestiona solo con el cuadro «Aprobado / No Registra» y sus adjuntos. Las acciones del servidor siguen existiendo, pero el panel ya no las ofrece. Se actualizó el texto de Solicitudes. Pruebas correctas.

**Indicaciones por orden médica en WhatsApp.** A pedido del titular: el bot explica qué hacer con cada orden (p. ej., fórmula → reclamar los medicamentos en la farmacia).
- Antes: un texto con todas las órdenes y una guía breve, y después imágenes con solo el título.
- Ahora: un aviso con cuántas órdenes vienen y, por cada página con órdenes (máx. 8), la imagen de la página con las indicaciones paso a paso de cada orden y dónde está («Documento 1, página 2 de tu historia»).
- Las indicaciones son plantillas editables («Orden: fórmula médica», «Orden: laboratorio», imágenes diagnósticas, procedimiento, terapias, remisión, incapacidad) en Acceso interno → Plantillas. Son orientaciones administrativas y el aviso recuerda seguir lo que indicó el médico.
- Si la página no se puede convertir en imagen al enviar, las mismas indicaciones salen como texto (antes se omitía). Aplica a QR, Meta y Zernio. El contenedor tiene poppler-utils para generar las imágenes.
- Validación: `tests/whatsapp.php` 45 (pruebas de órdenes actualizadas y una nueva de plantilla editable). Las demás suites siguen correctas.
