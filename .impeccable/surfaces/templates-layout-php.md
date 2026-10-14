---
version: 1
slug: "templates-layout-php"
primary_target: "templates/layout.php"
related_targets: ["templates/admin.php"]
---

# Surface brief: CitaYa v2 (portal del paciente + acceso interno)

Scope: todas las plantillas `templates/*.php` y `public/assets/*.css`. Visitor mode: Operate (paciente completa una solicitud; personal gestiona colas).

Audiencia y tarea: pacientes en móvil (a menudo desde WhatsApp) piden la historia de una atención y siguen su estado; personal en escritorio carga PDF, responde solicitudes, reportes y gestiona el bot. Restricciones: sin JS todo funciona; CSP `style-src 'self'` (sin estilos inline), fuentes autoalojadas; atributos `data-*` y clases que usa el JS se conservan.

## Direction contract

THESIS: Cada solicitud es un turno que se llama. El radicado es la cifra que manda y su estado se lee a un metro de distancia. Rechaza el portal clínico blanco-y-azul de tarjetas iguales y el panel gris con un acento.

OWN-WORLD: Barra superior y rail interno azul noche (#0f1b2d) como el tablero de la sala de espera; suelo claro frío, superficies blancas con filetes de 1px; ámbar (#f2a516) es dueño exclusivo de lo vivo (en cola, enviando, pendientes); verde confirma, rojo detiene, siempre con ícono y texto. Atkinson Hyperlegible Next para todo; Atkinson Hyperlegible Mono para radicados, cédulas y cifras tabulares. Esquinas de 6px, sin degradados ni biseles.

STORY: El paciente entiende en qué paso va, envía sus datos y ve su radicado «llamado» con un estado inequívoco y una sola acción siguiente. El personal ve cuántos turnos esperan, abre el más antiguo y responde.

FIRST VIEWPORT: Paciente: barra noche con marca a la izquierda; título de tarea y riel de tres pasos con cifras grandes; formulario en una columna ancha, acción principal como placa noche al pie; ayuda en columna lateral estrecha. Estado: placa invertida a ancho completo con «RADICADO» y el número en mono grande, el estado en pastilla de color, debajo la explicación y una acción. Interno: rail lateral noche con secciones y contadores ámbar; encabezado de página con título y acciones; tabla densa.

FORM: «Tablero de turnos» (digiturno colombiano), posición 3 de 7 en la lista ordenada; seed 934ede62. Movimiento firma: el radicado llamado en placa invertida con su estado (pantalla de estado, modal de progreso y detalle del caso). Raises: una placa invertida por pantalla (horario), cifras tabulares (datamatics), un acento para lo vivo (Metro), resultado a pantalla completa en móvil (feed).

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
