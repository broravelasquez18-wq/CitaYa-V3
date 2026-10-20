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

**Incidente: correo en reintento permanente («Esperando un nuevo intento…»).**
- Diagnóstico en el VPS: el contenedor sí conecta con smtp.gmail.com (587, 465 y 25), pero Gmail rechaza el inicio de sesión («Could not authenticate»). `MAIL_PASSWORD` no es una contraseña de aplicación de Gmail (tiene 12 caracteres; las de aplicación tienen 16). Lo resuelve el titular creando la contraseña de aplicación y poniéndola en Dokploy.
- Defecto corregido: PHPMailer cierra la conexión tras el fallo y el código SMTP se perdía (`transport_error_0`), así que el error de credenciales se reintentaba como una falla de red. Ahora se registra como `smtp_auth_failed` y queda en `failed` de inmediato. Eso dispara el aviso al responsable y la página del paciente ya no se queda cargando.
- Nuevo `php bin/mail-check.php [--retry]`: prueba el inicio de sesión SMTP sin mostrar la contraseña y, con `--retry`, vuelve a encolar los correos fallidos por credenciales o red de los últimos 7 días. Las respuestas recuperan su texto del comentario cifrado.

**Corrección: aviso de «historia no encontrada» enviado aunque la historia sí estaba.**
- Problema (reportado por el titular): cuando la primera búsqueda no daba coincidencia exacta (sin fecha o con otra fecha), se enviaba de inmediato por WhatsApp el radicado de «no encontramos tu historia». Al mismo tiempo la página le sugería su consulta, y si la elegía también recibía la historia.
- Ahora (`processSearch($id, $final)`):
  - si la página sugiere una consulta, el aviso queda en espera (evento `notice_deferred`);
  - si el paciente elige la consulta, solo recibe la historia;
  - si indica su fecha aproximada y no hay historia de ese día, recibe el radicado de inmediato (`changeFilters(..., true)`);
  - si no decide, el procesador envía el radicado a los 20 minutos (`releaseDeferredNotices`, una vez por minuto en `bin/worker.php`).
  - Si no se encontró al paciente o no hay nada que sugerir, el aviso sale de inmediato, como antes.
- Al encolar una historia se cancela cualquier aviso de «no encontrada» que siga sin enviar.
- Validación: `tests/requests-admin.php` 43 (5 nuevas). Las demás suites siguen correctas.

**Corrección: órdenes no detectadas en el formato real de historia.** El bot no enviaba indicaciones porque la historia cargada tenía 0 órdenes detectadas. El formato real titula las páginas de órdenes «SOLICITUD MEDICAMENTOS AMBULATORIOS», «SOLICITUD LABORATORIOS EXTRAMURAL», «SOLICITUD AYUDAS DIAGNOSTICAS EXTRAMURAL» e «SOLICITUD INTERCONSULTAS EXTRAMURAL», y el detector solo reconocía «ORDEN DE …» / «FÓRMULA MÉDICA».
- `MedicalOrders` reconoce ahora los títulos «SOLICITUD …» (medicamentos → fórmula, laboratorios, ayudas diagnósticas/imágenes, interconsultas → remisión, procedimientos, terapias). Los campos de la página de resumen («LABORATORIOS EXTRAMURAL:») no cuentan, porque pueden ir vacíos.
- Verificado con 5 historias locales: detecta solo las páginas de órdenes (p. ej., fórmula p. 5 e interconsulta p. 6). Prueba nueva en `tests/whatsapp.php` (46).
- Para las historias ya cargadas: `php bin/detect-orders.php` (procesa los archivos sin órdenes registradas).

**Indicaciones precisas por orden.** A pedido del titular, el mensaje dice exactamente qué se ordenó (p. ej., «Te ordenaron: *Ecografía pélvica ginecológica transvaginal*» y pedir la cita).
- `MedicalOrders::details` lee la página de la orden (de «PLAN DE MANEJO» a «IMPRESIÓN DIAGNÓSTICA»):
  - estudios, exámenes e interconsultas: nombre del servicio con su código CUPS, antes o después según cómo se extrae el texto;
  - medicamentos: nombre, dosis, frecuencia, vía, duración e indicaciones;
  - cuándo pedir la cita («1 MES Y MEDIO») y la vigencia («Vigencia: 180»).
  Si el formato no se reconoce, no inventa nada.
- Se guarda en `clinical_orders.details` (JSON; migración en `bin/setup.php`). `bin/detect-orders.php --refresh` vuelve a leer todos los PDF ya cargados.
- El texto de cada imagen queda así: título de la orden → «Te ordenaron / Te formularon» con la lista → cuándo pedir la cita → vigencia → pasos de la plantilla editable.
- Validación: `tests/whatsapp.php` 51 (6 nuevas, con textos del formato real sin datos de pacientes). Vista previa con 2 historias locales: laboratorio (3 exámenes), ecografía con vigencia de 180 días, interconsulta para dentro de 1 mes y medio, y fórmula con 3 medicamentos. Las demás suites siguen correctas.

**Mensajes de órdenes más sencillos.** El titular pidió respuestas mucho más fáciles de entender.
- Cada orden va en pocas líneas: icono y qué es (en palabras simples), una sola acción («👉 Pide una cita en tu EPS para hacerte este examen»), para cuándo pedir la cita y la vigencia («⏳ Tienes 180 días para usar esta orden»).
- Interconsultas: «Cita de control con dermatología» en vez de «Consulta de control o de seguimiento por especialista en…». Varias órdenes del mismo tipo se agrupan bajo «Exámenes de laboratorio», «Citas con especialistas», etc.
- Medicamentos: dosis en palabras simples («cada día», «por boca», «sobre la piel», «durante 90 días»).
- Aviso inicial de una línea. Se quitaron la referencia a la página y los pasos numerados largos.
- Las plantillas «Orden: …» quedaron como una sola línea de acción (editables).
- Validación: `tests/whatsapp.php` 54 (3 nuevas: formato de imágenes, interconsulta y dosis).

**Ajuste: sin confirmación al responder.** A pedido del titular se quitó la ventana «¿Guardar la respuesta… y notificar al paciente?»: «Guardar y notificar» envía directamente. Se mantienen las validaciones (estado elegido, adjuntos para «Aprobado», límite de tamaño) y el botón se desactiva al enviar para evitar dobles envíos.

**Corrección: órdenes en las respuestas «Aprobado».** Al responder una solicitud con un PDF adjunto, el WhatsApp enviaba el documento pero no explicaba sus órdenes (solo se hacía con las historias del repositorio).
- `responseMessages` lee cada PDF adjunto de una respuesta «Aprobado» (`MedicalOrders::detect`) y agrega, después de los documentos, el aviso y una imagen por página con órdenes y su texto sencillo.
- El armado se unificó en `orderPageMessages`, que usan ambos casos.
- Validación: `tests/whatsapp.php` 55 (1 nueva: respuesta con PDF que trae una orden de ecografía → texto, documento, aviso e imagen con «Pide una cita en tu EPS»).

**Plantillas «No registra» por motivo.** A pedido del titular, al responder «No Registra» se puede elegir el motivo exacto en el menú «Plantillas»:
- `nr_document` (no hay historias con ese documento), `nr_name` (el nombre no coincide con el documento), `nr_date` (no hay atención en la fecha indicada), `nr_specialty` (no hay atención en esa especialidad) y la general `not_registered`.
- Usan los datos de la solicitud ({documento}, {nombre}, {fecha}, {especialidad}) y exigen {enlace} para que el paciente haga una nueva solicitud. Se editan en Plantillas.
- Elegir una plantilla selecciona su estado. Elegir el estado sugiere la plantilla general (Aprobado → «Historia aprobada»; No Registra → «No registra: general»).
- WhatsApp ya no antepone «Respuesta a tu solicitud…» cuando el comentario ya nombra el radicado.
- Validación: `tests/requests-admin.php` 46 (3 nuevas). Las demás suites siguen correctas.

**Ajuste: pestaña WhatsApp oculta.** A pedido del titular se quitó la pestaña «WhatsApp» del panel mientras el bot está conectado. Solo aparece, junto con un aviso rojo («El WhatsApp del bot está desconectado… Vincularlo de nuevo →»), cuando el servicio QR informa que está desconectado. Así se conserva la forma de volver a vincularlo desde el panel. El estado se consulta a `/health` del servicio QR como máximo una vez por minuto por sesión.

## Roles, reporte de errores por WhatsApp y chat con el paciente (SCRUM-45)

**Requisitos (titular).**
- Rol empleado: responde solicitudes y ve su avance (respondidas hoy y en el mes).
- Rol administrador: todo el sistema, el análisis y la gestión de empleados (avance por día, mes y año).
- Menú del bot: 1) Solicitar historia clínica por atención, 2) Reportar un error.
- El reporte de error es automático con el bot. El bot detecta si requiere a un empleado; si sí, se notifica al empleado que respondió esa historia, que responde por un chat en el sistema, con el mismo número del bot.

**Diseño.**
- **Roles (SCRUM-46):**
  - columnas `admins.role` (admin/employee), `phone` y `created_at` (migración: los administradores existentes quedan como admin);
  - `requireStaff()` para Solicitudes, Reportes y Mi avance; `requireAdmin()` para Repositorio, Historial, Bandeja, Análisis, Plantillas, WhatsApp y Empleados, también en las acciones;
  - en cada petición se relee el rol y si la cuenta sigue activa;
  - `Staff`: crear (contraseña aleatoria mostrada una vez), editar, activar o desactivar (no la propia, y siempre queda un administrador activo), nueva contraseña, y avance día/mes/año (`requests.resolved_by` y reportes cerrados).
- **Bot (SCRUM-47):**
  - `SupportDesk::handle` decide antes del menú: chat abierto → el mensaje va al caso; reporte en curso → siguiente paso (`whatsapp_sessions`, 30 min; «0» cancela); opción «2» o palabras como «error» o «reportar» → inicia el reporte;
  - el bot pregunta qué historia (si hay varias enviadas a ese WhatsApp), qué problema (5 opciones) y la descripción;
  - «No me llegó / no abre el archivo» lo resuelve el bot: `WhatsApp::resendDelivery` vuelve a encolar la historia o la respuesta «Aprobado», y el caso queda `auto_resolved`;
  - lo demás abre un caso asignado a quien respondió esa historia o, si fue automática, al empleado activo con menos casos abiertos. Se le avisa con un contador en el panel y por WhatsApp si tiene número registrado;
  - n8n: nueva acción `conversation.handle`, llamada por el bot antes del menú; los mensajes de un chat abierto no cuentan para el límite de 20 por hora.
- **Chat (SCRUM-48):**
  - «Reportes de error»: abiertos y resueltos (el empleado ve los suyos, el administrador todos), con mensajes sin leer;
  - el caso muestra la conversación (`support_messages`, cifrada), que se actualiza cada 8 s; las respuestas salen por el número del bot;
  - «Marcar como resuelto» cierra el caso, despide al paciente, lo devuelve al menú y cuenta en el avance.

**Validación.**
- `tests/support.php` 32 comprobaciones nuevas: empleados y reglas de administración, avance, menú, reenvío automático, reporte con empleado, aviso por WhatsApp, chat, permisos entre empleados, cierre, cancelación, varias historias, opción inválida, sin historias y `conversation.handle`.
- `node --test n8n/*.test.js` 29 (1 nueva).
- Suites anteriores correctas; se actualizó el texto del menú esperado en 4 pruebas.
- Revisión visual en local con cuentas de prueba (después borradas): pestañas por rol, reportes, chat, envío y bloqueo de Empleados para el empleado.

**Ajuste: avisos de reportes solo en el sistema.** A pedido del titular, al empleado no se le envía nada por WhatsApp; los avisos llegan al panel y desde ahí responde en el chat.
- Se quitaron el mensaje del bot al empleado y el campo «WhatsApp para avisos» en Empleados.
- `SupportDesk::notifications` y la ruta `?page=notifications` (JSON) devuelven los reportes abiertos que esperan respuesta: nuevos o con mensajes del paciente sin leer. El empleado ve los suyos y el administrador todos.
- `app.js` la consulta cada 15 s en cualquier página del panel: actualiza el contador de «Reportes de error» y, ante un reporte nuevo o un mensaje nuevo del paciente, muestra un aviso en pantalla con «Abrir chat». No avisa del chat que ya está abierto.
- Validación: `tests/support.php` 32 (el aviso llega al panel del empleado asignado, no a su WhatsApp ni a otros empleados).

**Empleados: cédula y CRUD completo.** A pedido del titular:
- **Cédula** como identificador de cada persona: se pide primero, es obligatoria al crear (5 a 12 dígitos; se quitan puntos y espacios) y es única (`admins.document_number UNIQUE`). Internamente se conserva el id numérico como llave técnica, porque las solicitudes respondidas, los reportes y el avance apuntan a él; cambiarla rompería ese historial. Las cuentas anteriores aparecen como «Sin cédula» hasta editarlas.
- **CRUD:**
  - crear (cédula, nombre, correo y rol);
  - ver, con búsqueda por cédula, nombre o correo;
  - editar cédula, nombre, correo y rol (sin repetir cédula ni correo);
  - eliminar, con confirmación. Sin actividad se borra del todo. Con actividad se archiva (`deleted_at`): no aparece ni puede entrar, libera su cédula y correo, se conserva el historial y sus reportes abiertos pasan al empleado activo con menos carga. Nadie puede eliminarse a sí mismo y siempre queda un administrador activo.
  - Se mantienen activar/desactivar y nueva contraseña. El inicio de sesión excluye las cuentas eliminadas.
- Validación: `tests/support.php` 43 (11 nuevas) y prueba en local de crear y eliminar desde el formulario.

## Frontend v2: rediseño completo del portal y del acceso interno (SCRUM-49, RF-30 = SCRUM-50)

**Alcance (fase 1).** El titular pidió una versión 2 de todo el frontend usando las skills instaladas, y decidió:
- reemplazar la v1, sin interruptor entre versiones;
- usuarios: pacientes (sobre todo en móvil, a menudo desde WhatsApp) y personal de la clínica;
- de la identidad anterior solo se conserva el nombre CitaYa.

El contexto de producto quedó en `PRODUCT.md`.

**Requisitos (fase 2).**
- **RF-30.** Un único sistema visual para todas las vistas: portal, inicio de sesión, consola interna, modales y ventanas de progreso.
- **RNF:** se conservan:
  - rutas, formularios con CSRF, atributos `data-*` del JS y funcionamiento sin JavaScript;
  - la CSP sin estilos en línea y con fuentes servidas desde el propio servidor;
  - contraste AA, campos de 16 px, objetivos táctiles de 44 px y ausencia de desbordamiento horizontal.

**Diseño (fase 3).** Dirección «Tablero de turnos», elegida por el titular en la página de decisión de la skill *impeccable*. Toma la pantalla de turnos de las salas de espera colombianas:
- **Radicado como turno llamado:** en el estado de la solicitud y en el detalle del caso aparece en una placa azul noche, con número en cifras tabulares y estado con ícono.
- **Colores:** encabezado y rail interno azul noche y suelo claro frío. El ámbar es exclusivo de lo que está en curso (en cola, enviando, pendientes); verde confirma, rojo detiene, siempre con ícono y texto.
- **Tipografía:** Atkinson Hyperlegible Next y Mono (Braille Institute, licencia OFL), autoalojadas en `public/assets/fonts/`.
- **Consola interna:** rail lateral con secciones y contadores. En Pendientes, un tablero con la cantidad en espera y «Atender la más antigua».
- **Análisis:** barras de participación en la tabla, sin gráfico circular.

**Implementación (fase 4).**
- `app.css` y `responsive.css` reescritos con tokens.
- `templates/icons.php` agrega íconos SVG (`ui_icon`) que reemplazan los caracteres Unicode, y el tono de estado (`ui_tone`).
- `layout.php` y `admin.php` reestructurados (consola con rail).
- Las plantillas internas pierden los rótulos sobre los títulos y los números de sección.
- Ventanas de progreso con barra lineal, sin cambios en `upload-progress.js`.
- `app.js`: el aviso de reportes busca el contador en el rail; se quitan los emojis; en móvil el rail muestra la sección actual.

**Validación (fase 5).**
- Pruebas que no dependen del servidor web, sin cambios en verde: `run` 158, `requests-admin` 46, `resend-ui` 19, `support` 43, `whatsapp` 55, `n8n-api` 28, `whatsapp-qr` 8, `whatsapp-zernio` 22, `analytics`, `upload-progress.cjs`.
- `http`, `admin-http`, `bulk-http` y `upload` siguen fallando como antes: apuntan a `localhost/CitaYaV2`.
- Detector de diseño sin hallazgos.
- Capturas en Chrome sin interfaz a 1440 y 390 px de 17 vistas más el modal, sin desbordamiento horizontal. Los estados del paciente se generaron con datos ficticios.
- Revisión final independiente (subagente) con una ronda de 8 correcciones.
- Las capturas quedan en `.impeccable/review/`, excluida del repositorio porque muestra datos de la base local.
- Decisión abierta: si el logo conserva el ámbar (SCRUM-51).
- Sin verificar: el tablero de turnos con solicitudes en espera (en local había 0).

## Frontend v2: superficies comunes — drawer, modal, aviso y toast (SCRUM-49)

**Requisitos (fase 2).** El titular pidió reestructurar el HTML con cuatro superficies de uso fijo:
- **drawer** para formularios (agregar empleado, cargar historias, editar plantilla);
- **modal** para detalles (solicitud, historial del caso, resultado de carga) y formularios cortos (editar cuenta, ajustar fecha o especialidad);
- **aviso** (modal de alerta) para algo importante o para confirmar una acción (WhatsApp desconectado, contraseña nueva, retirar, reabrir, desactivar, eliminar, restaurar plantilla, cerrar reporte);
- **toast** para fallos, confirmación de acciones y novedades (reportes nuevos, mensajes del paciente).

RNF: se mantienen el funcionamiento sin JavaScript (enlace `#id` + `:target`), la CSP sin estilos en línea y los objetivos táctiles de 44 px.

**Diseño (fase 3).**
- El drawer entra por la derecha; en móvil ocupa toda la pantalla.
- El modal se centra; en móvil sube desde abajo como hoja.
- Encabezado común (`ui_sheet_head`), pie de acciones fijo abajo y zona «Más acciones».
- Toasts abajo a la derecha, con tono por borde e ícono. Éxito e información se ocultan solos (pausa al pasar el ratón o con foco). Un fallo queda hasta cerrarlo.

**Implementación (fase 4).**
- `app.js` expone `window.CitaYa` con `open`, `close`, `toast` y `confirm`. Gestiona animación de entrada y salida, Escape, clic en el fondo, foco de regreso, bloqueo de scroll, `data-auto-open` (reabrir el formulario tras un error) y `data-once`.
- `data-confirm` usa el aviso propio en lugar de `window.confirm`, también en contenido cargado después. `alert()` y `confirm()` del cuadro de respuesta pasan a toast y aviso.
- Los toasts van en la capa superior (`popover`) para verse sobre un modal abierto.
- El detalle y el historial del caso comparten `remoteModal` y la plantilla `templates/detail-modals.php`. Se eliminan los diálogos propios de detalle, historial, reapertura y resultado de carga.
- «Reabrir caso» pasa a ser un formulario con confirmación común.
- El servidor indica el tono del toast con `$_SESSION['flash_tone']` (`info` o `error`).

**Validación (fase 5).**
- Sin cambios en verde: `run` 158, `requests-admin` 46, `resend-ui` 19, `support` 43, `whatsapp` 55, `n8n-api` 28, `whatsapp-qr` 8, `whatsapp-zernio` 22, `analytics`, `upload-progress.cjs`.
- Chrome sin interfaz a 1440 y 390 px: drawers de empleado, plantilla y carga; modal de edición; confirmación y cancelación; detalle con historial encima; toasts sobre un modal. Sin desbordamiento horizontal. Se usó una cuenta temporal que se eliminó al terminar.
- Sin verificar con datos: la confirmación de reapertura (el historial local tenía 0 casos).

## Frontend v2: regreso a la paleta de la versión 2 (SCRUM-49)

**Requisito.** El titular pidió usar los colores de la versión 2. Es la paleta de `CitaYaV2`, igual a la del último commit de V3:
- cian `#00acc4`, teal `#008f98`;
- tinta `#203648`, fondo `#f5f9fa`, líneas `#dfe9ed`, suave `#e9f8f8`;
- éxito `#276b59`/`#e2f5ef` y error `#973f3f`/`#fff0ef`.

Reemplaza al azul noche y ámbar de «Tablero de turnos». La estructura (drawer, modal, aviso, toast) no cambia.

**Implementación.**
- Tokens de `app.css` reasignados. Nuevo `--brand` `#00808a`: el teal de la v2 un poco más profundo para que el texto blanco cumpla AA (4,71). Es el color de botones principales, pasos, selección y foco.
- Encabezado y rail vuelven a ser blancos, con la sección activa en teal sobre `#e9f8f8`.
- Las superficies oscuras (placa del radicado, toasts, tablero de espera) usan la tinta `#203648`.
- El ámbar se mantiene solo para lo que está en curso.
- Logo con el cuadro cian y «Ya» en cian, como en la v2. `theme-color` blanco. `DESIGN.md` actualizado.

**Validación.**
- Contrastes AA: botón 4,71; texto atenuado 4,70; enlaces 5,61; sección activa 5,74; éxito 5,56; error 6,12.
- Capturas a 1440 y 390 px sin desbordamiento horizontal.
- Mismas comprobaciones de superficies en verde. `resend-ui` 19 en verde.

## Portal del paciente: formulario de solicitud guiado (SCRUM-49)

**Requisito.** El titular pidió que el formulario de `?page=home` sea mucho más intuitivo para el paciente, que suele llegar desde el celular por WhatsApp.

**Diseño.**
- El formulario se divide en tres pasos cortos, cada uno con una pregunta:
  1. ¿De quién es la historia clínica?
  2. ¿Cuándo fue la atención?
  3. ¿Dónde te la enviamos?
- Barra «Paso N de 3» y un solo botón principal. En móvil la barra de acciones queda fija abajo.
- El tipo de documento sigue siendo una lista desplegable (decisión del titular), junto al número en escritorio.
- «No recuerdo la fecha» es una tarjeta que explica qué pasará.
- WhatsApp lleva el prefijo +57 visible.
- Antes de enviar, un modal «Revisa tus datos» resume paciente, atención y entrega, con «Cambiar» por sección.

**Implementación.**
- `templates/request-form.php` (nuevo) reemplaza el formulario de `layout.php`. Los nombres de campo, el CSRF y el contrato del servidor no cambian.
- En `app.js`, el módulo del formulario guiado:
  - valida al salir del campo con mensajes claros, las mismas reglas del servidor;
  - quita puntos, espacios y guiones del documento y cambia el teclado según el tipo;
  - sugiere el dominio del correo cuando hay un error de escritura (por ejemplo `gmial.com` → `gmail.com`);
  - con Enter avanza de paso;
  - antes de enviar valida todo y abre la revisión.
- Si el servidor rechaza un dato, la página vuelve al paso que corresponde (documento o nombre → 1, fecha o especialidad → 2, correo o WhatsApp → 3) con lo escrito y un toast de error.
- Sin JavaScript, los tres pasos se ven seguidos y el botón envía con la validación nativa.

**Validación.**
- Recorrido en Chrome sin interfaz a 1440 y 390 px: errores en línea, limpieza del documento, sugerencia de correo, revisión y «Cambiar», sin desbordamiento horizontal. No se envió ninguna solicitud.
- Un POST con correo inválido devuelve el paso 3 con TI, número, nombre y fecha conservados.
- `run` 158, `resend-ui` 19 y `requests-admin` 46 en verde.

## Portal del paciente: formulario en una sola tarjeta (SCRUM-49)

**Requisito.** El titular pidió un formulario más intuitivo y compartió una referencia: una sola tarjeta centrada con secciones numeradas.

**Diseño.** Reemplaza al formulario por pasos.
- En `?page=home` hay un título centrado y una tarjeta. No se muestran la introducción, los pasos del proceso ni la ayuda lateral; las demás páginas del portal no cambian.
- Tres secciones con título en mayúsculas: 1. Datos del paciente, 2. Atención médica, 3. Datos de contacto.
- Etiquetas en mayúsculas y campos con ícono. Fecha y especialidad, y correo y celular, van lado a lado en escritorio.
- Acciones «Cancelar» (limpia el formulario) y «Radicar solicitud de historia».
- Recuadro «Autorización de tratamiento de datos personales» (Ley 1581 de 2012). Debe validarse con la política de datos de la IPS.
- No se agregó el selector «Resultado de estudios / Historia clínica» de la referencia: el sistema solo gestiona historias clínicas.
- Se conservan la validación en línea, la limpieza del documento, la sugerencia de correo y el modal «Revisa tus datos».

**Accesibilidad.**
- Títulos de sección en `#007d8f` (4,85:1).
- El botón principal es `#0097ad` con texto de 19 px en negrita, que cuenta como texto grande (3,48:1, mínimo 3:1).

**Validación.**
- Chrome sin interfaz a 1440 y 390 px: errores al radicar incompleto, sugerencia de correo, revisión, «Cambiar» enfoca la sección y «Cancelar» limpia. Sin desbordamiento horizontal.
- Un POST con error conserva lo escrito.
- `run` 158, `resend-ui` 19 y `requests-admin` 46 en verde.

## Acceso interno: nuevo inicio de sesión (SCRUM-49)

**Requisito.** El titular compartió una referencia de inicio de sesión: panel oscuro con marca y módulos a la izquierda y la tarjeta de acceso a la derecha. Pidió hacerla con los colores actuales.

**Diseño.**
- El fondo usa la tinta de la v2 (`#16273a`–`#203648`), con retícula fina y halos cian y teal. El encabezado del sitio pasa a oscuro en esta página.
- A la izquierda: emblema, «CitaYa · Acceso interno», una línea de propósito y «Qué hace CitaYa» en seis tarjetas (a pedido del titular, en lugar de los módulos): lee los PDF, encuentra la historia, envía por correo, avisa por WhatsApp, organiza lo pendiente y mide la gestión.
- A la derecha, la tarjeta «Iniciar sesión»: correo y contraseña con ícono, botón para mostrar u ocultar la contraseña y botón cian «Acceder al portal».
- Al pie de la tarjeta, ayuda para recuperar la contraseña: un administrador genera una nueva desde Empleados.
- En móvil la tarjeta va primero.
- No se copiaron elementos de la referencia que el sistema no tiene o que serían afirmaciones sin respaldo:
  - «Mantener conexión», recuperación automática de clave e instalación como app;
  - sellos «SSL 256-bit», «HIPAA/HL7 FHIR» y «Servidores en línea»;
  - módulos ajenos (Agendamiento, Recursos humanos) y la marca QUALISYS.

**Implementación.**
- `templates/login.php` (nuevo).
- Íconos `plus`, `lock`, `eye` y `eye-off` en `icons.php`.
- Estilos de `body.login` en `app.css`.
- Mostrar u ocultar la contraseña con `aria-pressed`, en `app.js`. Sin JavaScript el botón no aparece.

**Validación.**
- Contrastes: textos pequeños en cian claro `#3fd2df` (6,41:1); botón con tinta sobre cian (4,57:1); texto atenuado (6,81:1).
- Capturas a 1440 y 390 px sin desbordamiento horizontal.
- Credenciales erróneas: toast «Credenciales incorrectas» y correo conservado.
- `run` 158 en verde.

## Acceso interno: animaciones del inicio de sesión (SCRUM-49)

**Requisito.** El titular pidió animar el inicio de sesión: que se muevan las cajas de los módulos, que se vean brillos moviéndose al fondo y que haya pastillas y cápsulas detrás.

**Implementación.** Solo CSS (respeta la CSP), animando únicamente `transform` y `opacity`.
- **Fondo** (`.login-glow`, fija y recortada):
  - tres halos cian y teal que derivan entre 22 y 30 s;
  - un barrido de luz sobre la retícula;
  - 14 chispas que suben y titilan;
  - 10 pastillas y cápsulas de dos tonos que flotan y giran despacio, algunas desenfocadas para dar profundidad.
- **Contenido:**
  - entrada escalonada de la marca y los módulos, y la tarjeta de acceso entra desde la derecha;
  - los módulos flotan con desfase y les pasa un reflejo; al pasar el ratón se elevan con borde cian;
  - el emblema emite anillos y los puntos de estado parpadean;
  - el botón «Acceder al portal» tiene un reflejo periódico suave.
- Con «reducir movimiento» todo queda quieto y las chispas se ocultan.
- En móvil se ocultan tres pastillas y baja su opacidad.

**Validación.**
- Chrome sin interfaz: las transformaciones cambian entre capturas a los 3 y 6 s (66 animaciones activas). Con `prefers-reduced-motion: reduce` quedan sin transformación.
- 1440 y 390 px sin desbordamiento horizontal: se corrigió uno de 8 px en móvil con `overflow: clip` y `contain: strict` en la capa de fondo.

## Acceso interno: modo claro y oscuro en el inicio de sesión (SCRUM-49)

**Requisito.** El titular pidió un botón para cambiar el inicio de sesión entre modo claro y oscuro.

**Implementación.**
- Botón «Modo claro / Modo oscuro» con ícono de sol y luna, en el encabezado del inicio de sesión (a pedido del titular). En móvil queda solo el ícono, con etiqueta accesible. Usa `aria-pressed` y una etiqueta que dice a qué modo cambia.
- La elección se guarda en `localStorage` (`citaya-theme`). `public/assets/theme.js` se carga en `<head>` sin `defer` (CSP `script-src 'self'`) y aplica `data-theme` antes de pintar, sin parpadeo.
- El modo oscuro es el predeterminado.
- El modo claro reasigna las variables `--login-*` (fondo `#eef6f8`, tarjetas blancas, tinta `#203648`, acento `#00737c`) y suaviza los halos, chispas, pastillas y cápsulas. Las animaciones se mantienen.
- `theme-color` del navegador acompaña el tema.
- Sin JavaScript el botón no aparece.

**Validación.**
- Chrome sin interfaz: clic → claro y guardado; recarga → sigue claro; clic → vuelve a oscuro.
- 1440 y 390 px sin desbordamiento.
- Contraste del acento claro sobre el fondo ≥ 4,5:1.
- `run` 158 en verde.

## Portal del paciente: fondo animado y modo claro u oscuro en el formulario (SCRUM-49)

**Requisito.** El titular pidió adaptar al formulario de solicitud lo hecho en el inicio de sesión.

**Implementación.**
- El fondo animado (halos, barrido, chispas, pastillas y cápsulas) pasa a `templates/ambient.php`, compartido.
- `layout.php` marca con `body.ambient` las páginas `admin-login` y `home`: incluye el fondo y muestra el botón de tema en el encabezado.
- Los estilos `body.login` del fondo, el encabezado, el pie y el tema pasan a `body.ambient`.
- La elección de tema es la misma en ambas páginas (`citaya-theme`).
- En `body.request-page`:
  - entrada escalonada del título, la tarjeta y las tres secciones, con reflejo periódico en «Radicar»;
  - el modo oscuro (predeterminado) usa la tarjeta en tinta con campos blancos, títulos en cian claro, ayudas atenuadas y errores en `#ff9c94` (5,82:1), con «Cancelar» y «Radicar» en el estilo del login;
  - el modo claro conserva la tarjeta blanca.
- El modal de revisión y las demás páginas del portal no cambian.

**Validación.**
- Chrome sin interfaz a 1440 y 390 px, en ambos modos y con errores de validación visibles. Sin desbordamiento horizontal.
- `run` 158, `resend-ui` 19 y `requests-admin` 46 en verde.

## Despliegue: autodeploy de Dokploy desde GitHub (SCRUM-49)

**Incidencia.** El push de `ab9ec3a` no se desplegó.
- El compose `citayav3` en Dokploy usa el proveedor genérico *Git* (`main`, `./docker-compose.yml`, sin rutas vigiladas) con *Autodeploy* activo.
- Con ese proveedor, el despliegue automático depende de que GitHub llame a la URL de despliegue de Dokploy, y el repositorio no tenía ningún webhook.
- Los despliegues anteriores se habían lanzado por otra vía.

**Corrección (con autorización del titular).**
- Despliegue manual de `ab9ec3a` desde Dokploy: el sitio quedó con el formulario y el login nuevos.
- Webhook en GitHub (Settings → Webhooks) hacia `http://2.25.77.168:3000/api/deploy/compose/<id>`, evento *push*, `application/json`, activo. La primera entrega (ping) fue exitosa.
- Desde ahora cada push a `main` despliega solo. Este registro se subió con un push para comprobarlo.

## Login y formulario del paciente: responsive en tableta y móvil (SCRUM-49)

**Requisito.** El titular pidió estructurar mejor el responsive móvil del inicio de sesión y del formulario.

**Problemas encontrados** en capturas de página completa a 360, 390 y 768 px:
- En el encabezado se amontonaban los dos enlaces y el botón de tema, aunque uno lleva a la página en la que ya se está.
- En el login, la marca grande se repetía debajo de la tarjeta (el emblema con anillos tocaba el borde) y las seis tarjetas de «Qué hace CitaYa» alargaban mucho la página.
- En tableta, la tarjeta de acceso ocupaba todo el ancho.
- El botón «Radicar solicitud de historia» se partía en dos líneas.

**Cambios** (`responsive.css`, sección «Login y formulario del paciente»):
- **Tableta (≤ 900 px):** tarjeta de acceso centrada a 520 px y bloque de marca a 620 px.
- **Móvil, encabezado (≤ 640 px):** solo el enlace a la otra página, en forma de pastilla, junto al botón de tema. Se oculta el subtítulo del logo.
- **Móvil, login:**
  - tarjeta más compacta;
  - la marca repetida queda oculta a la vista (se mantiene para lectores de pantalla);
  - «Qué hace CitaYa» pasa a dos columnas con ícono y título.
  - La página baja de 1.516 a 1.186 px de alto a 360 px.
- **Móvil, formulario:**
  - título más corto de alto y tarjeta más ancha;
  - menos espacio entre campos;
  - botones de 52 px;
  - el botón dice «Radicar solicitud» en una sola línea.

**Validación.**
- 360, 390 y 768 px en modo oscuro y claro, sin desbordamiento horizontal.
- `run` 158 y `resend-ui` 19 en verde.

## Empleados: contraseña manual al editar una cuenta (SCRUM-46)

**Requisito.** El titular pidió que, al editar a una persona, el administrador pueda escribir la contraseña a mano en lugar de solo generarla.

**Diseño.**
- El modal «Editar cuenta» suma la sección opcional «Cambiar contraseña», con *Contraseña nueva* (con mostrar u ocultar) y *Confirmar contraseña*.
- En blanco se conserva la actual.
- «Generar contraseña aleatoria» sigue en «Más acciones».

**Implementación.**
- `Staff::checkPassword`:
  - mínimo 10 caracteres y máximo 72 bytes, el límite de bcrypt;
  - la confirmación debe coincidir (`hash_equals`);
  - no puede ser la cédula ni el correo.
- `Staff::setPassword` guarda el hash y registra el evento `staff_password_set`.
- En `staff-update`, la contraseña se valida **antes** de guardar los demás datos, para no dejar la edición a medias.
- Si algo falla, el modal se reabre con los datos pero sin la contraseña escrita.
- En el navegador, la confirmación es obligatoria si se escribe una nueva y se avisa si no coincide.
- El toast al guardar recuerda entregarle la contraseña a la persona; nunca se muestra en pantalla.

**Validación.**
- `tests/support.php`: 48 en verde (6 nuevas):
  - contraseña manual válida;
  - corta;
  - confirmación distinta;
  - igual a la cédula;
  - un intento rechazado no cambia la contraseña.
- En local, con una cuenta temporal ya eliminada:
  - el navegador bloquea una confirmación distinta;
  - el servidor rechaza una corta y reabre el modal;
  - una válida se guarda, la anterior deja de funcionar y la nueva permite entrar.
- `run` 158, `requests-admin` 46, `resend-ui` 19 y `whatsapp` 55 en verde.

**Ajuste (2026-10-14, decisión del titular).** Por ahora se permite usar la cédula o el correo como contraseña manual: se quitó esa regla de `Staff::checkPassword` y del texto de ayuda del modal. Se mantienen el mínimo de 10 caracteres y la confirmación. La prueba correspondiente ahora comprueba que la cédula se acepta (`support` 48 en verde).

**Ajuste (2026-10-14, decisión del titular).** El mínimo de la contraseña manual baja de 10 a 6 caracteres para que sirvan cédulas cortas (por ejemplo, de 8 dígitos). Cambian `Staff::checkPassword`, el `minlength` y el texto de ayuda del modal. Nueva comprobación: una cédula de 8 dígitos se acepta. `support` 49 y `run` 158 en verde.

## IP real del cliente detrás de Traefik (límites de intentos)

**Problema.**
- En producción (Dokploy + Traefik), Apache ve todas las peticiones con la IP interna del proxy, `10.0.1.48`.
- Por eso los límites por IP los compartían todos los usuarios:
  - `admin-login:` permitía 10 intentos cada 15 minutos para todo el personal junto;
  - `request-ip:` permitía 20 solicitudes por hora para todos los pacientes juntos.

**Diseño.**
- Nueva clase `App\ClientIp::resolve($_SERVER)`.
- Si `REMOTE_ADDR` es pública, se usa tal cual y se ignora `X-Forwarded-For` (en una conexión directa, el cliente podría falsificarlo).
- Si `REMOTE_ADDR` es privada o reservada y llega `X-Forwarded-For`, se toma la última IP válida de la lista. Traefik (sin `forwardedHeaders.insecure`) descarta el encabezado que manda el cliente y pone el suyo con la IP real al final.
- Sin `X-Forwarded-For`, o si no trae ninguna IP válida, se conserva `REMOTE_ADDR`. En XAMPP local (127.0.0.1, sin proxy) no cambia nada.

**Implementación.** Se agregó `src/ClientIp.php`. En `public/index.php`, `$ip` ahora sale de `ClientIp::resolve`.

**Validación.**
- Nueva prueba `tests/client-ip.php`, 11 en verde: IP pública directa (también con XFF), proxy privado con XFF, varias IP, IPv6, entrada final inválida, XFF inválido o vacío, sin XFF, local y sin `REMOTE_ADDR`.
- `run` 158, `requests-admin` 46, `resend-ui` 19 y `support` 49 en verde.

**Pendiente (despliegue).** Tras el push a `main`, confirmar en los registros del contenedor que los límites ya se cuentan por cliente.

## Bot de WhatsApp: solicitud por documento con datos guardados (RF-31, SCRUM-53)

**Alcance.**
- El paciente que ya pidió su historia por el formulario web no vuelve a llenarlo.
- El bot pide el número de documento y reutiliza los datos guardados.
- La primera vez sigue siendo por el formulario web.

**Requisito RF-31.**
- Cada solicitud guarda los datos del paciente: documento, nombre, correo y WhatsApp.
- Con HISTORIA, el bot pide el documento:
  - sin datos guardados, envía el enlace del formulario;
  - con datos guardados, los muestra para confirmarlos y pregunta la fecha y la especialidad de la atención.

**Diseño.**
- Nueva tabla `patient_profiles`, única por (tipo, número) e indexada por número. Se crea con `schema.sql`, que ya aplica `bin/setup.php` al arrancar.
- `Application::createRequest` llama a `saveProfile`.
  - Si el documento ya tiene perfil con otro nombre, solo se reemplaza cuando el nombre nuevo coincide con el registro del paciente. Así un tercero no puede cambiar el correo guardado.
  - El WhatsApp se conserva si la nueva solicitud no trae uno.
- Nueva clase `App\WhatsAppIntake`. Guarda el paso de la conversación en `whatsapp_sessions` con estados `intake_*`. `SupportDesk::handle` le pasa esas sesiones antes de su propia lógica de cancelación.
  - `intake_document`: el número debe tener entre 3 y 24 letras o dígitos, con al menos un dígito. Hay un límite de 10 consultas por chat y hora para evitar recorrer documentos ajenos.
  - Mismo WhatsApp que el perfil: muestra los datos completos.
  - Otro WhatsApp: primero pide el nombre completo, igual que el formulario. Después muestra el correo oculto. Con 2 nombres incorrectos envía el formulario.
  - `intake_confirm`: 1 continúa; 2 envía el formulario para corregir los datos.
  - `intake_date`: acepta día/mes/año o año-mes-día, no futura; 0 si no la recuerda.
  - `intake_specialty`: lista numerada de especialidades; 0 si no la recuerda.
  - Se radica con `createRequest(..., directSearch: true, whatsappPhone: chat)`, la misma búsqueda directa del formulario.
  - Si la historia o el radicado quedan en cola, ese mensaje es la respuesta.
  - Con varias atenciones (`intake_select`), el paciente elige por número.
  - Sin coincidencia y con una atención reciente disponible (`intake_suggest`), el bot la ofrece. Si el paciente responde 2, se envía el radicado.
  - MENU, cancelar o salir terminan la conversación.
- Bot PHP: en `WhatsApp::handleInbound`, la intención «history» llama a `WhatsAppIntake::start` en vez de enviar el enlace.
- n8n: nueva acción `intake.start` en `N8nApi`. `whatsapp-bot.js` la usa y, si falla, vuelve al enlace (`link.create`). Los siguientes pasos llegan por `conversation.handle`. Se regeneró `citaya-bot-whatsapp.json`.

**Validación.**
- `tests/whatsapp.php`: 79 en verde (24 nuevas):
  - perfil guardado y protegido contra otro nombre;
  - documento inválido;
  - confirmación de datos;
  - fechas inválidas y futuras;
  - especialidad fuera de la lista;
  - radicación con historia por chat y correo;
  - sugerencia aceptada y rechazada;
  - corrección con el formulario y MENU;
  - otro WhatsApp con nombre y correo oculto;
  - formatos de fecha.
- Se ajustaron las pruebas que esperaban el enlace directo, ahora con un documento nuevo: `requests-admin` 46, `support` 49, `whatsapp-qr` 8, `whatsapp-zernio` 22 y `n8n-api` 28 en verde.
- `run` 158, `resend-ui` 19 y `pdf-reader` 17 en verde. Pruebas de n8n con `node --test`: bot 13 en verde.
- `admin-http`, `bulk-http`, `http` y `upload` fallan igual en `main` sin estos cambios: necesitan datos demo y credenciales locales.

**Despliegue (2026-10-15).**
- El push a `main` se desplegó bien.
- La primera prueba seguía enviando el enlace: en producción el QR reenvía los mensajes a n8n, y el flujo «CitaYa · Bot WhatsApp» tenía el código anterior.
- Con la autorización del titular, se cambió en el nodo «Procesar» solo el bloque de HISTORIA. Antes se comprobó que el código coincidía con la versión anterior del repo; el resultado tiene el mismo hash que `n8n/citaya-bot-whatsapp.json`.
- Se publicó la versión `29be3158`.

**Pendiente.** Probar desde WhatsApp: HISTORIA → documento → confirmación de datos.

**Ajuste (2026-10-15, pedido del titular).** El bot acepta la fecha de la atención con el mes escrito.
- Formatos: «10 de julio del 2026», «10 julio 2026», «10 jul. 2026», «Julio 10, 2026», «el 10 de julio». Se aceptan abreviaturas, mayúsculas y tildes, y también «setiembre».
- Sin año, se toma la fecha ya pasada más reciente.
- Se rechazan las fechas inexistentes, los meses que no existen y las fechas futuras.
- Los mensajes del bot muestran los dos ejemplos (*15/03/2026* o *10 de julio de 2026*).
- Solo cambia `WhatsAppIntake::parseDate` en CitaYa; el flujo de n8n no se toca.
- `whatsapp` 83 en verde (4 nuevas, una de ellas en la conversación). `run` 158, `support` 49, `n8n-api` 28 y `requests-admin` 46 en verde.

**Ajuste (2026-10-15, pedido del titular).** Si es la primera vez del documento, el bot ya no envía el aviso «No tenemos solicitudes anteriores…». Ahora llega solo el mensaje del formulario con su enlace (plantilla `form_link`). Solo cambia `WhatsAppIntake` en CitaYa; n8n no se toca. Nueva comprobación: en ese caso sale un único mensaje. `whatsapp` 84, `support` 49, `requests-admin` 46, `n8n-api` 28 y `whatsapp-qr` 8 en verde.

**Ajuste (2026-10-15, pedido del titular): mensajes más cortos y directos.**
- Se acortaron los textos del bot en `WhatsAppIntake` (documento, nombre, confirmación, fecha, especialidad, sugerencia), `SupportDesk` (reporte de errores y cierre) y `WhatsApp` (código, liberación de mensajes y prefijo de respuesta, que pasa de «Respuesta a tu solicitud» a «Solicitud»).
- También se acortaron los textos predeterminados de las plantillas: menú, enlace al formulario, historia encontrada, radicado pendiente, aprobada y los cinco «No registra». Las variables y las reglas no cambian; `{enlace}` sigue siendo obligatorio.
- Las plantillas ya editadas en el panel conservan su texto guardado: para usar el nuevo hay que pulsar «Restaurar».
- Los textos fijos del flujo de n8n (respuestas a CONFIRMAR y al menú de respaldo) no se tocaron.
- Las pruebas se ajustaron a los textos nuevos: `whatsapp` 84, `support` 49, `requests-admin` 46, `n8n-api` 28, `whatsapp-qr` 8, `whatsapp-zernio` 22, `run` 158 y `resend-ui` 19 en verde.

**Incidente (2026-10-15): el bot dejó de responder.**
- **Causa.** Los registros de Dokploy mostraron que los mensajes llegaban (QR → n8n, HTTP 200), pero desde las 21:06 `inbound.register` respondía `allowed:false`. Cada chat tenía un límite de 20 mensajes por hora, y la conversación guiada usa unos 6 mensajes por solicitud. Tras varias pruebas, el número quedó en silencio sin ningún aviso.
- **Corrección.** Los mensajes de una conversación guiada en curso (sesión en `whatsapp_sessions` de menos de 30 minutos, de solicitud o de reporte) ya no cuentan para el límite, igual que el chat abierto con el personal. Las consultas de documento y las solicitudes conservan sus propios límites. El límite general sube de 20 a 60 mensajes por hora, en `N8nApi` y en el bot PHP.
- **Validación.** `n8n-api` 30 en verde (2 nuevas: 70 mensajes en una conversación sin bloqueo; fuera de una conversación, el mensaje 61 se limita). `whatsapp` 84, `support` 49, `requests-admin` 46, `whatsapp-qr` 8, `whatsapp-zernio` 22 y `run` 158 en verde.

**Ajuste (2026-10-15, pedido del titular): la especialidad también se entiende escrita.**
- En la prueba real, el paciente respondió «No recuerdo» y el bot solo repetía que respondiera con el número.
- Ahora `WhatsAppIntake::specialtyChoice` acepta:
  - el número;
  - «no recuerdo», «no sé», «ninguna» (= 0);
  - el nombre escrito, sin tildes ni mayúsculas, aunque esté dentro de una frase («fue en pediatría»). El nombre exacto gana cuando otro lo contiene (Ginecología frente a Ginecología y Obstetricia).
- Si lo escrito coincide con varias especialidades («medicina»), el bot pregunta solo entre ellas.
- Si no se entiende, responde «No reconocimos esa especialidad…» y recuerda el *0*.
- La fecha también acepta «no sé» o «ninguna».
- `whatsapp` 91 en verde (7 nuevas). `support` 49, `n8n-api` 30 y `requests-admin` 46 en verde.

**Ajuste (2026-10-17, pedido del titular): login y formulario más livianos.**
- **Diagnóstico.** El servidor responde en ~25 ms; la lentitud estaba en el navegador. El fondo animado (`ambient.php`) del login tenía 56 animaciones infinitas y 20 elementos con filtros. Los halos usaban `blur(60px)` sobre 520 px mientras se escalaban. Las chispas animaban `filter: brightness`, lo que repinta en cada cuadro. El encabezado usaba `backdrop-filter`, que se recalculaba sobre el fondo en movimiento. El brillo de los botones animaba `left`, que recalcula el diseño en cada cuadro.
- **Corrección (solo CSS/JS, mismo diseño).**
  - Se quitó el `blur` de los halos (el degradado radial ya es difuso) y de dos cápsulas.
  - Se quitaron el parpadeo de las chispas, el `backdrop-filter` del encabezado (ahora con fondo casi opaco) y el flotado infinito de los módulos.
  - El brillo de los botones ahora usa `transform`.
  - El fondo se pausa mientras se escribe (`body.is-typing`) y se reanuda 1,2 s después de la última tecla.
- **Validación.** Login: 36 animaciones infinitas (antes 56) y 0 filtros (antes 20). Formulario: 29 animaciones y 0 filtros. Ninguna animación usa `left` ni `filter`. Se comprobó la pausa al escribir y que el aspecto se mantiene. `prefers-reduced-motion` sigue apagando el fondo.

**Ajuste (2026-10-17, pedido del titular): el reporte por WhatsApp valida primero el documento.**
- **Antes.** Con la opción 2, el bot identificaba al paciente solo por el número de WhatsApp verificado. Listaba de una vez las historias enviadas a ese número, con su radicado y su fecha.
- **Ahora.** `SupportDesk` pide primero el *número de documento* del paciente (estado `ask_document`) y no muestra nada hasta validarlo.
  - Solo se listan las historias enviadas a este WhatsApp **para ese documento** (`claimed_document_number`). Si en un mismo número hay historias de varios pacientes, cada uno ve solo las suyas.
  - El documento se normaliza igual que en la solicitud (se quitan puntos, espacios y guiones). Un texto que no parece documento se vuelve a pedir sin contar como intento.
  - Hasta 3 documentos que no coinciden; luego el reporte se cierra. Hay además un límite de 10 consultas de documento por hora por chat (`support-document:`). *0* cancela, como antes.
- El flujo de n8n no cambia: sigue enviando el mensaje a `conversation.handle`.
- **Validación.** `support` 53 en verde (5 nuevas: pide el documento sin mostrar historias, documento ajeno, documento mal escrito, cierre tras 3 intentos, solo las historias del documento validado). `whatsapp` 91, `n8n-api` 30, `requests-admin` 46 y `whatsapp-qr` 8 en verde.

**Ajuste (2026-10-17, pedido del titular): la lista del reporte por WhatsApp no muestra el radicado.**
- **Antes.** Con varias historias, la opción 2 las listaba como «radicado (dd/mm/aaaa)».
- **Ahora.** Cada historia sale como «Solicitada el viernes 16 de octubre de 2026, 3:42 p. m.» (`SupportDesk::longDate`), sin el radicado. Los mensajes de confirmación conservan la referencia del reporte (`RE-…`).
- El flujo de n8n no cambia.
- **Validación.** `support` 54 en verde (1 nueva: la lista no trae el radicado y da la fecha con mes y hora).

**Ajuste (2026-10-17, pedido del titular): fotos y PDF en el chat de los reportes de error.**
- **Antes.** El servicio QR descartaba todo mensaje sin texto, así que las fotos del paciente nunca llegaban al chat del reporte. El personal solo podía responder con texto.
- **Ahora.**
  - **Paciente → chat.** El servicio QR descarga la foto o el documento (Baileys `downloadMediaMessage`, máx. 10 MB) y lo reenvía en base64 dentro del mensaje (`image|document` con `data`, `mime_type`, `filename`, `caption`). n8n lo pasa a `conversation.handle` como `media`. El pie de foto llega como texto.
  - `SupportDesk` guarda el archivo en `storage/support` si su tipo real (no el declarado) es JPG, PNG, WEBP o PDF. Si es de otro tipo, pesa demasiado o no se pudo descargar, el chat anota que llegó un archivo que no se puede mostrar.
  - Una foto también sirve como descripción al crear el reporte.
  - **Personal → paciente.** El chat permite adjuntar un PDF (máx. 10 MB, se revisa el contenido). Sale por la cola de WhatsApp (`kind = support_file`) como documento, con el texto como pie; si el texto pasa de 1000 caracteres, va antes en un mensaje aparte.
  - **Panel.** Las fotos se ven en la burbuja y los PDF como tarjeta con nombre y tamaño. Ambos se abren desde `?page=report-file`, que solo sirve el archivo a quien puede ver el reporte y verifica su huella.
  - `support_messages` tiene columnas nuevas de adjunto y `outbox_id` (las agrega `bin/setup.php`). `n8n.php` y `whatsapp.php` aceptan cuerpos de hasta 16 MB.
- **Despliegue.** El push a main reconstruye la app, el worker y el contenedor `whatsapp-qr`. El nodo «Procesar» del flujo «CitaYa · Bot WhatsApp» debe actualizarse y publicarse en n8n con `n8n/citaya-bot-whatsapp.json`; sin eso, las fotos llegan a n8n pero no a CitaYa.
- **Validación.** `support` 64 en verde (10 nuevas: foto con pie, permisos para abrirla, PDF del paciente, archivo no admitido o sin datos, sin menú del bot, PDF del personal por la cola, PDF sin texto, solo PDF reales, envío vacío y foto vía n8n). `whatsapp` 91, `n8n-api` 30, `requests-admin` 46, `whatsapp-qr` 8 y bot de n8n 14 en verde. `admin-http` falla igual que antes del cambio (apunta a CitaYaV2).

**Corrección (2026-10-17): las fotos no llegaban al chat en producción.**
- **Causa.** `docker/entrypoint.sh` asignaba los permisos de `storage` antes de `bin/setup.php`. Como `setup.php` corre como root, creó `storage/support` con dueño root y modo 0700, y Apache (www-data) no podía guardar las fotos: `conversation.handle` devolvía 500 y el bot le mostraba el menú al paciente.
- **Arreglo.** El entrypoint crea también `wa-outbox`, `responses` y `support` antes del `chown`, y vuelve a asignar los permisos después de `setup.php`. Si un adjunto no se puede guardar, el mensaje igual entra al chat con la nota, sin error ni menú.
- **Validación.** `support` 64 en verde; `sh -n` del entrypoint sin errores. Diagnóstico en los logs de Dokploy: el QR entregaba los mensajes (HTTP 200) y la app registraba `Permission denied` en `storage/support`.

**Ajuste (2026-10-17, pedido del titular): «Cerrar sesión» pasa del menú lateral al encabezado.**
- El botón sale del final del rail (`templates/admin.php`) y queda al final del encabezado del acceso interno (`templates/layout.php`), solo con sesión iniciada. En el rail queda el nombre y el rol.
- En pantallas de 650 px o menos el botón muestra solo el ícono (el texto queda para lectores de pantalla). En la barra horizontal del móvil ya no se reserva espacio para la sesión.
- **Validación.** Revisado en local a 1366 px y a 375 px. Al pulsarlo se cierra la sesión y se vuelve al inicio.

**Ajuste (2026-10-17, pedido del titular): el empleado puede cargar historias clínicas.**
- **Antes.** Cargar historias (una atención o carga masiva) y la página Repositorio eran solo del administrador.
- **Ahora.**
  - El empleado ve «Repositorio» en el menú y puede usar «Cargar historias», con una atención o con varias.
  - Las acciones `upload` y `bulk-upload` piden sesión del personal (`requireStaff`). Cada carga queda en el registro de eventos (`histories_uploaded`) con quién la hizo.
  - **Retirar un paquete sigue siendo solo del administrador.** El empleado no ve la columna «Acción», y la acción `withdraw` sigue protegida en el servidor.
  - El separador del menú ahora va después de las 4 secciones de todo el personal, y no se dibuja si es la última (empleado).
- **Validación.** En local, con un empleado de prueba (`.runtime/empleado-prueba.txt`): ve Repositorio y abre el panel de carga. Su carga llega a la validación de los PDF (un archivo falso se rechaza con el mensaje de siempre), y «Retirar» lo devuelve a Solicitudes con «Esa sección es solo para administradores».

**Ajuste (2026-10-17, pedido del titular): tabla de Solicitudes más clara.**
- **Antes.** La columna del correo ocupaba el espacio y el nombre del paciente se partía en varias líneas, igual que la cédula (p. ej. «111222094 / 9»). El plazo y los envíos eran columnas aparte, y la tabla se desbordaba en pantallas de 1280 px.
- **Ahora** (`templates/deliveries.php`; el nombre y la cédula también en `delivery-history-rows.php`):
  - **Paciente:** el nombre va en una sola línea, y si es muy largo termina en «…» con el nombre completo al pasar el mouse. Debajo va el tipo de documento y el número en mono, sin partirse.
  - **Contacto:** reemplaza «Correo de entrega». El correo va en una línea (recortado con el completo al pasar el mouse) y, si aplica, «WhatsApp» debajo.
  - **Estado:** las pastillas van apiladas. En Pendientes, el plazo va aquí en forma corta («Quedan 5 h», «Vencida hace 2 h»; el detalle exacto al pasar el mouse) y los envíos como texto («1 envío»). Quedan 5 columnas en ambas pestañas.
  - **Fila completa clicable:** abre el detalle de la solicitud, igual que el botón del ojo. Los botones de la fila conservan su acción, y seleccionar texto no abre nada.
  - En el celular siguen siendo tarjetas; el nombre va en una línea y el correo se puede partir.
- **Validación.** En local a 1280 px, sin desborde aun con un nombre de 45 caracteres y el plazo vencido. Clic en la fila abre el detalle. Revisado a 375 px. La rama de Pendientes se probó con datos ficticios en CLI («Vencida hace 6 h», «Quedan 50 min»). `requests-admin` 46 y `support` 64 en verde.

**Ajuste (2026-10-17, pedido del titular): Análisis con gráficas circulares y de crecimiento.**
- **Antes.** Una tabla de especialidades con barras de participación y los totales por día, mes y año.
- **Ahora** (`templates/analytics.php`, `AppointmentAnalytics::trend()` y `channels()`):
  - **Resumen del mes:** solicitudes recibidas, historias enviadas y atenciones del mes, con la variación frente al mes anterior. Si el mes anterior fue 0, se muestra la cifra en vez de un porcentaje. Un solo filtro de mes controla todo el módulo.
  - **Crecimiento de 12 meses:** líneas de solicitudes e historias enviadas, en un solo eje. Al pasar el mouse (o con las flechas del teclado) se ve el mes con sus valores. La tabla queda en «Ver los datos por mes», con las atenciones incluidas.
  - **Donas:** atenciones por especialidad (top 5 + «Otras») y solicitudes por canal (WhatsApp / solo web). Cada una lleva el total en el centro, leyenda con cifra y porcentaje, aviso al pasar el mouse y su tabla completa.
  - La paleta categórica está validada para daltonismo con el validador del skill de visualización (pasa todas las pruebas; contraste bajo en 3 colores, compensado con leyenda y tabla). Se asigna por clases porque la CSP (`style-src 'self'`) bloquea los estilos en línea; el primer intento con `style="…"` dejó las líneas sin color.
  - Los totales por día, mes y año se conservan al final. DESIGN.md actualizado: ya no prohíbe las gráficas circulares.
- **Validación.** `analytics` 24 en verde (6 nuevas: 12 meses, conteos por mes con ceros, canales, render con dos donas y tabla, mes vacío sin división por cero). Revisado en local a 1366 px y 375 px (sin desplazamiento horizontal), con los avisos de dona y de crecimiento funcionando.

**Ajuste (2026-10-17, pedido del titular): especialidad con más demanda por día, mes y año.**
- **Necesidad.** Una función central del análisis es ver qué especialidad tuvo más demanda en un día, un mes o un año. Antes solo se podía ver por mes.
- **Ahora** (`AppointmentAnalytics::bySpecialty()`; `monthly()` comparte la consulta en `ranking()`):
  - Sección a todo el ancho «¿Qué especialidad tuvo más demanda?», con selector Día / Mes / Año y su propio campo de fecha (fecha, mes o año). Parámetros `sp` y `sp_date`. Sin fecha propia, el mes sigue al filtro del resumen y el día toma el último día con atenciones.
  - Un destacado nombra la especialidad más demandada con sus atenciones y su porcentaje. Si hay empate, nombra todas («Empate en el primer lugar»).
  - Debajo van la dona (top 5 + «Otras»), la leyenda y la tabla completa del período. Un período sin atenciones muestra un estado vacío.
  - La métrica sigue siendo la base del módulo: atenciones con PDF por fecha de atención, cada una contada una vez.
- **Corrección de paso.** Las clases de color `c1…c5` chocaban con las cápsulas decorativas del login (`.c3`, `.c4` con opacidad; `.c3`, `.c5` ocultas en móvil). Se renombraron a `sc1…sc5` / `sc-other`.
- **Validación.** `analytics` 32 en verde (8 nuevas: día, mes igual al análisis mensual, año, empate, día vacío, períodos o fechas inválidos, y render del selector con empate y del período anual). Revisado en local a 1366 px y 375 px con día (vacío), mes y año.

**Ajuste (2026-10-19, pedido del titular): se elimina la Bandeja local.**
- Sale del menú del administrador la página «Bandeja local» (`?page=inbox`), con la lista de correos de simulación. También sale la descarga del `.eml` (`?page=mail`), que solo usaba esa bandeja. Ambas direcciones muestran ahora «Página no encontrada».
- Se quitan sus estilos (`mail-*`) y la mención «Acceso interno → Bandeja local» del aviso del portal cuando el correo no está habilitado.
- No cambia el envío: en modo `local` los correos se siguen guardando en `storage/outbox/` y cuentan como simulados en Análisis.
- **Validación.** En local, el menú del administrador ya no la muestra y las dos direcciones responden «Página no encontrada». `requests-admin` 46, `support` 64 y `analytics` 32 en verde. `tests/http.php` se ajustó (ya no pide `inbox`/`mail`), pero falla igual que antes del cambio porque apunta a CitaYaV2, como `admin-http`.

**Ajuste (2026-10-19, pedido del titular): el administrador puede crear plantillas.**
- **Antes.** Plantillas tenía solo los 17 textos del sistema: editables y restaurables, pero no se podían agregar nuevos.
- **Ahora:**
  - Botón «Crear plantilla» en Plantillas. Cada plantilla nueva tiene nombre, uso y texto, y se puede editar o eliminar. Va en la tabla nueva `custom_templates`, que crea `schema.sql` al arrancar.
  - **Usos:**
    - **Respuesta «Aprobado»** y **Respuesta «No Registra»**: aparecen en el menú «Plantillas» al responder una solicitud, con los datos del paciente ya puestos, y seleccionan el estado.
    - **Chat de reportes de error**: aparece como «Respuesta rápida» en el chat; pregunta antes de reemplazar lo escrito.
  - **Variables por uso:** `{radicado} {nombre} {documento} {fecha} {especialidad}`; en «No Registra» también `{enlace}`, que es obligatorio, como en las del sistema; en el chat, `{nombre} {radicado} {reporte}`.
  - **Reglas:** nombre de 3 a 80 caracteres, único por uso; texto de 10 a 3000; solo se admiten las variables del uso. Crear, editar y eliminar queda en el registro de eventos.
  - La página separa «Plantillas creadas» de «Plantillas del sistema».
- **Validación.** `support` 74 en verde (10 nuevas: crear, variables reemplazadas, nombre repetido, variable ajena, «No Registra» sin `{enlace}`, uso inválido, nombre corto, editar y listar por uso, eliminar, eliminar dos veces). `requests-admin` 46 y `whatsapp` 91 en verde. En local: se creó una plantilla «No Registra» desde el navegador; aparece en el menú de respuesta con los datos de la solicitud, y una de chat aparece como respuesta rápida y llena el mensaje. Para probarlo se crearon en la base local una solicitud y un reporte ficticios («Paciente Ficticio Plantillas»).

**Ajuste (2026-10-19, pedido del titular): la consola interna con el diseño del login.**
- **Pedido.** Al titular solo le gustaba cómo quedaron el login y el formulario, y eligió llevar a toda la consola cuatro rasgos: el fondo oscuro animado, el modo claro/oscuro, las tarjetas de vidrio y la tipografía y el espaciado.
- **Cómo:**
  - `layout.php` marca la consola con sesión como `console-ambient` y le pone el fondo de `ambient.php` en variante `console`, con menos formas y chispas, más tenues.
  - El botón de tema aparece también en el encabezado de la consola.
  - En `app.css`, un bloque de tokens: el oscuro (predeterminado) y el claro redefinen las variables de superficie, tinta, líneas y estados, y agregan `--glass`. Tarjetas, rail y encabezado son de vidrio, sin `backdrop-filter`.
  - Los títulos llevan el rótulo mono «Acceso interno», con letra más grande y más aire.
  - La pausa del fondo mientras se escribe también aplica en la consola.
- **Validación.**
  - En local, en las 9 páginas de la consola en oscuro: ningún fondo claro sobrante y ningún texto sin contraste (revisión automática de colores calculados).
  - Revisados también el detalle de la solicitud (modal) y un drawer, en oscuro y en claro; el móvil a 375 px queda sin desplazamiento horizontal.
  - Fondo: 15 animaciones, solo `transform`/`opacity`, sin filtros. No se midieron los FPS porque el panel del navegador estaba oculto.
  - DESIGN.md actualizado con la excepción de sombras para el vidrio.

**Corrección (2026-10-19): el bot pedía el nombre completo aunque el documento ya tuviera datos guardados.**
- **Causa.** El bot pide el nombre cuando el chat no es el `whatsapp_phone` del perfil del documento, para que quien solo sepa un número de documento no vea los datos ni reciba la historia. Pero ese número solo se guardaba al radicar desde el bot, así que pedía el nombre en cada conversación cuando:
  - el perfil venía del formulario web sin WhatsApp, o vinculado con el código CONFIRMAR;
  - el nombre ya se había validado, pero la conversación quedó sin terminar.
- **Decisión del titular.** Pedir el nombre **solo la primera vez por cada WhatsApp**. Se descartó no pedirlo nunca, por el riesgo de que un tercero reciba la historia.
- **Arreglo.**
  - Tabla nueva `patient_profile_chats` (perfil + chat). El bot no pide el nombre si el chat es el del perfil o ya está en esa tabla.
  - Un chat queda recordado al validar el nombre en el bot, al confirmar con el código CONFIRMAR y al radicar una solicitud vinculada a ese chat (`saveProfile`).
  - `bin/setup.php` agrega, una sola vez y sin duplicar, los chats que ya tenían solicitudes vinculadas a cada documento.
  - Dos nombres incorrectos no recuerdan el chat. n8n no cambia.
- **Validación.** `whatsapp` 93 en verde (2 nuevas: el chat validado no vuelve a pedir el nombre, y CONFIRMAR recuerda el chat; además, un chat con nombres incorrectos no queda recordado). `support` 74, `n8n-api` 30, `requests-admin` 46 y `whatsapp-qr` 8 en verde.

**Ajuste (2026-10-20, pedido del titular): el menú lateral de la consola es una hamburguesa en móvil.**
- **Antes.** Hasta 960 px el rail de secciones se volvía una barra horizontal deslizable bajo el encabezado: había que deslizar para encontrar secciones y el usuario no se veía. A 375 px el encabezado se desbordaba («Solicitar historia», «Acceso interno», tema y «Cerrar sesión» no cabían).
- **Ahora (≤ 960 px, todas las páginas de la consola):**
  - Botón de hamburguesa a la izquierda del encabezado (`layout.php`, ícono nuevo `menu`). Abre el rail como panel lateral de hasta 300 px con fondo oscurecido.
  - Se cierra con Escape (el foco vuelve al botón), con un clic en el fondo o al elegir una sección; al abrir, el foco va a la sección actual. `aria-expanded` y la etiqueta del botón cambian con el estado. Al pasar a escritorio se cierra solo.
  - Cerrado, el panel queda con `visibility:hidden`, así que sus enlaces no reciben foco. Mientras está abierto la página no se desplaza detrás.
  - En el encabezado quedan hamburguesa, marca, tema y salir. «Solicitar historia» pasa al pie del panel, junto al usuario.
  - Escritorio sin cambios.
- **Validación.** En local, con una sesión de prueba creada en `storage/sessions` (ya borrada): a 375 px y 768 px el botón abre y cierra el panel (botón, fondo, Escape), el panel ocupa todo el alto y no hay desplazamiento horizontal; a 1280 px el rail lateral sigue igual y el botón no aparece. La animación de apertura no se pudo ver en movimiento porque el panel del navegador estaba oculto (se probó el estado final con transiciones desactivadas).

**Corrección (2026-10-20, pedido del titular): el encabezado se desbordaba en móvil.**
- **Causa.** En el login y el formulario del paciente, el enlace del encabezado («Acceso interno» / «Solicitar historia») tenía `white-space:nowrap` y no podía encogerse. En celulares de 320 px con la letra del sistema agrandada (125–150 %) empujaba el botón de tema fuera de la pantalla (hasta 38 px).
- **Arreglo (`responsive.css`, ≤ 650 px).** Marca, hamburguesa, tema y salir no se encogen; la navegación sí, y su enlace puede pasar a dos líneas.
- **Validación.** En local, login, formulario y consola a 320 y 360 px, con letra al 100, 125 y 150 %: el ancho del encabezado es igual al de la pantalla en todos los casos. A 375 px con letra normal el enlace sigue en una línea, como antes.

**Corrección (2026-10-20, pedido del titular): con el menú de hamburguesa abierto, la página de atrás se seguía desplazando.**
- **Causa.** El bloqueo era `body.rail-open{overflow:hidden}`, pero quien se desplaza es `html`. Como la consola pone `overflow-x:clip` en `html`, el `overflow` del `body` no llega a la ventana y no bloqueaba nada.
- **Arreglo.** Al abrir el menú, `app.js` pone la clase `rail-lock` en `html`. `responsive.css` aplica a `html` y `body` `overflow:hidden` y `overscroll-behavior:none`, y el fondo oscurecido lleva `touch-action:none`. El menú se sigue desplazando por dentro (`overscroll-behavior:contain`) sin arrastrar la página.
- **Validación.** En local a 375 px, con la página desplazada 200 px: con el menú abierto, la rueda sobre el fondo, el encabezado y el menú deja la página en 200; en una pantalla baja (480 px) el menú se desplaza hasta su final y la página sigue en 200. Control con el bloqueo anterior: la misma rueda movía la página de 200 a 500. No se probó en un iPhone real.

**Ajuste (2026-10-20, pedido del titular): Solicitudes e Historial de enviadas en un solo módulo, con tarjetas de estado.**
- **Antes.** Dos secciones en el menú: «Solicitudes» (pestañas Pendientes / Todas) y «Historial de enviadas» (solo administradores).
- **Ahora:**
  - «Solicitudes» tiene cuatro tarjetas arriba, según la imagen del titular: **Pendientes** (por gestionar), **Aprobadas** (listas o enviadas: lo que antes era el historial), **No registra** (respondidas «No Registra» y sin gestión pendiente) y **Todas**.
  - Cada tarjeta filtra la tabla (`?page=deliveries&f=pending|approved|not_registered|all`) y muestra cuántas solicitudes coinciden con la búsqueda actual; al buscar, los cuatro números se actualizan.
  - Aprobadas usa la tabla del antiguo historial. «Reabrir caso» sigue siendo solo para administradores; los empleados ven la lista sin ese botón.
  - «Historial de enviadas» sale del menú. La dirección antigua `?page=history` abre la tarjeta Aprobadas.
  - El tablero «N solicitudes en espera» se quitó porque repetía el número de Pendientes; queda el botón «Atender la más antigua».
  - Código: `AdminDeliveries::counts()` y el filtro «No registra» en `search()`; tarjetas en `deliveries.php`; ícono nuevo `file-x`.
- **Validación.** `requests-admin` 49 en verde (3 nuevas: la tarjeta «No registra» lista la solicitud respondida, cada tarjeta cuenta lo mismo que su lista y, al buscar un radicado, solo cuenta lo que coincide). `support` 74 y `run` 158 en verde. En local, a 1280 px en oscuro y claro, y a 375 y 320 px: filtros, búsqueda, `?page=history` y sin desplazamiento horizontal. La base local tiene pocas solicitudes (ninguna aprobada), así que la tabla de Aprobadas con datos no se vio en local.
