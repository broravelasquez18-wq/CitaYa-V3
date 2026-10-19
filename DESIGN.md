---
name: CitaYa
description: Historias clínicas por correo y WhatsApp; portal del paciente y consola interna con la lógica de un tablero de turnos.
colors:
  cyan: "#00acc4"
  teal: "#008f98"
  brand: "#00808a"
  brand-hover: "#006b74"
  brand-soft: "#e9f8f8"
  night: "#203648"
  night-2: "#2a465a"
  night-3: "#35546a"
  night-line: "#3d5b70"
  night-ink: "#eef6f8"
  night-muted: "#a9bec9"
  ground: "#f5f9fa"
  surface: "#fff"
  surface-2: "#f7fafb"
  line: "#dfe9ed"
  line-strong: "#c3d4db"
  ink: "#203648"
  ink-2: "#3b5060"
  muted: "#5c7380"
  amber: "#f2a516"
  amber-strong: "#b06f00"
  amber-ink: "#6b4100"
  amber-soft: "#fff3d8"
  amber-line: "#efd08c"
  ok: "#276b59"
  ok-soft: "#e2f5ef"
  ok-line: "#b9dfd0"
  stop: "#973f3f"
  stop-soft: "#fff0ef"
  stop-line: "#f0cfca"
  info: "#00737c"
  info-soft: "#e9f8f8"
  info-line: "#cce3e4"
  focus: "#00808a"
typography:
  display:
    fontFamily: "Atkinson Hyperlegible Next, Segoe UI, system-ui, -apple-system, Roboto, Arial, sans-serif"
    fontSize: "clamp(1.75rem, 1.2rem + 2vw, 2.5rem)"
    fontWeight: 650
    lineHeight: 1.2
    letterSpacing: "-0.025em"
  headline:
    fontFamily: "Atkinson Hyperlegible Next, Segoe UI, system-ui, -apple-system, Roboto, Arial, sans-serif"
    fontSize: "1.75rem"
    fontWeight: 650
    lineHeight: 1.2
    letterSpacing: "-0.015em"
  title:
    fontFamily: "Atkinson Hyperlegible Next, Segoe UI, system-ui, -apple-system, Roboto, Arial, sans-serif"
    fontSize: "1.375rem"
    fontWeight: 650
    lineHeight: 1.2
    letterSpacing: "-0.015em"
  body:
    fontFamily: "Atkinson Hyperlegible Next, Segoe UI, system-ui, -apple-system, Roboto, Arial, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.55
  label:
    fontFamily: "Atkinson Hyperlegible Next, Segoe UI, system-ui, -apple-system, Roboto, Arial, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 700
    lineHeight: 1.4
  call-ref:
    fontFamily: "Atkinson Hyperlegible Mono, ui-monospace, Cascadia Mono, Consolas, monospace"
    fontSize: "clamp(1.5rem, 1rem + 2.4vw, 2.5rem)"
    fontWeight: 700
    lineHeight: 1.05
    letterSpacing: "0.01em"
    fontFeature: "tnum"
  figure:
    fontFamily: "Atkinson Hyperlegible Mono, ui-monospace, Cascadia Mono, Consolas, monospace"
    fontSize: "1.75rem"
    fontWeight: 700
    lineHeight: 1.1
    fontFeature: "tnum"
rounded:
  sm: "4px"
  md: "6px"
  lg: "10px"
  pill: "999px"
spacing:
  s1: "4px"
  s2: "8px"
  s3: "12px"
  s4: "16px"
  s5: "24px"
  s6: "32px"
  s7: "48px"
  s8: "64px"
components:
  button-primary:
    backgroundColor: "{colors.night}"
    textColor: "{colors.surface}"
    rounded: "{rounded.md}"
    padding: "10px 18px"
    height: "46px"
  button-primary-hover:
    backgroundColor: "{colors.night-3}"
    textColor: "{colors.surface}"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "10px 18px"
    height: "46px"
  button-danger:
    backgroundColor: "{colors.stop}"
    textColor: "{colors.surface}"
    rounded: "{rounded.md}"
    padding: "10px 18px"
    height: "46px"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "0 12px"
    height: "48px"
  card:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.lg}"
    padding: "24px"
  pill-live:
    backgroundColor: "{colors.amber-soft}"
    textColor: "{colors.amber-ink}"
    rounded: "{rounded.pill}"
    padding: "3px 9px"
  pill-ok:
    backgroundColor: "{colors.ok-soft}"
    textColor: "{colors.ok}"
    rounded: "{rounded.pill}"
    padding: "3px 9px"
  pill-stop:
    backgroundColor: "{colors.stop-soft}"
    textColor: "{colors.stop}"
    rounded: "{rounded.pill}"
    padding: "3px 9px"
  pill-wait:
    backgroundColor: "{colors.surface-2}"
    textColor: "{colors.ink-2}"
    rounded: "{rounded.pill}"
    padding: "3px 9px"
  pill-info:
    backgroundColor: "{colors.info-soft}"
    textColor: "{colors.info}"
    rounded: "{rounded.pill}"
    padding: "3px 9px"
  tab-count:
    backgroundColor: "{colors.amber}"
    textColor: "{colors.night}"
    rounded: "{rounded.pill}"
    padding: "0 6px"
    height: "22px"
  call-plate:
    backgroundColor: "{colors.night}"
    textColor: "{colors.night-ink}"
    rounded: "{rounded.lg}"
    padding: "24px"
  call-state-live:
    backgroundColor: "{colors.amber}"
    textColor: "{colors.night}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
  call-state-ok:
    backgroundColor: "{colors.ok}"
    textColor: "{colors.surface}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
  call-state-stop:
    backgroundColor: "{colors.stop}"
    textColor: "{colors.surface}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
  rail-item:
    backgroundColor: "{colors.night}"
    textColor: "{colors.night-muted}"
    rounded: "{rounded.md}"
    padding: "0 12px"
    height: "42px"
  rail-item-selected:
    backgroundColor: "{colors.night-3}"
    textColor: "{colors.surface}"
---

# Design System: CitaYa

## Overview

**Creative North Star: "El tablero de turnos"**

CitaYa se diseña como el digiturno de una sala de espera colombiana: cada solicitud es un turno que se llama. El radicado es la cifra que manda y su estado se lee a un metro de distancia. Un tablero azul noche (barra superior en el portal, barra más rail lateral en la consola interna) enmarca un suelo claro y frío donde viven superficies blancas separadas por filetes de 1px. La interfaz habla en español de Colombia, con fechas `dd/mm/aaaa`.

El sistema rechaza el portal clínico blanco-y-azul de tarjetas iguales y el panel gris con un acento decorativo. El color es semántico, no ornamental: el ámbar es dueño exclusivo de lo que está en curso, el verde confirma, el rojo detiene, y ninguno aparece sin ícono y texto. La densidad cambia según quién mira: el paciente, casi siempre en móvil, ve una sola columna ancha con campos de 48px y una acción por paso; el personal, en escritorio, ve tablas densas con cifras tabulares en mono.

Todo funciona sin JavaScript y bajo una CSP `style-src 'self'`: no hay un solo estilo en línea. Barras y gráficos se dibujan con atributos SVG, no con `style="width:…"`. Tipografía y fuentes son autoalojadas (Atkinson Hyperlegible Next y Mono, OFL).

**Key Characteristics:**
- Tablero azul noche como marco; suelo claro frío; superficies blancas con filetes de 1px.
- La firma: el radicado «llamado» en una placa invertida con su estado en pastilla de color.
- Ámbar solo para lo vivo; verde y rojo siempre con ícono y texto.
- Una sola familia tipográfica (Atkinson Hyperlegible) con su variante mono para radicados, cédulas y cifras.
- Esquinas de 6px y 10px, sin degradados suaves ni biseles; plano en reposo.
- Cero estilos en línea; SVG con atributos para todo lo que se mide.

## Colors

Un tablero nocturno, un suelo de papel frío y tres tonos de estado que nunca decoran.

### Primary
- **Azul noche de tablero** (`night`): barra superior, rail de la consola, placa del radicado, encabezados de modales, botón primario, paso actual del riel y las barras de participación del análisis. Sus escalones `night-2` (hover sobre noche) y `night-3` (elemento seleccionado en el rail y en la navegación superior) dan profundidad sin sombras; `night-line` separa dentro del tablero.
- **Tinta del tablero** (`night-ink`) y **gris de tablero** (`night-muted`): texto sobre noche; el segundo para etiquetas y navegación inactiva.

### Secondary
- **Ámbar de turno en curso** (`amber`): el único acento cálido y el dueño exclusivo de lo vivo: estado «en cola» / «enviando» en la placa, la línea de barrido inferior de la placa viva, el relleno de la barra de progreso de cola, los contadores del rail (`tab-count`) y la cifra del tablero de turnos en Solicitudes cuando hay al menos una en espera. Sus derivados `amber-soft` (fondo), `amber-line` (borde) y `amber-ink` (texto) forman la pastilla «en curso», la nota pendiente del caso y el reporte sin leer. `amber-strong` existe como token pero hoy no se usa en ningún componente.

### Tertiary
- **Verde de confirmación** (`ok`, `ok-soft`, `ok-line`): enviado, disponible, aprobado, pasos completados. Sobre la placa, el estado «enviado» va en verde lleno con texto blanco.
- **Rojo de detención** (`stop`, `stop-soft`, `stop-line`): fallido, incierto, no disponible, retirado, alertas de error y validación. Sobre la placa, rojo lleno con texto blanco.
- **Azul informativo** (`info`, `info-soft`, `info-line`): avisos neutrales, enlaces y botones de texto, íconos de sección del expediente. `focus` comparte su valor y dibuja todos los anillos de foco.

### Neutral
- **Suelo frío** (`ground`): fondo de la página.
- **Superficie** (`surface`) y **superficie hundida** (`surface-2`): tarjetas y campos; cabeceras de tabla, segmentados, zonas de carga y columna de datos del expediente.
- **Filete** (`line`) y **filete fuerte** (`line-strong`): divisiones de 1px entre superficies; bordes de 1.5px de campos y botones secundarios.
- **Tinta** (`ink`, mismo valor que `night`), **tinta secundaria** (`ink-2`) y **gris de apoyo** (`muted`): texto principal, etiquetas de campo y texto auxiliar.

### Named Rules
**The Amber Owns Live Rule.** El ámbar solo marca lo que está ocurriendo ahora: en cola, enviando, reintento, pendiente de verificación, pendientes por responder, contadores. Un estado terminado nunca es ámbar; un elemento decorativo nunca es ámbar. Si la cifra del tablero de turnos es 0, va en blanco, no en ámbar.

**The Never Color Alone Rule.** Verde confirma, rojo detiene, ámbar está en curso, y los tres van siempre con ícono y texto. Una pastilla de estado sin ícono propio recibe su glifo por máscara CSS (visto para ok, reloj para en curso, triángulo para detenido, lupa para en espera).

**The Status Mapping Rule.** El tono sale del estado, no de la plantilla: `queued`, `sending`, `retry`, `pending_verification` → en curso; `sent`, `simulated`, `matched`, `verified` → listo; `failed`, `uncertain`, `unavailable`, `not_eligible`, `expired`, `cancelled`, `not_registered` → detenido; todo lo demás → en espera.

**Decisión abierta: el ámbar del logotipo.** La marca (boleto con una cruz, 30px) usa `amber` como relleno en la barra noche. Si el logotipo queda exento de la regla «ámbar solo para lo vivo» o debe cambiar de color está pendiente de decisión del titular. No usar el logotipo como precedente para otros usos no vivos del ámbar mientras se resuelve.

## Typography

**Display Font:** Atkinson Hyperlegible Next (con Segoe UI, system-ui, Roboto, Arial)
**Body Font:** Atkinson Hyperlegible Next
**Label/Mono Font:** Atkinson Hyperlegible Mono (con ui-monospace, Cascadia Mono, Consolas)

**Character:** Una tipografía diseñada para la legibilidad de baja visión, en tres pesos (400, 600, 800), con su gemela mono para todo lo que se dicta o se compara: radicados, documentos, fechas y cifras. Se lee como un tablero público, no como un folleto.

### Hierarchy
- **Display** (650, `clamp(1.75rem, 1.2rem + 2vw, 2.5rem)`, 1.2, -0.025em): el título de tarea del portal del paciente («Tu historia clínica, en tu correo.»).
- **Headline** (650, 1.75rem, 1.2): título de página de la consola y de la tarjeta de acceso. El `h1` base usa 2.25rem.
- **Title** (650, 1.375rem / 1.125rem, 1.2): encabezados de tarjeta (`h2`) y de subsección (`h3`); títulos de modales en 1.125rem.
- **Body** (400, 1rem, 1.55): texto corrido; párrafos introductorios en 1.125rem con medida de 60–62ch; `text-wrap: pretty`.
- **Label** (700, 0.875rem): etiquetas de campo, segmentados, navegación (600). Texto auxiliar y pastillas en 0.8125rem.
- **Radicado llamado** (mono 700, `clamp(1.5rem, 1rem + 2.4vw, 2.5rem)`, 1.05, 0.01em): el número de la placa; nunca se parte (`white-space: nowrap` en tablas).
- **Cifra de tablero** (mono 700, 1.75rem, 1.1, tabular): valores de estadísticas, totales de actividad, contadores.

### Named Rules
**The Tabular Truth Rule.** Todo lo que se dicta, se copia o se compara (radicado, cédula, fecha, ingreso, conteos, porcentajes) va en Atkinson Hyperlegible Mono con `tabular-nums`. El texto que se lee va en Next.

**The Called Number Label Rule.** La única etiqueta en mayúsculas con tracking (0.8125rem, 700, 0.06em) es la que nombra la cifra llamada: «RADICADO» sobre la placa y en el encabezado del progreso. Es el rótulo de un dato, no un sobretítulo de sección; no se extiende a encabezados.

## Layout

El portal del paciente vive en un contenedor de 1120px: título de tarea, riel de tres pasos (tope de 760px, cada paso marcado por una barra superior de 3px: noche si es actual, verde si está hecho) y una rejilla de formulario ancho + columna de ayuda de 300px que se pega a 84px del tope. La consola interna es una rejilla de rail de 248px + área de trabajo (máximo 1320px), con el rail pegado bajo la barra de 63px.

El ritmo es una escala de 4px: `s1` 4 · `s2` 8 · `s3` 12 · `s4` 16 · `s5` 24 · `s6` 32 · `s7` 48 · `s8` 64. Las tarjetas usan 24px de relleno (32px la del formulario principal); las secciones apiladas se separan 24px.

Respuesta (escritorio primero):
- **≤1180px:** el contenedor gana relleno lateral de 24px; el flujo de carga pasa de 5 a 3 columnas.
- **≤960px:** el portal pasa a una columna (la ayuda baja); el rail se convierte en barra horizontal desplazable con máscara de desvanecido a la derecha y el indicador de selección pasa al borde inferior; estadísticas a 2×2; expediente del caso a una columna.
- **≤700px:** las tablas `responsive-table` se convierten en tarjetas con etiquetas `data-label`; el encabezado sigue disponible para lectores de pantalla.
- **≤650px:** la barra baja a 56px; campos y formularios a una columna; botones de 48px y objetivos táctiles de 44px; la placa del radicado se extiende de borde a borde de la tarjeta con el estado a todo el ancho debajo del número; modales a `100% - 16px`.

**The One Plate Per Screen Rule.** Cada pantalla tiene como máximo una placa invertida dominante (el radicado llamado, el tablero de turnos o el resumen del análisis). Es la cifra que manda; si dos compiten, ninguna manda.

## Elevation & Depth

Plano en reposo. La profundidad se construye con tono (el tablero noche frente al suelo claro, `surface` frente a `surface-2`) y con filetes de 1px, no con sombras. La única sombra es la de lo que flota sobre la página: diálogos, el menú desplegable de decisión del caso y los avisos emergentes. El fondo de los diálogos es noche al 62%.

### Shadow Vocabulary
- **Pop** (`box-shadow: 0 18px 48px rgba(15,27,45,.24), 0 2px 6px rgba(15,27,45,.12)`): modales de detalle, progreso, resultado de carga, reapertura, menú de decisión y avisos emergentes. Nada más.

### Named Rules
**The Float-Only Shadow Rule.** Una superficie lleva sombra solo si flota sobre el contenido (diálogo, menú, aviso). Tarjetas, tablas y placas nunca la llevan. **Excepción desde 2026-10-19:** en la consola interna con fondo animado, tarjetas, rail y bandas de cifras son de vidrio, translúcidas y con una sombra suave que las separa del fondo en movimiento. Sin `backdrop-filter`.

## Shapes

Esquinas contenidas y consistentes: 6px (`radius`) para botones, campos, filas, avisos y pastillas cuadradas de estado de la placa; 10px (`radius-lg`) para tarjetas, placas, modales y bandas de cifras; 4px para piezas internas (código en línea, opciones del segmentado y del menú); 999px solo para pastillas y contadores. Los bordes interactivos son de 1.5px (campos, botones secundarios, zonas de carga punteadas); las divisiones estructurales son de 1px. Los números de paso son cuadrados redondeados de 32px, no círculos. Sin biseles ni degradados suaves: los únicos `linear-gradient` del sistema son de corte duro (el segmento ámbar de barrido de la placa viva y la columna del rail) o máscaras de desvanecido.

## Components

### Buttons
Sólidos y directos, con la acción principal como una placa noche.
- **Shape:** esquinas de 6px; altura mínima de 46px (48px en móvil); borde de 1.5px.
- **Primary:** fondo noche, texto blanco, peso 650; un ícono de flecha opcional a la derecha. Sobre una superficie noche (tablero de turnos, aviso emergente) se invierte: fondo blanco, texto noche.
- **Hover / Focus:** hover pasa a `night-3`; al presionar baja 1px; foco con contorno de 3px en `focus` a 2px de separación (blanco dentro del tablero).
- **Secondary:** fondo blanco, borde `line-strong`, texto tinta; hover lleva el borde a noche.
- **Danger:** rojo lleno con texto blanco, solo para acciones destructivas.
- **Text button:** azul informativo subrayado de 1px; en rojo para «Retirar».
- **Disabled / busy:** opacidad 0.55.

### Chips (pastillas de estado)
- **Style:** cápsula de 999px, 0.8125rem peso 700, borde de 1px con el tono `-line`, fondo `-soft`, texto en el tono pleno (`amber-ink` para en curso). Ícono de 14px o glifo por máscara de 12px.
- **State:** `live` (en curso), `ok` (listo), `stop` (detenido), `wait` (en espera, gris), `info` (informativo). Los plazos de respuesta (SLA) reutilizan los mismos tonos.

### Cards / Containers
- **Corner Style:** 10px.
- **Background:** `surface` sobre `ground`.
- **Shadow Strategy:** ninguna (ver Elevation & Depth).
- **Border:** 1px `line`; los encabezados de tarjeta cierran con un filete de 1px.
- **Internal Padding:** 24px (32px el formulario del paciente; 16px en móvil).

### Inputs / Fields
- **Style:** 48px de alto, fondo blanco, borde de 1.5px `line-strong`, esquinas de 6px, 16px de texto para evitar el zoom en móvil. Los campos de documento y fecha usan la mono. Los `select` llevan un chevrón SVG propio.
- **Focus:** borde noche más doble anillo (3px `info-soft` y 1px `info`).
- **Error / Disabled:** `:user-invalid` pinta el borde en rojo; deshabilitado en `surface-2` con texto `muted`.
- **Fieldset:** la leyenda es una etiqueta de 800 seguida por un filete que llena el ancho.

### Navigation
- **Barra superior:** 60px noche, marca a la izquierda (logotipo + «CitaYa» 700 + contexto separado por filete), enlaces de 0.875rem/600 en `night-muted`; hover `night-2`, actual `night-3` con texto blanco.
- **Rail de la consola:** 248px noche, ítems de 42px con ícono de 19px, `night-muted` en reposo, `night-3` y una barra blanca de 3px a la izquierda cuando está seleccionado; un filete separa el grupo de trabajo diario (Solicitudes, Reportes, Mi avance) de la administración. Los contadores ámbar van al final del ítem. La sesión se cierra al pie. En móvil se vuelve una barra horizontal desplazable.
- **Segmentado:** opciones de 36px en un canal `surface-2`; la activa en noche con texto blanco.

### Placa del radicado llamado (firma)
La placa invertida: fondo noche, esquinas de 10px, «RADICADO» como rótulo en `night-muted` y el número en mono grande blanco. Debajo, el estado como una pastilla cuadrada de 6px: ámbar lleno con texto noche si está en curso (más una línea de barrido ámbar de 4px en el borde inferior, estática con movimiento reducido), verde o rojo lleno con texto blanco si terminó, `night-2` si espera. Variante compacta para modales. En móvil ocupa todo el ancho superior de la tarjeta.

### Tablero de turnos
Banda noche en Solicitudes con el total en espera en mono 2.25rem y su rótulo en minúsculas; la cifra pasa a ámbar solo si hay al menos una en espera; acción primaria invertida para abrir la más antigua.

### Cifras y tablas
- **Estadísticas:** banda blanca de 10px dividida por filetes, etiqueta en `muted` y cifra mono 1.75rem.
- **Tablas:** cabecera `surface-2` pegajosa a 0.8125rem/700; filas con filete de 1px; en móvil, tarjetas etiquetadas.
- **Gráficas del análisis (desde 2026-10-17, pedido del titular):** el módulo abre con tres cifras del mes y su variación frente al mes anterior. Le sigue una gráfica de crecimiento de 12 meses (líneas de 2px, un solo eje, cruz que busca el mes con aviso flotante) y dos donas: especialidades (las 5 con más atenciones con color, el resto en «Otras», gris) y canal de la solicitud. Las donas tienen leyenda con cifra y porcentaje y un total en el centro, y cada gráfica deja su tabla en «Ver los datos». La paleta categórica es fija y validada para daltonismo (azul, naranja, aguamarina, amarillo, magenta) y se asigna por clase (`sc1…sc5`, `sc-other`), nunca con estilos en línea, porque la CSP no los permite. Los textos van en tinta, nunca en el color de la serie.

### Diálogos y progreso
Encabezado noche pegajoso con título blanco y botón de cierre de 44px con filete `night-line`; cuerpo blanco; entrada de 0.28s con `cubic-bezier(.16,1,.3,1)`. La barra de progreso de cola es una pista de 8px con relleno ámbar en barrido (noche para la carga de archivos con porcentaje; rojo si falla).

### Íconos
Conjunto propio de trazo único de 1.8px en cuadrícula de 24, `currentColor`, 14–26px según contexto, siempre `aria-hidden` junto a texto.

## Do's and Don'ts

### Do:
- **Do** reservar `amber` para estados en curso, contadores y la cifra del tablero cuando es mayor que 0.
- **Do** acompañar todo verde, rojo o ámbar de estado con un ícono y una palabra; si la pastilla no trae ícono, dejar que la máscara CSS ponga el glifo.
- **Do** derivar el tono de un estado con el mapeo único de estados (en curso / listo / detenido / en espera).
- **Do** escribir radicados, documentos, fechas y cifras en Atkinson Hyperlegible Mono con `tabular-nums`.
- **Do** dibujar barras, progresos y gráficos con atributos SVG (`width`, `x`, `fill` por clase), nunca con estilos en línea.
- **Do** mantener campos de 16px y objetivos táctiles de 44px (botones de 48px) en móvil.
- **Do** usar filetes de 1px y el contraste noche / suelo para separar; la sombra `--shadow-pop` solo en lo que flota.
- **Do** redactar la interfaz en español de Colombia y con fechas `dd/mm/aaaa`.

### Don't:
- **Don't** usar atributos `style` ni bloques `<style>`: la CSP `style-src 'self'` los bloquea.
- **Don't** pintar en ámbar un estado terminado, un botón, un encabezado o un adorno.
- **Don't** comunicar un estado solo con color.
- **Don't** pasar de 5 colores en una dona ni rotar el orden de la paleta: el resto va a «Otras». Tampoco quitar la tabla de respaldo de una gráfica.
- **Don't** poner dos placas invertidas dominantes en la misma pantalla.
- **Don't** añadir sombras a tarjetas, tablas o placas fuera de la consola con fondo animado, ni degradados suaves o biseles. Tampoco usar `backdrop-filter` sobre el fondo animado: se recalcula en cada cuadro.
- **Don't** convertir el rótulo «RADICADO» en un patrón de sobretítulos en mayúsculas para secciones.


## Consola interna: fondo animado, vidrio y tema claro/oscuro (2026-10-19)

A pedido del titular, la consola del personal toma el lenguaje del login y del formulario del paciente.
- **Fondo:** el mismo `ambient.php`, en variante `console`: halos, barrido, 4 formas y 7 chispas (el login usa 10 y 14), más tenues. Solo anima `transform` y `opacity` (15 animaciones), se pausa mientras se escribe y se queda quieto con «reducir movimiento».
- **Tema:** oscuro por defecto y claro con el botón del encabezado. La elección se comparte con el login (`localStorage` `citaya-theme`). El oscuro redefine los tokens (`--ground`, `--surface`, `--surface-2`, `--line`, `--ink`, `--muted` y los de estado) bajo `body.console-ambient`, así los componentes no se tocan uno por uno.
- **Vidrio:** tarjetas, rail, encabezado y bandas de cifras usan `--glass`, una translucidez con un borde interior claro y una sombra suave. Radio de 14px en las tarjetas.
- **Tipografía:** cada título de página lleva arriba el rótulo «Acceso interno» en mono, como el `login-kicker`; el título y el texto de apoyo son más grandes y hay más aire arriba.
