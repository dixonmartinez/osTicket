# Guía de interfaz y accesibilidad

Este documento explica la capa de mejoras visuales añadida y cómo
extenderla **sin romper** la aplicación ni los temas existentes.

---

## 1. Objetivo y criterio

La interfaz de osTicket funciona, pero arrastra tres problemas habituales:

1. **Accesibilidad**: sin foco visible, sin enlace de salto, mensajes de
   error que no se anuncian a lectores de pantalla, etiquetas ausentes en
   los formularios de acceso.
2. **Consistencia**: botones, campos y tablas se comportan distinto entre el
   portal y el panel.
3. **Móvil**: las tablas de datos (colas, listados) desbordan la pantalla.

El criterio seguido es **no reescribir el tema**: se añade una capa CSS
(`css/ui.css`) que se carga al final y sólo cambia color, espaciado, foco,
estados y comportamiento en pantallas pequeñas. Si se elimina el archivo y
sus dos etiquetas `<link>`, todo vuelve exactamente al estado anterior.

---

## 2. Qué se ha cambiado

### 2.1 `css/ui.css` (nuevo)

| Bloque | Efecto |
|---|---|
| Variables (`:root`) | Paleta y radios centralizados; permite re-tematizar sin tocar decenas de reglas |
| `.sr-only` | Texto sólo para lectores de pantalla |
| `.skip-link` | Enlace "Saltar al contenido" visible al recibir foco |
| `:focus-visible` | Anillo de foco consistente en enlaces, botones, campos y elementos con `tabindex` |
| Formularios | Radio, transiciones, estado `disabled`/`readonly`, `accent-color` en casillas, validación nativa legible |
| Botones | Hover/activo/deshabilitado coherentes, área mínima de pulsación |
| Tablas | Resalte de fila, cabecera marcada, enlaces de ordenación con subrayado al pasar, cifras tabulares |
| Mensajes | `error_bar`/`warning_bar`/`notice_bar` con altura automática (antes se recortaban los mensajes largos) |
| Móvil (< 782 px) | Las tablas hacen scroll dentro de su bloque en vez de romper la página; los campos no desbordan |
| `prefers-reduced-motion` | Desactiva animaciones para quien lo pide en el sistema |
| `prefers-contrast: more` | Bordes y focos reforzados |
| Impresión | Oculta navegación, muestra la URL de los enlaces, evita cortes raros |

### 2.2 Plantillas

| Archivo | Cambio |
|---|---|
| `include/client/header.inc.php` | Carga `ui.css`; enlace "Saltar al contenido"; región `role="status"` con `aria-live` para los mensajes; `role="alert"` en errores/avisos; carga del tema y botón de cambio |
| `include/staff/header.inc.php` | Ídem para el panel |
| `include/staff/login.header.php` | Carga `ui.css`, el tema y el botón (flotante en la esquina) |
| `include/templates/theme-toggle.inc.php` | Botón de cambio de tema con SVG en línea (nuevo) |
| `include/client/login.inc.php` | `<label>` accesible, `autocomplete="username"`/`current-password`, atributo `required`, autocorrección desactivada en el usuario |
| `include/staff/login.tpl.php` | Ídem + `role="alert"` en el mensaje de error de acceso (se anuncia al fallar) |

### 2.3 Resultado esperado

- Navegación completa con teclado (Tab) mostrando siempre dónde está el foco.
- Los lectores de pantalla anuncian errores de login y avisos del sistema.
- Los gestores de contraseñas reconocen y rellenan los campos de acceso.
- Las tablas del panel son usables en un móvil sin hacer zoom.
- Ningún cambio en la lógica ni en los permisos.

---

### 2.4 Móvil (`css/mobile.css`)

Hoja **aditiva**: todo su contenido vive dentro de `@media (max-width: 1040px)`,
así que en escritorio no cambia ni un píxel. Qué hace:

* contenedor de 960 px → fluido, con desplazamiento horizontal contenido en el
  área de contenido (las tablas anchas se leen completas);
* barras de pestañas desplazables en horizontal, con objetivos táctiles de 40 px;
* campos de formulario a 16 px para evitar el zoom automático de iOS;
* utilidad `table.stack` para convertir tablas en tarjetas apiladas
  (`data-label` en cada `<td>`);
* reglas de impresión.

Se carga desde `include/staff/header.inc.php`, después de `css/ui.css` y antes
de `css/dark.css`, que es la última hoja del `<head>`.
Guía de uso y de la PWA: [`MOBILE.md`](MOBILE.md).

### 2.5 Panel de analíticas (`scp/css/analytics.css`)

Diseñado primero para móvil: rejilla de tarjetas que colapsa a dos columnas,
tablas que se convierten en tarjetas con `data-label`, gráficas SVG sin
JavaScript, modo de alto contraste y `prefers-reduced-motion`. La vista se
puede ver sin base de datos en [`analytics-preview.html`](analytics-preview.html).

### 2.6 Modo oscuro (`css/dark.css` + `js/theme.js`)

Capa independiente que se activa con `data-theme="dark"` en `<html>`. Cubre el
panel de agentes, el portal del cliente y las dos pantallas de acceso. Detalle
completo en [§3.4](#34-modo-oscuro).

## 3. Cómo personalizar

### 3.1 Cambiar colores

Edita las variables al inicio de `css/ui.css`:

```css
:root {
    --ui-accent: #0b63ce;        /* color principal */
    --ui-accent-soft: rgba(11, 99, 206, .12);
    --ui-border: #c9d3dc;
    --ui-radius: 6px;            /* esquinas */
}
```

Afecta a foco, botones, casillas, filas de tabla y mensajes. El resto del
tema (`assets/default/css/theme.css`) no se toca.

### 3.2 Sustituir la capa por un tema propio

`assets/default/` es el directorio pensado para personalizaciones. Si
quieres un tema completo:

1. Copia `assets/default/` a `assets/<mi-tema>/`.
2. Cambia `ASSETS_PATH` (configuración → *Settings → Pages*) o el valor en
   `client.inc.php`.
3. Deja `css/ui.css` cargado **después** del tema para conservar las
   mejoras de accesibilidad, o intégralas en tu tema.

### 3.3 Añadir reglas propias

Crea `css/` local y cárgalo desde tu tema, no edites `css/ui.css` si vas a
recibir actualizaciones del proyecto. Si necesitas una excepción puntual:

```css
/* en tu hoja personalizada, cargada después */
#mi-pantalla .boton { border-radius: 2px; }
```

### 3.4 Modo oscuro

El tema oscuro es una capa independiente (`css/dark.css`) que se activa con
`data-theme="dark"` en `<html>`. No reescribe `scp.css` ni `theme.css`: los
sobrescribe con selectores de mayor especificidad, así que una actualización
del tema base no lo rompe.

**Cómo se elige el tema**, por orden de prioridad:

1. Lo que haya elegido el usuario con el botón de la cabecera (se guarda en
   `localStorage`, clave `ost-theme`; no hay cookies ni estado en el servidor).
2. `OST_DEFAULT_THEME` en `.env`: `auto` (por defecto), `dark` o `light`.
3. `auto` resuelve con `prefers-color-scheme`, y **sigue al sistema en vivo**
   si el usuario cambia de tema claro a oscuro en su equipo.

```bash
# .env — todo el helpdesk en oscuro salvo quien elija otra cosa
OST_DEFAULT_THEME=dark
```

```bash
docker compose up -d php     # la variable se lee del entorno al arrancar
```

`js/theme.js` se carga en el `<head>`, antes del `<body>`, y aplica el tema
antes del primer pintado: no hay destello blanco al navegar.

**Dónde está cada cosa**

| Archivo | Papel |
|---|---|
| `css/dark.css` | Todos los colores del tema oscuro y la redefinición de los tokens `--ui-*` |
| `js/theme.js` | Resolución de la preferencia, persistencia y botón |
| `include/templates/theme-toggle.inc.php` | Marcado accesible del botón (panel y portal) |
| `include/staff/header.inc.php`, `include/client/header.inc.php` | Carga del CSS/JS (tema al final del `<head>`) y colocación del botón |
| `include/staff/login.header.php` | Lo mismo en las pantallas de acceso y recuperación de contraseña |
| `tools/ui/theme-check.php` | Comprobación automática (`make theme`) |
| `tools/ui/contrast-audit.js` | Medición de contraste con navegador real |
| `docs/theme-preview.html` | Maqueta con el marcado real, para revisar sin Docker |
| `include/class.osticket.php` → `getThemeDefault()` | Lectura de `OST_DEFAULT_THEME` |
| `css/density.css` | Modo compacto (solo espaciado) |
| `js/dialog-focus.js` | Foco atrapado en los diálogos y devuelto al cerrarlos |

**Densidad de la interfaz.** Junto al botón de tema hay otro botón que alterna entre espaciado **cómodo** (el original de osTicket) y
**compacto** (filas, formularios y pestañas más juntos, útil en pantallas
pequeñas o con muchas colas). Se guarda en `localStorage` con la clave
`ost-density` y se aplica como `data-density="compact"` en `<html>`, antes del
primer pintado, igual que el tema:

```bash
# .env — todo el equipo arranca en compacto salvo quien elija otra cosa
OST_DEFAULT_DENSITY=compact
```

`css/density.css` **solo** cambia `padding` y `line-height`: no toca tamaños de
fuente ni colores, así que no puede romper el contraste ni los objetivos
táctiles de móvil. Se carga después de `css/dark.css` (el tema manda en
colores, esta hoja en espaciado) y `make theme` comprueba ese orden.

**Añadir un componente nuevo.** Usa los tokens en lugar de colores fijos:

```css
.mi-componente {
    background: var(--dk-surface-2);
    border: 1px solid var(--dk-border);
    color: var(--dk-text);
}
```

Dentro de `html[data-theme="dark"]` esos tokens existen; para el tema claro usa
`var(--ui-surface, #fff)` y compañía, que ya están definidos en `css/ui.css`.

**Los correos no se invierten a propósito.** El cuerpo de una entrada de ticket
es HTML ajeno, con sus propios colores, tablas e imágenes. Invertirlo produce
resultados ilegibles. En oscuro se muestra sobre un “papel” atenuado
(`--dk-paper`, `#e8ecf0`): menos brillo que el blanco puro, pero fiel al
original.

**Impresión**: `@media print` fuerza siempre el tema claro y oculta la
cabecera y los menús, para no gastar tóner en fondos oscuros.

**Contraste** (verificado con la fórmula WCAG):

| Combinación | Ratio | Nivel |
|---|---|---|
| Texto principal `#e6ebf0` sobre fondo `#12161b` | 15,1:1 | AAA |
| Texto principal sobre superficie `#171d24` | 14,1:1 | AAA |
| Texto secundario `#a6b2be` sobre fondo `#12161b` | 8,4:1 | AAA |
| Enlaces `#83b7ee` sobre superficie `#171d24` | 8,1:1 | AAA |
| Texto sobre “papel” de correo (`#1a1f24` / `#e8ecf0`) | 14,0:1 | AAA |
| Error (`#ffd7d3` sobre `#3a1d1d`) | 11,6:1 | AAA |
| Aviso (`#f7e3ad` sobre `#352b12`) | 11,0:1 | AAA |
| Correcto (`#c7f2cd` sobre `#14301d`) | 11,6:1 | AAA |

Los ratios están medidos con la fórmula de WCAG 2.1, no estimados: al cambiar
un color del tema, recalcula antes de publicar.

**Vista previa sin Docker**: abre `docs/theme-preview.html` en el navegador.
Carga los CSS reales y trae el botón para alternar. (También puedes servir el
repositorio con `python3 -m http.server 8099` y entrar en
`http://localhost:8099/docs/theme-preview.html`.)

**Huecos corregidos tras probarlo en un navegador de verdad.** El primer
intento cubría la estructura pero dejaba piezas con el fondo claro del tema
base y el texto claro del oscuro. Se localizaron midiendo el contraste
renderizado, no a ojo:

| Zona | Síntoma |
|---|---|
| `#nav li > ul` (menús desplegables de la barra superior) | Fondo blanco con texto claro: ilegible |
| `#basic_search` | Franja blanca alrededor del buscador |
| `.sticky.bar.opaque` | Barra de la cola de tickets en blanco |
| `ul.tabs` y sus pestañas | Pestañas blancas |
| `.form_table th h4` | Título de formulario sobre gris `#929292` |
| `.label` / `.label-info` / `.label-bare` | Etiquetas de estado con fondo claro |
| `table tfoot`, paginación (`Page: [n]`) | Se leían mal sobre el fondo oscuro |
| `h2` de sección | Azul `#0A568E` sobre oscuro (2.2:1) |
| `.select2-highlighted` | 4.48:1, justo por debajo del mínimo |
| Enlaces del cuerpo del correo | `thread.css` los fija con `!important` a `#428bca` (3:1 sobre el papel) |
| `.skip-link` | Fondo azul claro con texto blanco |
| Desplegable de colas (`#customQ_nav .customQ-dropdown`) | `li.top-level`, `personalQ`, `.add-queue` y enlaces en blanco; el `li:hover` azul se comía el texto del `span` |
| Barra de colas: `.jb-overflowmenu-container` | Gris `#eee` detrás de todo el menú |
| `#sub_nav .active a` | Regla demasiado ancha: pintaba de azul también los enlaces del desplegable |
| `#ticket_notes` | Celdas y `.info` en `#f9f9f9` |
| `.thread-preview-entry` (colapsada y cabeceras) | Gris y pasteles azul/naranja/amarillo con texto claro |
| `.freetext-files`, `.tip_content`, `#resizable-columns`, `.custom-data thead th` | Bloques de ayuda y ficheros en blanco/gris |
| Portal del cliente: `#ticketTable`, `tr.alt`, `#reply`, `#clientLogin`, `#ticketForm`, `#faq` | Campos blancos y bordes claros de `assets/default/css/theme.css` |
| `.action-button` del portal | Texto `#555` sobre superficie oscura (2.3:1) |
| Analíticas (`.an-page`) | Tokens `--an-*` con `#ffffff` y `#1f2933`: tarjetas, tablas y gráficas en claro |
| Panel de inicio (`.dashboard-kpis`, `#line-chart-here`) | Tarjetas `#fff` y gráfica con degradado claro |
| Traducciones en línea (`div.translations`, `div.add-translation`) | Menú blanco y pie gris `#eee` |
| Responder (`#ticket_actions`, `#response_options`, `.selected-signature`) | Barra `#eee`, `textarea` blanca y firma sobre blanco |
| Nota rápida y acciones flotantes (`.quicknote a.action`, `.floating-options a.action`) | `color:black !important` sobre fondo claro |
| Filas y campos deshabilitados (`tr.disabled`, `input:disabled`) | Gris `#f5f5f5` con texto claro |

**Comprobación automática**: `make theme` (dentro del contenedor,
`docker compose run --rm tools make theme`). Verifica que los cuatro archivos
existen, que los tokens están definidos, que las **tres** cabeceras —panel,
portal y acceso del personal— cargan el tema en el orden correcto, que el
service worker lo precachea y que `getThemeDefault()` valida el valor. Forma
parte de `make ci` y de `make docker-test`.

**Si añades una pantalla nueva** con su propia cabecera HTML, cópiale las tres
líneas (atributo `data-theme-default`, `js/theme.js` antes de jQuery,
`css/dark.css` como **última** hoja del `<head>`) y el `include` del botón.
`make theme` fallará hasta que lo hagas.

---

## 4. Reglas para no romper nada

1. **No cambies el marcado estructural** (`#container`, `#content`,
   `#pjax-container`): PJAX y los scripts dependen de ellos.
2. **No uses `!important`** salvo para corregir una regla inline existente
   (ya hay algunas en el núcleo).
3. **No apliques `overflow` a `#content`**: convierte el contenedor en un
   *scroll box* y rompe menús flotantes y diálogos. Usa el patrón aplicado
   a `table.list`/`table.queue` en móvil.
4. **No ocultes con `display:none`** elementos que contienen datos: usa
   `.sr-only` para mantenerlos accesibles.
5. **Contraste mínimo 4.5:1** para texto normal y 3:1 para texto grande.
   Comprueba con las herramientas del navegador.
6. **Prueba con teclado**: Tab, Shift+Tab, Enter, Espacio y Escape en
   formularios, diálogos y menús.
7. **Prueba en ventana estrecha** (< 782 px) y con zoom al 200 %.
8. **No escribas colores fijos en componentes nuevos**: usa los tokens
   (`--ui-*` en claro, `--dk-*` dentro de `html[data-theme="dark"]`). Si un
   color queda fijo, en uno de los dos temas se rompe el contraste.

---

## 5. Verificación rápida

Checklist manual (5 minutos):

- [ ] Tab desde el inicio de la página muestra "Saltar al contenido".
- [ ] Todos los campos de acceso tienen etiqueta (visible u oculta) y se
      rellenan con el gestor de contraseñas.
- [ ] Al fallar el login, el mensaje se anuncia (probado con NVDA/VoiceOver
      o inspeccionando `role="alert"`).
- [ ] Las tablas de `scp/tickets.php` y `scp/directory.php` se desplazan en
      móvil sin desbordar la página.
- [ ] Los mensajes largos de `error_bar`/`warning_bar` no se recortan.
- [ ] Con `prefers-reduced-motion: reduce` no hay animaciones.
- [ ] Impresión de un ticket: sin menús y con las URLs visibles.
- [ ] El botón de la cabecera alterna claro/oscuro y **recuerda** la elección
      al recargar y al navegar a otra pantalla.
- [ ] El botón de densidad alterna cómodo/compacto, lo recuerda y no deja
      texto cortado en las filas de la cola.
- [ ] Con un diálogo abierto, Tab y Shift+Tab no salen de él; al cerrarlo con
      Escape el foco vuelve al botón que lo abrió.
- [ ] Con `OST_DEFAULT_THEME=dark` no aparece ningún destello blanco al
      cargar (recarga con Ctrl+F5 y observa).
- [ ] En oscuro se leen bien: login, cola de tickets, un ticket con respuesta
      y nota, el diálogo de respuesta y una tabla de configuración.
- [ ] Una entrada con HTML de correo (tabla, imagen, enlace) sigue legible
      sobre el papel claro.
- [ ] `make theme` en verde.

**Auditoría de contraste con navegador** (lo que encontró los huecos de la
tabla anterior). `tools/ui/contrast-audit.js` abre una página, calcula el
contraste real de cada texto visible —incluidos los menús desplegables, que
sólo se pueden medir abiertos— y lista los que no llegan a WCAG 2.1:

```bash
npm install --no-save playwright-core
node tools/ui/contrast-audit.js                    # la vista previa, en oscuro
node tools/ui/contrast-audit.js --theme light      # comprobar que no se rompió el claro
node tools/ui/contrast-audit.js http://localhost:8090/scp/tickets.php \
     --cookie "OSTSESSID=<tu-sesion>"              # contra la app real
```

Sale 0 si no hay hallazgos y 1 si los hay, así que se puede encadenar. En la
vista previa el tema oscuro está a **0 hallazgos**; en claro quedan 18
heredados del tema base de osTicket (naranja `#F6A828` con texto blanco en
jQuery UI, `.btn_sm`, `#header a`, `.article-meta`), que son anteriores a
este trabajo y se corrigen en el tema claro en una pasada aparte.

Herramientas recomendadas además: Lighthouse (accesibilidad), axe DevTools,
WAVE, y el modo de emulación de `prefers-reduced-motion` del navegador.

---

## 6. Estado y siguientes pasos

Implementado en esta iteración:

- Capa CSS de accesibilidad y consistencia.
- Enlace de salto, regiones vivas y etiquetas de formulario.
- Ajustes responsive para tablas de datos.
- **Modo oscuro** con selector en la cabecera, persistencia por usuario,
  respeto a `prefers-color-scheme` y valor por defecto configurable.
- **Densidad** cómoda o compacta, con su propio botón y valor por defecto.
- **Diálogos accesibles**: foco atrapado mientras están abiertos, devuelto al
  cerrarlos y cierre con Escape (esto último ya existía en `scp.js`).

Cobertura medida con `tools/ui/contrast-audit.js` sobre la vista previa
(reproduce el marcado real de las plantillas): panel (cabecera, colas,
búsqueda, listas, ficha, formularios, pestañas, avisos, diálogos, widgets,
analíticas, panel de inicio, traducciones en línea, entradas de ticket,
notas, ayudas, respuesta a un ticket) y portal del cliente (lista, ficha,
respuesta, acceso y FAQ).

Pendiente (requiere tocar marcado y JavaScript, por eso se deja fuera de
esta entrega para no arriesgar funcionalidad):

1. Reemplazar `onclick=` inline por `addEventListener` (habilita una CSP sin
   `unsafe-inline`, ver OST-102 en `SECURITY_AUDIT.md`).
2. Indicador de fuerza de contraseña en el alta de cuenta.
3. Sustituir iconos de Font Awesome por SVG embebidos (rendimiento y
   accesibilidad). Los dos botones de la cabecera ya son SVG en línea.
6. Revisión del tema oscuro en las pantallas con HTML de terceros
   (visor de correo, previsualización de PDF) con datos reales.
