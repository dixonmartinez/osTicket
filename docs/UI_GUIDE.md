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
| `tools/ui/icon-sheet.php` | Hoja con los 155 iconos SVG para revisarlos a ojo (§3.5) |
| `tools/ui/inline-handlers.php` | Inventario de `onclick=`/`onchange=` para la migración a `addEventListener` (§6.2) |
| `docs/theme-preview.html` | Maqueta del **panel** con el marcado real, para revisar sin Docker |
| `docs/theme-preview-client.html` | Maqueta del **portal del cliente**, con sus hojas exactas |
| `include/class.osticket.php` → `getThemeDefault()` | Lectura de `OST_DEFAULT_THEME` |
| `css/density.css` | Modo compacto (solo espaciado) |
| `js/dialog-focus.js` | Foco atrapado en los diálogos y devuelto al cerrarlos |
| `js/icons.js` | Iconos de Font Awesome marcados como decorativos y SVG fuera del tabulador |

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

**Vista previa sin Docker**: abre `docs/theme-preview.html` (panel) y
`docs/theme-preview-client.html` (portal del cliente) en el navegador.
Carga los CSS reales y trae el botón para alternar. (También puedes servir el
repositorio con `python3 -m http.server 8099` y entrar en
`http://localhost:8099/docs/theme-preview.html`.)

**Por qué hay dos maquetas.** El portal del cliente no carga
`scp/css/scp.css`: sus hojas son `css/osticket.css` y
`assets/default/css/theme.css`. Si se mezclan las dos en un solo archivo,
reglas del panel como `#nav .active { display:inline-block }` falsean el
resultado (la barra del portal se ve como una lista de viñetas). Cada
maqueta carga exactamente las hojas de su cabecera, en el mismo orden.

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
| Responder (`#reply`, `#response_options`, `.selected-signature`) | Barra blanca (`sticky bar`), `textarea` blanca y firma sobre blanco |
| Nota rápida y acciones flotantes (`.quicknote a.action`, `.floating-options a.action`) | `color:black !important` sobre fondo claro |
| Filas y campos deshabilitados (`tr.disabled`, `input:disabled`) | Gris `#f5f5f5` con texto claro |
| Portada del portal (`.thread-body` dentro de `#landing_page`) | El “papel claro” de los correos se aplicaba a la portada: texto oscuro sobre blanco en mitad del tema |
| Barra de navegación del portal (`#nav li a.active`) | Píldora `#dbefff` con el texto claro |
| Iconos de `#nav` y `#sub_nav` | PNG/GIF de 16 px **con fondo blanco**: cuadrados claros sobre la barra oscura. Se sustituyen por el glifo de Font Awesome |
| Acceso del cliente (`#clientLogin`), `.infoTable`, `#reply` | `#f6f6f6`, `#f4faff` y `#f9f9f9` de `theme.css` |
| `.sidebar .content`, `.search-form .search`, `#kb > li` | Barra lateral y base de conocimiento en blanco |
| `.action-button` del portal | `color:#777 !important` de `theme.css`: hacía falta `!important` |
| `div.translatable` | Envoltorio blanco del campo traducible |
| Hover de la barra de colas sin `#customQ_nav` | En `settings.php` el menú no lleva ese id: el `li:hover` se quedaba blanco |
| `h2 i` / `h2 small` | Icono verde oscuro y texto secundario gris sobre oscuro |

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

### 3.5 Iconos en SVG

`js/icons.js` sustituye en el navegador los `<i class="icon-x">` que están en
su mapa `SVG_ICONS` por un `<svg>` en línea con **las mismas clases** y el
color heredado (`currentColor`). Ventajas: no depende de la fuente de iconos
(se acaba el parpadeo mientras carga), se ve nítido en pantallas HiDPI y el
tamaño sigue al texto (`1em`), incluidos `.icon-large` y el zoom.

- Las plantillas **no cambian**: el marcado sigue siendo `<i class="icon-x">`.
- Un icono que no esté en el mapa se queda con Font Awesome: la sustitución
  es aditiva, nunca deja un hueco.
- Para volver atrás sin tocar código: `<html data-svg-icons="off">`.
- Para añadir un icono, una entrada más en `SVG_ICONS` (rejilla 24×24, trazo
  de 2 px). `theme-check` exige que haya 150 o más.

El mapa se amplió en dos tandas medidas con `grep` sobre `include/` y `scp/`:
primero los 107 más repetidos y después los 48 que quedaban con uso real
(avisos, adjuntos, ordenaciones de columna, manos de asignación, redes y
estado). En total **155 iconos**. La hoja de revisión visual se genera con:

```bash
make icons                                      # deja la hoja en ost-iconos.html
php tools/ui/icon-sheet.php --theme=dark --out=/tmp/iconos.html
```

Ábrela en el navegador: muestra los 155 con el mismo `js/icons.js` de la
aplicación, así que lo que se ve ahí es lo que verá el agente. Si un icono no
se entiende a 13 px, se redibuja en `js/icons.js`.

Quedan en Font Awesome:

- Las utilidades que no son dibujos (`icon-fixed-width`, `icon-spin`, `icon-2x`,
  `icon-large`…).
- Los iconos de marca que osTicket no usa en ninguna plantilla.

**Los atributos del `<i>` se conservan.** Los iconos de ayuda
(`<i class="help-tip" href="#clave">`) y los de tipo `.tip` no son decorativos:
`scp/js/tips.js` lee su `href` (o su `data-content`) para buscar el texto de
ayuda. Al sustituir el `<i>` por un `<svg>` se copian **todos** los atributos
que no sean `class` —`href`, `id`, `data-*`, `rel`— además de `title` y
`style`. Si se pierden, tips.js no encuentra la sección, **borra el icono** y
no muestra nada: el usuario ve que la ayuda «desaparece» al pulsarla.

### 3.5.1 Las barras `#nav` y `#sub_nav`

Estas dos barras no usan `<i>`: el tema pinta el icono con un PNG/GIF de
fondo (`scp/css/scp.css` para el panel y `assets/default/css/theme.css` para
el portal). Varios de esos PNG **llevan fondo blanco**, así que en oscuro se
veía un cuadrado claro comiéndose el texto, y en pantallas HiDPI salían
borrosos.

Ahora los dibuja `css/ui.css` con **máscaras SVG** (`mask-image`), que no
necesitan tocar el marcado:

```css
#sub_nav a.Ticket { --ost-nav-icon: var(--ost-i-folder-open); }
```

- La imagen de fondo se anula (`background-image: none`) y el icono sale de un
  `::before` con `background-color: currentColor` y `mask-image`. Resultado:
  **hereda el color del texto** en los dos temas, incluidos el hover y la
  pestaña activa.
- Son 58 clases del panel y 5 del portal, con 28 dibujos en total, definidos
  una sola vez como `--ost-i-*` en `:root`.
- Los trazos salen de `js/icons.js`, así que el dibujo es el mismo que el de
  los `<i>` del resto de la interfaz.
- El hueco es el del tema (`padding-left` de 21 px en el panel y 32 px en el
  portal, y el icono cae en `background-position` 0/10 px 50 %): no hay que
  tocar plantillas ni `padding`.

### 3.5.2 Avisos del sistema

Los avisos (`#error_bar`, `#warning_bar`, `#notice_bar`, los `#msg_*` del
portal y los `.error-banner` / `.success-banner` / `.warning-banner` del
panel) usan el mismo truco, porque `error.png`, `alert.png` y `ok.png` son
PNG de 16 px **con fondo blanco**:

```css
:where(.error_bar, #msg_error, .error-banner) {
    --ost-bar-icon: var(--ost-i-exclamation-sign);
    --ost-bar-color: #c0392b;
}
```

- La imagen de fondo se anula en el bloque `:is(...)` (necesita la
  especificidad del `id` para ganar a `scp.css`), y el dibujo sale de un
  `::before` de 16 px con `mask-image`.
- El color va aparte, en `:where(...)`, que tiene especificidad 0: así
  `css/dark.css` puede cambiarlo con su regla de siempre (`--dk-danger`,
  `--dk-warning`, `--dk-success`) sin tener que ganar una guerra de
  especificidad. **Si añades un aviso nuevo, mete el icono y el color en el
  bloque `:where`**, no en el `:is`.
- Los tres tipos llevan ahora fondo teñido también en oscuro; antes los
  `#msg_*` salían en gris plano.

**Para añadir una clase nueva**: una línea con su `--ost-nav-icon` y, si el
icono no existe, un `--ost-i-*` más copiando el `path` de `js/icons.js`. Si el
icono no está en el mapa, el `background-image: none` la dejaría sin dibujo:
por eso la lista de clases con máscara y la de selectores que la activan es la
misma.

### 3.5.3 La barra de colas (`#customQ_nav`)

En `/scp/tickets.php` la barra de colas (*Open*, *My Tickets*, *Closed*…) la
monta `jb.overflowmenu` (`scp/js/jb.overflow.menu.js`): mide la fila y, lo que
no cabe, lo pasa a un menú secundario detrás de un asa. Es un componente
**medido**, así que cambiarle el ancho o el flujo desde la hoja lo rompe.

**La causa de que la barra saliera vacía no era el CSS.** Fue una regresión
del frente 2, en el ORM: `SelectSql::fields()` (`include/class.orm.select.php`)
pasa el mapa de campos a `addAnnotations()`, que apunta ahí la anotación y
**no lo devolvía**. El mapa se quedaba sin la anotación y
`ModelInstanceManager::buildModel()` repartía la fila corrida una columna: el
`id` del modelo relacionado se leía de la anotación y
`VerySimpleModel::set()` escribía ese valor en la clave ajena. Con
`CustomQueue` (que declara `select_related => parent`) eso dejaba `parent_id`
con el `sort` de la cola en **todas** las del primer nivel;
`getHierarchicalQueues()` descarta lo que cuelga de otra cola y la barra se
quedaba sin un solo elemento, sin un error en ningún log. Lo fija
`tests/QuerySqlTest::testLaAnotacionEntraEnElMapaDeCamposConSelectRelated()`.

Dos cosas más que valían antes y ya no:

- `scp.css` fija `.jb-overflowmenu` en **960 px** con la fila en
  `position: absolute`. En una pantalla de 1280 px el contenedor no era el
  ancho real, así que la medida salía mal y podía esconder todas las colas
  dejando el asa visible con la barra vacía. Ahora el contenedor es
  `width: auto; height: auto`, la fila vuelve al flujo (`position: static`) y
  el desbordamiento no pinta borde ni fondo.
- `refresh()` restaba anchos elemento a elemento con `offset().left`, y
  cerraba el componente cuando no quedaba nada por mover. Ahora mide por
  **suma de anchos** (`outerWidth(true)`), mueve **desde el final**, no mueve
  nunca la pestaña activa ni `#new-ticket` (`.primary-only`) y sólo cierra si
  el menú secundario quedó vacío.

Reglas para quien toque esta zona: **nada de `display: flex` ni `gap`** en
`#nav`, `#sub_nav`, `#basic_search` ni `#customQ_nav`; la separación va por
`margin` (`#nav > li + li { margin-left: 4px }`) y `#basic_search .attached.input`
necesita `height: auto` porque `scp.css` lo clava en 24 px.

**Si faltan colas pero la barra se ve bien**, el problema tampoco es CSS: la barra
sólo lista las colas de primer nivel con el bit `FLAG_QUEUE` y sin
`FLAG_DISABLED` (`CustomQueue::getHierarchicalQueues()`, `$primary = true`).
Ese bit se activa en la ficha de la cola, *Queue Visibility* → «Shows up in
queue navigation». Para saberlo sin adivinar:

```bash
make queue-nav            # o: make queue-nav AS=dixon
```

Imprime `ID | Cola | Banderas | Barra | Motivo`, con el motivo de cada cola
que no entra (`FLAG_DISABLED`, falta `FLAG_QUEUE`, `parent_id`) y un contraste
con la barra real cuando hay agente. `make queue-nav ARGS=--json` si lo
quieres para un script.

Cuando la barra real no coincide con las banderas, el comando añade una
**traza** con la tabla leída a pelo, la consulta de la barra tramo a tramo
(cuántas filas hay, cuántas quedan en la raíz y cuántas de esas son cola) y lo
que devuelve `getHierarchicalQueues()` o el error que lanza. Si un tramo
falla, lo dice y sigue con el siguiente. `make queue-nav ARGS=--trace` la
imprime siempre.

Dos detalles que cuestan una tarde si no se saben:

- **El instalador de osTicket no crea ninguna cola.** Una instalación limpia
  tiene `ost_queue` vacía y la barra sólo muestra *Search* y *New Ticket*.
  Las colas se crean en `/scp/queues.php` («Add New Queue») o copiando una;
  sin marcar *Queue Visibility* no salen en la barra.
- `queue --nav` **no necesita agente**: deduce la barra de las banderas. Si no
  hay ninguno, lo dice con los recuentos (`filas`, `activos`, `admins`) en vez
  de morir, y `resolveStaff()` cae a una consulta directa a `ost_staff` como
  último recurso.

### 3.6 Indicador de fuerza de la contraseña

`js/password-strength.js` engancha un medidor de cuatro tramos a los campos
marcados con `data-password-strength` (alta de cuenta y cambio de contraseña
del portal, y alta o cambio de contraseña del panel de agentes, incluido el
diálogo que se abre por AJAX) y avisa si el campo `data-password-confirm` no
coincide.

- **No bloquea el envío** ni sustituye la validación del servidor: si el
  script no carga, el alta funciona igual. Es una ayuda, no un requisito.
- Todo se calcula en el navegador; la contraseña no sale de la página.
- Puntúa longitud (8, 12 y 16 caracteres), variedad (minúsculas, mayúsculas,
  dígitos, símbolos) y penaliza las contraseñas y secuencias típicas
  (`password`, `123456`, `qwerty`, `osticket`…).
- El nivel se anuncia con `role="status"` y `aria-live="polite"`, y el campo
  queda ligado al medidor con `aria-describedby`.
- Los textos se traducen en `include/client/footer.inc.php` y se pasan como
  `window.OST_PW_STRENGTH`; el script trae los mismos en inglés por si falta.

| Nivel | Cuándo | Color (claro / oscuro) |
| --- | --- | --- |
| 1 Débil | menos de 8 caracteres, o una palabra o secuencia típica | `#b3261e` / `--dk-danger` |
| 2 Aceptable | 8–11 con poca variedad | `#9a6700` / `--dk-warning` |
| 3 Buena | 12+ o mucha variedad | `#1b7f3b` / `--dk-success` |
| 4 Fuerte | 16+ y los cuatro tipos de carácter | `#14652f` / `--dk-success` |

### 3.7 HTML de terceros: correo, adjuntos y editor

El cuerpo de un correo llega con **colores propios** (`style="color:#000"`,
tablas con fondo blanco, imágenes…). En oscuro se mantiene ese “papel”
claro y atenuado, porque invertirlo vuelve ilegible el mensaje; lo que se
corrige es lo que el tema dejaba a medias:

| Zona | Antes | Ahora (oscuro) |
| --- | --- | --- |
| Citas (`blockquote`) | Texto `#777` heredado del tema | Fondo `#dee5ec`, texto `#1a1f24` |
| Bloques de código (`pre`, `code`, `kbd`) | Gris sobre gris | Fondo `#d9e0e7`, borde y texto oscuro |
| Tablas y celdas | Bordes casi invisibles | Borde `#b9c3cd`, cabecera `#dbe2e9` |
| Líneas (`hr`) | Borde claro del tema | `#b9c3cd` |
| Controles dentro del HTML | Dibujados en oscuro | `color-scheme: light` en el papel |
| Visor de PDF (`object`/`iframe`) | Hueco oscuro | Fondo blanco y borde del tema |
| Editor enriquecido | Superficie oscura | Papel claro `--dk-editor-paper` (`#eef1f5`): lo pegado desde Word/Outlook trae negro en línea y sobre oscuro desaparecía. **Hasta v33 esta fila no era cierta**: el tema apuntaba a `.redactor-editor`, una clase que Redactor 3 no usa (§3.12) |
| Barra del editor (Redactor) | Franja casi blanca con botones oscuros | Fondo `--dk-surface-3`, botones planos del tema y `--dk-accent` al pasar; menús de formato y diálogos del editor incluidos. **Hasta v33 esta fila tampoco era cierta**: una regla de cuatro clases de `redactor.css` ganaba al tema (§3.12) |
| Zona de adjuntos (`.dropzone`) | Borde y enlace azul oscuro sobre fondo oscuro | Borde del tema, texto atenuado y enlace `--dk-link` |

En claro, `thread.css` dejaba la cita en `#777` (4,48:1) y el código en
`#777` sobre `#f2f2f2` (4,01:1); `css/ui.css` los sube a `#4a4f55` y `#333`.

### 3.8 Tema claro: dónde vive cada corrección

El tema claro **no se toca** en `assets/default/css/theme.css` (es el tema
de osTicket y se puede actualizar). Las correcciones van a `css/ui.css`,
siempre bajo `html[data-theme="light"]`, para que no se mezclen con el
tema oscuro de `css/dark.css`:

| Selector | Antes | Ahora |
| --- | --- | --- |
| `div#header a`, `#sub_nav > li > a`, `.newItemQ.queue-count` | Naranja `#E65524` (3,69:1) | `#b23d13` |
| `.ui-dialog-titlebar`, `.ui-datepicker-header` | Blanco sobre `#F6A828` (1,99:1) | Texto `#3b2800` |
| `.btn_sm` | Blanco sobre `#f90` (2,14:1) | Degradado `#a05f00`→`#8b5200` |
| `.action-button.danger` | `#999` translúcido sobre `#eee` (2,46:1) | `#b3261e` opaco |
| `.label` | Blanco sobre `#999` (2,85:1) | Fondo `#63696f` |
| `.form_table th h4` | Blanco sobre `#929292` (3,11:1) | Fondo `#5a636b` |
| `.action-dropdown li.active > a` | `#08C` sobre `#cce7f5` (3,03:1) | `#0b4f75` |
| `.tip_content .links a`, `.thread-body a` | `#548dd4` / `#428bca` | `#2d6491` |
| `.faded`, `.add-queue a` | `rgba(0,0,0,.5)` / `#666` al 40 % | `#5b6670` / `#444` al 85 % |
| `.filedrop .dropzone` (texto y enlace) | `#999` (3,75:1) y azul al 70 % | `#5b6670` y `#2d6491` |
| `.row-item .delete:hover` | Blanco sobre `#fc9f41` (1,9:1) | Fondo `#a3540a` |

Y en el portal del cliente, `body.client-portal` (la clase la pone
`include/client/header.inc.php`) agrupa los botones del tema:
`.green.button` pasa a `#5d7b08` (2,21 → 4,88:1), `.blue.button` a
`#00739f` (2,53 → 5,31:1), `.action-button` a `#4a4a4a` y
`.article-meta` a `#5b6670`. Sin esa clase las reglas alcanzarían a los
botones del panel, que no usan el tema del portal.

### 3.9 Acciones declarativas (`js/actions.js`)

Los manejadores en línea (`onclick="..."`) impiden una CSP sin
`unsafe-inline`, así que **se sustituyeron todos** (0 en `include/` y `scp/`)
por atributos que interpreta un único listener delegado en `document`. No hace
falta inicializar nada: basta con el `<script>` de `js/actions.js`, que ya va
en los dos pies de página. Para lo que necesita lógica propia hay funciones
con nombre (`data-call="ostAddMergeTicket"`, `ostAddSequence`…).

| Atributo | Dónde | Qué hace |
|---|---|---|
| `data-href="url"` | cualquier elemento | navega a `url` |
| `data-go-back` | cualquier elemento | `history.back()` |
| `data-submit-on-change` | `<select>` | envía su formulario al cambiar |
| `data-ost-dialog="201"` | enlace | abre el `href` en un diálogo AJAX (un `#` inicial se descarta) |
| `data-ost-dialog-url="url"` | cualquier elemento | abre esa URL en el diálogo, sin tocar el `href` |
| `data-ost-dialog-size="large"` | junto a los anteriores | tamaño del diálogo |
| `data-ost-dialog-row="base/"` | enlace dentro de una fila | abre `base` + el valor de `data-ost-dialog-row-field` buscado en el `<tr>` (p. ej. el id de la columna que se configura); `data-ost-dialog-row-scope` cambia el contenedor |

> **`data-dialog` ya existía y significa otra cosa.** En osTicket,
> `data-dialog="ajax.php/…"` es la **URL** que `scp/js/scp.js` abre con
> `$.dialog(url, 201)`, y además refresca la lista por PJAX al cerrar. Por eso
> las acciones de este archivo llevan prefijo `data-ost-`: usar el mismo
> nombre hacía que el manejador de osTicket abriera el diálogo con el número
> `201` como URL, jQuery lo convirtiera a `Number` y `.load()` reventara con
> `e.indexOf is not a function`; el overlay «Loading…» se quedaba encima de la
> página. Si necesitas el comportamiento de osTicket (recargar al cerrar),
> escribe `data-dialog`; si necesitas exactamente el `$.dialog` de antes, usa
> `data-ost-dialog`.
| `data-confirm="texto"` | elemento o `<form>` | pide confirmación antes de actuar (en un formulario, al enviar); se combina con los demás atributos |
| `data-remove-closest="tr"` | cualquier elemento | borra del DOM el ancestro que case con el selector (filas, condiciones, idiomas) |
| `data-toggle-target="#id"` | elemento o `<select>` | muestra u oculta el destino: `data-toggle-mode="show\|hide\|toggle"`, `data-toggle-when="valor"` (según el valor del control) y `data-toggle-speed="200"` |
| `data-inherit-toggle` | checkbox | muestra u oculta `.if-not-inherited` de su tabla según el estado |
| `data-save-draft` | cualquier elemento | llama a `saveDraft()` (borrador del editor de osTicket) |
| `data-discard-draft[="url"]` | cualquier elemento | descarta los borradores del editor del formulario y recarga (o navega a `url`) |
| `data-reload` | cualquier elemento | recarga la página |
| `data-click-target="selector"` | cualquier elemento | pulsa los elementos que casen (no cancela la acción propia); `data-click-scope="parent"` limita la búsqueda al padre y `closest:sel` a un ancestro |
| `data-pjax-submit[="selector"]` | `<form>` | envía por PJAX a `#pjax-container` (o al contenedor indicado) en vez de recargar |
| `data-pjax-query="sort=mode&dir=dir"` | contenedor de enlaces | recarga la lista por PJAX añadiendo esos parámetros a la URL actual, con el valor tomado del `data-mode`/`data-dir` del enlace pulsado (equivale a `addSearchParam()`) |
| `data-set-name="campo"` | cualquier elemento | escribe un campo del formulario antes de enviarlo: valor literal con `data-set-value` o tomado de otro `data-*` con `data-set-from`; `data-submit-form` envía después |
| `data-ost-ajax="url"` | cualquier elemento | hace la petición AJAX y aplica el resultado: `-method="post\|delete"`, `-data="a=1&b=2"`, `-json`, `-reload` (recarga al terminar) y `-fade="tr"` (desvanece el ancestro). En la URL, `{@attr}` se sustituye por el `data-attr` del elemento y `{#selector}` por el valor de ese campo |
| `data-ost-mark-removed` | enlace de borrar | marca la fila como quitada sin sacarla del DOM: cambia su campo oculto (`-field="selector"`, `-prefix="D"`, `-drop-first` para sustituir el primer carácter) y la desvanece; `-mark="save pending"` avisa en el botón de guardar |
| `data-ost-drop-row=".caja"` | enlace de borrar | quita los campos del bloque (para que no se envíen) y lo desliza hacia arriba sin borrarlo |
| `data-ost-append-hidden="campo"` | enlace de borrar | añade un `<input hidden>` con ese nombre y el valor de `-append-value` al contenedor `-append-to` (por defecto `#ticket-entries`); se combina con `data-toggle-target` y `data-remove-closest` |
| `data-ost-remove-row="tbody"` | enlace de borrar | desvanece y quita el contenedor, y vuelve a habilitar la opción del `<select>` de `-remove-restore` que tenga su `data-form-id` |
| `data-ost-expand=".body"` | enlace | despliega el destino dentro de `-expand-scope` (por defecto el propio enlace), oculta `-expand-hide` y, con `-expand-icons`, cambia `icon-caret-right` por `icon-caret-down` |
| `data-ost-upload-form="url"` | `<form>` | si el campo `-upload-when` tiene valor, el envío va a esa URL y se desengancha del diálogo (`submit.dialog`) |
| `data-ost-dialog-entry` / `data-ost-dialog-pjax` | enlace de diálogo | al cerrarse con éxito, repinta la entrada del hilo con el JSON de respuesta o recarga por PJAX |
| `data-ost-user-lookup="url"` | cualquier elemento | abre el buscador de usuarios (`$.userLookup`) y aplica el elegido: `-fill="#a=campo[\|formato]"` escribe texto (`angle` → `<email>`, `name-email` → `Nombre <email>`, `{id}` en el selector), `-value="#campo=id"` rellena un campo oculto, `-add="#select"` añade la opción y dispara `change` (`-add-format="email-name"`), `-redirect="url"` navega y `-save-draft` llama antes a `saveDraft()`. En la URL, `{#campo}` se sustituye por el valor de ese campo |
| `data-call="funcion"` | elemento o `<form>` | llama a `window.funcion(event)`; si devuelve `false`, cancela la acción (en un `<form>`, al enviar). Incluye `ostHelpTopic` (recarga el formulario dinámico al cambiar de tema) y `ostAutodetectTimezone` (carga `jstz` y rellena el `<select>` de `data-timezone-target`) |

Se combinaron más de 50 plantillas en diez tandas y los manejadores en línea
bajaron de **124 en 71 archivos a 0** (`make inline-audit`). `make
inline-budget` falla si vuelve a aparecer uno, y `make theme` lo detecta con
archivo y línea, así que el marcado nuevo tiene que usar los atributos de
esta tabla o una función con `data-call`.

El buscador de usuarios (`$.userLookup`) fue el grupo más grande: los ocho
`onclick` hacían cosas distintas con el usuario elegido, así que el callback
se describe con atributos (`data-ost-user-lookup-fill`, `-value`, `-add`,
`-redirect`) y la lógica vive una sola vez en `js/actions.js`.

**Todo `<script>` en línea lleva `nonce`.** La CSP sale con
`script-src 'self' 'nonce-…'`, sin `'unsafe-inline'`, así que una etiqueta sin
el atributo **no se ejecuta**:

```php
<script nonce="<?php echo Http::nonce(); ?>">
    $(function() { /* … */ });
</script>
```

- El nonce es **por sesión** (`Http::nonce()`), porque los trozos de HTML que
  llegan por AJAX se ejecutan dentro de la página que los recibe y su nonce
  tiene que coincidir.
- `make csp-nonce` (dentro de `make ci`) recorre las plantillas y falla con
  archivo y línea si alguna etiqueta no lo lleva. Hoy: **98 en línea, 0 sin
  nonce**.
- Si el HTML se arma en PHP (un heredoc, un complemento con
  `addExtraHeader()`), no hay etiqueta que editar: `Http::nonceScripts()`
  añade el atributo, y `addExtraHeader()` ya lo hace solo.
- Los `<script src="…">` no lo necesitan.

### 3.10 Capa moderna (`css/modern.css`)

`ui.css` arregló accesibilidad y consistencia, pero el cromo seguía siendo el
de 2010: cabecera de 76 px con sombra interior, pestañas con degradado, tablas
con rejilla de 1 px y franjas azules, botones de 11 px y todo metido en una
columna de 960 px. `css/modern.css` es una capa **aparte** que moderniza esa
forma sin tocar el tema ni el marcado.

**Qué cambia**

| Zona | Antes | Ahora |
| --- | --- | --- |
| Lienzo | Columna fija de 960 px | Hasta 1280 px con márgenes elásticos |
| Tipografía | `Lato` 11–12 px | Pila del sistema a 14 px con interlineado 1.5 |
| Cabecera | 76 px, sombra interior, `#info` en caja gris | Barra plana; las acciones de la sesión son enlaces con realce al pasar |
| Navegación | Pestañas con degradado y sombra proyectada | Pastillas; la activa va rellena del color de acento |
| Subnavegación | Pestañas con borde | Fichas suaves, la activa con anillo |
| Contenido | Marco con borde inferior de 3 px | Tarjeta con radio de 10 px y sombra suave |
| Tablas | Rejilla de 1 px, cebras azules, celdas de 1 px de relleno | Sin rejilla: filete inferior por fila, cabecera en versalitas **pegajosa** al desplazar, realce de acento al pasar |
| Búsqueda | Campo y botón sueltos | Campo unido al botón, con anillo de foco |
| Botones | 11 px con degradado | 13 px, radio de 8 px; la acción principal rellena, el resto neutra |
| Avisos | Bandas de color | Tarjeta con filete de color a la izquierda |
| Acceso | Caja con borde | Tarjeta centrada, campos y botón de 40 px |

**Las tres reglas que la hacen segura**

1. **Ningún color literal.** Todo sale de los tokens `--ui-*` de `ui.css`; el
   tema oscuro redefine esos mismos tokens en `dark.css`. Por eso el modo
   oscuro funciona en la capa nueva **sin una línea de más**. `make theme`
   falla si alguien escribe un `#hex`, `rgb()` o `hsl()` (se permite
   `rgba(0, 0, 0, …)`, que son las sombras y valen en los dos temas).
2. **La misma especificidad que `scp.css`, sin prefijos `html body`.** Así
   gana a `scp.css` por orden de carga y **pierde** contra `dark.css` y
   `density.css`, que van después y mandan en color y en espaciado compacto.
   Si se usaran prefijos, la capa moderna ganaría al tema oscuro y los
   botones quedarían blancos sobre azul claro.
3. **Nada de medidas atadas al marcado.** Ni anchos ni alturas fijas que
   recorten contenido: solo espaciado, radios, bordes y tipografía.

**Dónde se carga.** En las tres cabeceras (`include/staff/header.inc.php`,
`include/client/header.inc.php`, `include/staff/login.header.php`), siempre
**después de `ui.css` y antes de `dark.css`**. El orden lo comprueba
`make theme`.

**Las hojas se sirven con versión en la URL.** nginx las cachea **7 días**
(`expires 7d` en `docker/nginx/default.conf`) y `GIT_VERSION` no sirve como
versión: en el árbol es la cadena literal `'$git'` y no cambia. Todas las
hojas y scripts propios se piden por `Http::asset()`, que añade
`?v=<filemtime>`; al cambiar el archivo cambia la URL y el navegador vuelve a
pedirlo. `make theme` falla si alguna plantilla carga un recurso sin versión.

**Cómo verla sin levantar el panel.** Las maquetas de `docs/` cargan las hojas
de verdad, así que sirven para revisar el resultado sin PHP ni base de datos:

```bash
python3 -m http.server 8088        # desde la raíz del proyecto
# luego: http://localhost:8088/docs/
```

`docs/index.html` es el índice de las tres maquetas (panel, portal y
analíticas). En el panel de la maqueta hay una sección con el estado vacío de
la cola.

**Cómo quitarla.** Borrar `css/modern.css` y sus tres `<link>`: la aplicación
vuelve exactamente al aspecto anterior, porque ninguna plantilla depende de
ella.

**Dos cosas que la capa moderna NO puede tocar**, y por qué:

1. **`#nav`, `#sub_nav` y `#basic_search` no pueden pasar a `flex`.** La barra
   de colas lleva el plugin `jb.overflowmenu` (`scp/js/jb.overflow.menu.js`),
   que mide la fila con `innerWidth()` y los `offset()` de cada pestaña para
   decidir cuáles manda al menú de desbordamiento. Con `flex` y `gap` la
   medida sale mal y **esconde todas las colas menos dos**: en
   `/scp/tickets.php` la barra quedaba casi vacía, con «Search» y «New
   Ticket», y parecía que faltaba el filtro. El aspecto de pastilla se
   consigue con relleno y radio en los enlaces, sin cambiar el flujo.
   `tests/QueueNavLayoutTest.php` falla si alguien vuelve a poner `flex` o
   `gap` ahí. Lo mismo vale para el buscador: el filtro rápido y el selector
   de orden son `.action-dropdown` posicionados en absoluto.
2. **La columna ordenada no se pinta como un bloque de color.** `scp.css` da
   a la cabecera activa un fondo azul claro de punta a punta de la celda
   (`#cfe6ff`) y el tema oscuro lo repetía en gris (`--dk-surface-4`). Con la
   cabecera en versalitas, eso se lee como una columna rota. Ahora el
   indicador es el color de acento con fondo suave en los dos temas, y la
   flecha (la imagen de fondo de `scp.css`) se queda donde estaba.

**Controles que parecían texto.** «Sort», el filtro rápido y el «More» de la
barra son `span.action-button` con un anillo interior al 8 % de opacidad: en
pantalla no se distinguían de una etiqueta. Ahora llevan borde visible, alto
elástico y realce al pasar. La regla **excluye** `green`, `red`, `blue`,
`primary` y `danger`: `.action-button` también se usa en los «Add New …» de
color, y una regla genérica les habría quitado el color
(`tests/ThemeControlsTest.php` lo comprueba, y falla si se quita la exclusión).

**El buscador dice qué busca.** El campo de colas y el de tareas llevan
`placeholder` traducido («Search tickets…», «Search tasks…») y `aria-label`,
para que no quede un cuadro vacío sin explicación — y para quien usa lector de
pantalla, donde el placeholder no se anuncia igual.

**Filas y prioridad.** El tema oscuro repintaba las filas impares (cebras de
`scp.css` pasadas a `--dk-surface-2`), así que la tabla no era la misma en los
dos temas; ahora no hay cebras en ninguno y el realce al pasar usa el acento.
La prioridad trae su color en línea (lo elige el administrador): se respeta,
pero se dibuja como **etiqueta redondeada**, no como un rectángulo que cubre
media celda.

**Los cuatro `!important` que hay**, ninguno de adorno: uno devuelve a `auto`
la altura de un bloque que lleva `style="height:25px"` en la plantilla, otro
anula el fondo en línea de la celda de prioridad (ver §3.11), y los otros dos
son las excepciones ya conocidas de `prefers-reduced-motion` y de la
impresión (que oculta cabecera, navegación y buscador). Ninguno toca datos.

**Texto sobre el acento.** El token `--mod-on-accent` se deriva de
`--ui-surface`: en claro da blanco sobre azul y en oscuro casi negro sobre
azul claro. Es la forma de escribir un solo botón que funcione en los dos
temas sin duplicar reglas.

### 3.11 Tamaño del texto y etiqueta de prioridad

**El texto se leía pequeño.** El cuerpo de `scp.css` está en 14 px, y encima
los controles de formulario no fijan tamaño: un `<input>`, `<select>`,
`<textarea>` o `<button>` **no hereda la tipografía del `body`**, así que el
navegador les pone su `13.3px Arial` por omisión (`scp.css` además les da
`font-size: .95em`). En una pantalla grande, el buscador y los botones se leen
más pequeños que el texto de al lado.

La escala vive en tres tokens, en `:root` de `css/modern.css`:

```css
--mod-text:    15px;   /* cuerpo, tablas y campos */
--mod-text-sm: 14px;   /* navegación, botones, pies y textos de apoyo */
--mod-text-xs: 12px;   /* cabeceras de tabla en versalitas y etiquetas */
```

- El `body` usa `var(--mod-text)`; las cabeceras de tabla, la navegación y los
  botones usan los otros dos. **Para agrandar o achicar todo el panel se
  cambia `--mod-text`** y lo demás acompaña.
- Los campos llevan `font-family: var(--mod-font)` y
  `font-size: var(--mod-text)` explícitos. Sin eso vuelven al tamaño del
  navegador, que es justo el defecto que esto corrige.
- `theme-check` comprueba que los tres tokens existen, que `body` usa la
  escala, que los campos fijan su tamaño y que **ningún texto baja de 12 px**.
- Esto es tipografía, no espaciado: `css/density.css` (modo compacto) sigue
  mandando en `padding` y `line-height` sin tocar tamaños.
- La barra de colas no se ve afectada: `jb.overflowmenu` mide los elementos en
  tiempo de ejecución, así que con el texto más grande reparte de nuevo y
  manda al menú secundario lo que ya no cabe.

**La etiqueta de prioridad.** `PriorityField::display()` mete el mismo
`background-color` en el `<td>` y en el `<div>` de dentro
(`queue-tickets.tmpl.php`), así que la celda entera salía pintada de punta a
punta: en la captura del usuario era un rectángulo rosa de todo el ancho de la
columna, con el texto pegado a la izquierda, y parecía una columna rota.

Ahora el `<td>` se anula (`background: transparent !important`, la única forma
de ganarle a un `style` en línea) y el color se queda sólo en la etiqueta, que
es `inline-block` con `border-radius` de píldora. **El color no se pierde: se
mueve** de la celda a la etiqueta. El contraste en oscuro lo sigue resolviendo
`css/dark.css` (§3.10), que devuelve texto oscuro a las celdas con color en
línea.

### 3.12 Editor enriquecido, tablas de estadísticas y estados vacíos

Otra tanda de capturas con la nota «hay cosas que se ven mal» y la petición
de **tablas mejores** y **un editor más rico**. Lo que sigue es lo que se
arregló, con la causa de cada defecto, porque casi ninguno era «falta de
estilo»: eran nombres y especificidades.

#### 3.12.1 Por qué el tema oscuro no llegaba al editor

Dos errores, los dos silenciosos:

1. **La clase no existe.** Redactor 3 pone la clase **`.redactor-in`** (más
   `.redactor-in-<uuid>`) al elemento editable, y `.redactor-styles-on` a la
   caja. El tema hablaba de `.redactor-editor`, así que *todas* las reglas
   del área de escritura —fondo, color, cursor, texto de ayuda— no se
   aplicaban a nada. `css/mobile.css` tenía el mismo nombre mal, de modo que
   el `max-width: 100%` del editor tampoco entraba en móvil.
   Se puede comprobar en `js/redactor.min.js` (`enable: … addClass("redactor-in
   redactor-in-"+this.uuid)`) y en `css/mobile.css`.
2. **La barra la pintaba `redactor.css` con cuatro clases.**
   `.redactor-box.redactor-styles-on .redactor-toolbar.redactor-toolbar`
   (especificidad 0,4,0) pone `rgba(255,255,255,.97)` y **se carga después de
   `modern.css`**. La regla del tema
   (`html[data-theme="dark"] .redactor-toolbar`, 0,2,1) perdía siempre: la
   barra salía casi blanca, con los botones ya oscuros encima. La corrección
   es una regla de la misma especificidad:

   ```css
   html[data-theme="dark"] .redactor-box.redactor-styles-on .redactor-toolbar.redactor-toolbar {
       background-color: var(--dk-surface-3);
   }
   ```

El área de escritura **sigue siendo papel claro** (lo pegado desde Word u
Outlook trae `color:#000` en línea), pero ya no blanco puro: `#eef1f5`
(`--dk-editor-paper`), con `color-scheme: light` y cursor oscuro. Y el papel
está dentro de un marco del tema (borde `--dk-border-strong` y radio), así
que el editor se lee como parte de la página y no como una ventana ajena.

#### 3.12.2 La barra del editor: más botones, por grupos

`js/redactor-osticket.js` (`redact()`) declara la barra en grupos separados
por `|`:

| Grupo | Botones |
| --- | --- |
| Modo | `html` (código fuente), `format` (párrafo, cita, títulos) |
| Historia | `undo`, `redo` |
| Texto | `bold`, `italic`, `underline`, `deleted`, `sub`, `sup`, `fontcolor`, `fontfamily` |
| Listas | `ul`, `ol`, `outdent`, `indent` |
| Insertar | `image`, `video`, `file`, `table`, `link`, `line` |
| Pantalla | `fullscreen` |

Tres detalles que importan:

- **`fullscreen` estaba en `buttons` y su plugin no estaba en `plugins`.** El
  botón lo crea el plugin (`addButtonAuto('fullscreen', …)`); si el plugin no
  se carga, el botón no llega a existir y *desaparece sin error*. Ahora
  `'fullscreen'` está en la lista de plugins.
- **`|` no dibuja nada por sí solo**: Redactor lo salta. El parche de la barra
  (mismo fichero, `R$.add('service','toolbar', …)`) intercepta `addButton` y,
  si el botón anterior en la lista era `|`, inserta
  `<span class="redactor-separator" aria-hidden="true"></span>` antes. Va en
  `try/catch`: si una versión futura cambia el DOM, la barra pierde los trazos
  pero **no deja de funcionar**. El trazo lo pinta `css/modern.css`
  (`.redactor-toolbar .redactor-separator`).
- **Todos los botones existen** en este Redactor. `tests/EditorToolbarTest.php`
  lee `y.buttons` de `js/redactor.min.js` y los `addButtonAuto/Before/After` de
  `js/redactor-plugins.js`, y falla si la barra pide un nombre que no está.
  Un nombre inventado se ignora en silencio, así que la prueba es la única
  red.

En claro los botones dejan de ser tarjetas blancas con sombra (aire de fila
de pegatinas): pasan a ser planos, con `--ui-accent-soft` al pasar y
`--ui-accent` cuando están activos.

#### 3.12.3 Adjuntos: arrastrar y soltar

El formulario de respuesta ya aceptaba arrastrar ficheros (`js/filedrop.field.js`),
pero el aviso de «puedes soltar aquí» era un `background-color` **en línea**
(`rgba(0,0,0,.3)`): sobre el papel oscuro se confundía con la superficie y en
claro tapaba el texto. Ahora se pone una clase:

```js
dragOver:  this.$element.addClass('ost-drop-over');
dragLeave: this.$element.removeClass('ost-drop-over');
```

`css/modern.css` la pinta con `--ui-accent` y `--ui-accent-soft`, `css/dark.css`
con `--dk-link` y `--dk-accent-soft`. La zona de adjuntos deja de ser una caja
gris: borde de puntos del tema, icono a tamaño, texto de apoyo y el enlace
«choose them» en color de acento.

#### 3.12.4 Tablas

**Tabla de estadísticas del panel** (`include/staff/dashboard.inc.php`,
`table.dashboard-stats.table`). Sumaba tres rejillas a la vez: los bordes
verticales de `table.dashboard-stats`, el punteado de `.table tr` y el blanco
por celda de `.table td:not(:empty)` — que además no dejaba ver la cebra ni el
hover. Ahora:

- una sola línea por fila (`border-bottom` de `--ui-border`), sin bordes
  verticales y sin fondo por celda;
- cabecera en versalitas sobre `--ui-surface-alt`;
- cebra con `--ui-surface-alt` y hover con `--ui-accent-soft`;
- cifras con `font-variant-numeric: tabular-nums`, que es lo que hace que una
  columna de recuentos se pueda leer de un vistazo.

Las tablas de las colas (`table.list`) no cambian: ya venían de §3.10.

#### 3.12.5 La ficha del ticket y la base de conocimiento

| Defecto en la captura | Causa | Arreglo |
| --- | --- | --- |
| El asunto se leía casi negro sobre oscuro | `scp.css` pinta `.tixTitle h3` en `#444` y el tema sólo redefinía `h2` | Regla para `.tixTitle h3` (y su enlace) con `--dk-text` |
| «Post Internal Note» era un panel blanco entre pestañas oscuras | `form#note` **no tenía ninguna regla** en `dark.css`; sí la tenían `#reply` y `.infoTable` | `form#note` y `#response_options > form` con `--dk-surface-2`; etiquetas y errores incluidos |
| El bloque de respuesta flotaba sobre un gris que no casaba con nada | `#response_options > form` traía `#f9f9f9` y borde `#aaa` de `scp.css` | Superficie y borde del tema, radio inferior y `padding` en claro |
| Las pestañas eran fichas con dos sombras y fondo azul grisáceo | `ul.tabs` de `scp.css` | Mismo lenguaje que la barra de colas: tira sobre `--ui-surface-alt`, activa blanca con filete superior de acento. **No se toca el flujo ni la altura**: las pestañas siguen siendo `li` en línea con su `bottom` de 1 px |
| «Priority:» y nada detrás | El `<td>` imprimía `getPriority()`, que es `null` cuando el ticket no tiene respuesta guardada para el campo | Se calcula una vez: prioridad de la respuesta → prioridad por defecto del sistema (`getPriorityId()` + `Priority::lookup()`) → `None` en texto atenuado. `tests/EditorToolbarTest.php` fija las tres ramas |
| «NO FAQs found» y «The search did not match any FAQs» eran una línea gris suelta | La plantilla imprimía el texto sin estructura | El estado vacío de las colas (`.empty-state`): icono, frase y pista. El buscador de la base de conocimiento estrena `placeholder` |

Nota sobre `Priority`: **`Priority` no tiene `display()`**. El camino correcto
es `getDesc()`. Llamar a `$prio->display()` en la ficha habría sido un error
fatal, no un adorno que no se ve; por eso la fila usa `getDesc()` y escapa con
`Format::htmlchars()`.

#### 3.12.6 Cómo se comprueba

- `php tools/ui/theme-check.php` → **178** comprobaciones (antes 158), hoy
  **195** tras las pasadas de §3.13.11 y §3.14. Las
  nuevas: `.redactor-in` en el tema y ninguna regla de `.redactor-editor`, la
  regla de cuatro clases de la barra, `#response_options > form`, `#note`,
  `.tixTitle h3`, el token `--dk-editor-paper`, los seis botones nuevos, el
  plugin `fullscreen`, el separador, la tabla de estadísticas, las pestañas,
  las dos salidas vacías de la base de conocimiento y la fila de prioridad.
- `php tests/run.php` → **869** pruebas, 0 fallos (hoy 942, §3.13.11).
  `tests/EditorToolbarTest.php` (10 pruebas) valida los botones contra el
  propio Redactor.
- `node tools/ui/dark-audit.js` ya no marca el editor como zona clara
  inesperada: su lista de excepciones pasa de `.redactor-editor` a
  `.redactor-in` (el papel claro del editor es deliberado, §3.7).

### 3.13 Segunda pasada: capturas nuevas

La segunda tanda de capturas del panel no traía defectos de estructura, sino
de **piezas concretas que ninguna hoja cubría**: el plugin de los
desplegables, la ficha del ticket, el gráfico del panel, los iconos de la
línea de tiempo y dos pantallas que decían cosas que no eran verdad.

#### 3.13.1 Select2: la caja blanca de «Select a canned response»

osTicket incrusta **select2 v4** (`css/select2.min.css`, inicializado en
`scp/js/scp.js:208` sobre `select#cannedResp`). El tema oscuro sólo tenía
equivalente para la **API v3** (`.select2-choice`, `.select2-choices`), que
el plugin ya no genera. Resultado: la caja de la respuesta predefinida y el
desplegable de filtros de la cola seguían con el `background-color: #fff` y el
borde `#aaa` del plugin, dos rectángulos blancos en mitad de una pantalla
oscura.

Se añadió el bloque de v4 completo (caja, flecha, `__choice`, `__dropdown`,
`__results__option`, `--highlighted`, `--disabled`, `search__field`) y, en el
tema claro, la caja hereda los tokens `--ui-*`. Sin `!important` salvo el
`width` al 100 % en móvil, donde el plugin fija 350 px en línea.

#### 3.13.2 El buscador de la base de conocimiento

El de las colas lleva `class="basic-search"`; el de la KB
(`scp/css/scp.css:2151`, `#kbSearch`) no. La regla del tema iba por la clase,
así que el buscador de la KB se quedaba con el `input` nativo. Ahora la regla
va por `#basic_search input[type="text"]` y alcanza a los dos; el `placeholder`
que se añadió en §3.12.5 ya se ve en el mismo estilo que el resto.

#### 3.13.3 La ficha del ticket, como un solo panel

`table.ticket_info` son **dos tablas dentro de otra** (mitad izquierda: estado,
prioridad, departamento, fecha; mitad derecha: usuario, correo, organización,
origen). `scp.css` le pone `#F4FAFF` al conjunto, pero cada tabla de dentro
trae sus propias celdas, así que las dos mitades salían de distinto tono, con
las etiquetas pegadas al borde y una línea de separación sólo en una de ellas.

Ahora la ficha se lee como un panel: fondo transparente en las tablas
interiores, etiquetas en `--ui-text-muted` con su peso, `padding` vertical
constante y **una línea fina entre filas** (`tr + tr`, para no dejar un filete
suelto al final). En oscuro lo mismo con `--dk-*`.

#### 3.13.4 El gráfico del panel

`scp/css/dashboard.css` se carga **dentro del cuerpo** de la página
(`include/staff/dashboard.inc.php:12`), o sea **después** de `modern.css` y de
`dark.css`. Por eso los selectores nuevos llevan `body` delante: es lo que
permite ganar por especificidad sin `!important`.

- El fondo era un degradado de seis capas con `filter: progid:` para IE. Ahora
  es una superficie plana con borde del tema.
- Las etiquetas de los ejes venían en **8 pt de Monaco**. Pasan a
  `var(--mod-text-xs)` (12 px: el mínimo de la escala, que
  `TextScaleTest::testNoHayTamanosSueltosPequeños` comprueba).
- En oscuro, `fill` con `--dk-text-muted`: Raphael dibuja el texto en negro y
  sobre la superficie oscura no se veía. `fill` por CSS gana al atributo de
  presentación del SVG.

#### 3.13.5 Iconos de la línea de tiempo y avatar

- `.thread-event .type-icon::after` (el lápiz de «Created by», el reloj de
  «Flagged as overdue») es una caja gris con un **borde blanco de 16 px** que
  tapa la línea vertical del hilo. En claro pasa desapercibido; en oscuro era
  un cuadrado blanco. Ahora el borde y la caja son del tema.
- El aviso de vencimiento traía **dos iconos**: el GIF de
  `.Icon.overdueTicket` y el que dibuja `ui.css` con una máscara en el
  `::before`. Se queda el de la máscara, que sí cambia de color con el tema.
- El avatar lo genera el servidor sobre un lienzo **blanco**
  (`include/class.avatar.php`) y en oscuro se veía como una ficha blanca
  suelta; ahora va en círculo, con anillo del tema.

#### 3.13.6 El realce va en la fila, no en cada celda

`table.list tbody td { background: #fff }` (`scp.css:1165`) y el realce de
`scp.css` se pintaban **por celda**. Una celda vacía —«Assigned To» de un
ticket sin asignar— se quedaba con otro fondo y en la fila seleccionada
aparecía un rectángulo de distinto tono en medio. El realce pasa a la fila
(`tr:hover`, `tr.highlight`) y las celdas van transparentes, en los dos temas.

#### 3.13.7 «period:» y nada detrás

`include/staff/dashboard.inc.php` marcaba el periodo elegido con un script que
**borraba** el atributo `selected` de la opción marcada y lo ponía en otra. Al
quitar el atributo, el navegador se queda **sin selección**: el desplegable
aparece vacío hasta que el usuario elige algo. Ahora la opción se marca en el
servidor (con `$report->end`, escapada con `Format::htmlchars()`) y el script
se eliminó: funciona también sin JavaScript.

#### 3.13.8 La cola no promete filas que no trae

«Showing 1 - 23 of about 23» sobre una tabla **sin una sola fila**
(`tickets.php?queue=14`, «Closed · This Year»). El total y la tabla pueden
discrepar; cuando discrepan, la página miente y el agente no sabe si la cola
está vacía o rota.

- La plantilla cuenta ahora las filas que **de verdad** ha pintado
  (`$rendered`). Si no hay ninguna, el pie no promete ninguna, el paginador
  olvida el total (`setTotal(0, true)`) y se explica el motivo en el estado
  vacío que ya existía.
- **El dato y la consulta están bien.** Medido en la instalación donde se vio
  (`make queue-check QUEUE=14 QUEUE_ARGS=--sql`): la consulta devuelve **23**
  filas, el total de esa misma consulta es 23, `getCount()` dice 23 y
  `getTotal()` 23; ninguna columna se come filas (23 con cada una); la vista
  `ost_ticket__cdata` está completa (60 de 60, 0 tickets sin fila); los 23
  cierres caen dentro del rango (`2026-01-01 → 2027-01-01`) y el agente ve los
  ocho departamentos. Veredicto de la herramienta: **«los números cuadran»**.
  Ni la vista materializada ni el criterio de periodo explican la tabla vacía.
- Lo que queda por descartar es el **paso de filas a modelos**: la consulta
  devuelve filas y la plantilla las convierte en `Ticket` una a una. Ese hueco
  no lo medía nada, así que `make queue-check` gana `--hydrate`:

  ```bash
  make queue-check QUEUE=14 --as=d.martinez --hydrate
  ```

  Recorre las filas como lo hace la página y añade a «Números» cuántos modelos
  se construyen, cuántos salen con `ticket_id` vacío (la firma del mapa de
  campos corrido que dejó la barra de colas vacía en `cb6b8f7`) y los primeros
  números de ticket. Si la base devuelve 23 y aquí salen 0, el problema está en
  la hidratación; si salen 23, la página tiene que pintarlos y lo que falla es
  otra cosa (caché del navegador, `pjax`).
- **Medido** (`make queue-check QUEUE=14 QUEUE_ARGS="--hydrate --as=d.martinez"`):
  la base devuelve 23, el total 23, `getCount()` 23, `getTotal()` 23 y la
  hidratación construye **23 modelos con `ticket_id`** (0 vacíos; los primeros
  números son 318481, 491656, 881767…). O sea: **el dato, la consulta y el ORM
  están bien**. La plantilla pinta lo que recibe.
- **La causa de la tabla vacía era el paginador.** `paginateSimple()` pone el
  OFFSET tal cual, así que un `?p=` más allá del final —historial del
  navegador, un enlace guardado, o un filtro que acaba de reducir la cola— pide
  filas a partir de la 26 de una cola de 23 y la tabla sale vacía aunque el
  total sea 23. La plantilla calcula ahora la ventana **después** de conocer el
  total y recorta la página pedida a la última que existe (`is_int($count)`
  distingue el total desconocido, que se deja como estaba). El «esta cola tiene
  tickets, pero la lista no se ha podido cargar» que se añadió como red ya no
  puede dispararse por esto.
  `make queue-check` añade a «Números» **cuántas páginas hay** con ese total,
  para que se vea de un vistazo si `?p=2` existe.

#### 3.13.9 Tiempos de respuesta negativos

La tabla por departamento publicaba cifras como «-3.5 h». El *Statistics*
nativo mide el tiempo de respuesta como
`timestampdiff(HOUR, parent.created, entry.created)`; si la respuesta tiene
fecha **anterior** a su mensaje padre, la resta sale por debajo de cero.

- **Origen**: `Ticket::create()` inserta la entrada con el mensaje de apertura
  con `NOW()`, y el sembrador sólo fechaba hacia atrás el ticket, el hilo y los
  eventos. Las respuestas —sí fechadas en el pasado— quedaban antes de su
  padre. `SeedManager::backdate()` fecha ahora también las entradas del hilo,
  que en ese momento es sólo la de apertura.
- **Cinturón**: el agregado del informe va envuelto en `GREATEST(..., 0)`. Una
  base sembrada antes del arreglo conserva esas filas; se acota en la consulta
  —no al imprimir— para que el promedio tampoco se contamine.
  `tests/ReportTimeTest.php` lo fija.
- **Reparación de lo ya sembrado**: `make seed-repair`
  (`manage.php seed --repair`) recoloca esas entradas detrás de su padre,
  respetando el orden de escritura, y recalcula `thread.lastmessage`. La
  revisión real de la instalación encontró **48** entradas así. Es el arreglo
  de datos; la cota del informe es la red por si queda alguna.

#### 3.13.10 La purga de la base de conocimiento no veía lo que tenía que borrar

La instalación del usuario tenía **33 artículos huérfanos** —su `category_id`
apunta a categorías que ya no existen y su pregunta no está en el manifiesto
del sembrador—, y el doctor los contaba con su propia consulta (`SELECT … LEFT
JOIN ost_faq_category … WHERE c.category_id IS NULL`). Al pedir la purga,
`make seed-repair SEED_ARGS=--purge-orphans` respondía **«no hay artículos que
borrar»** y los 33 seguían ahí.

- **Causa**: `FAQ::$meta` declara `select_related('category')` y ese JOIN se
  compila como **interno**. `FAQ::objects()->all()` devuelve modelos `FAQ`
  hidratados con la categoría, y un artículo cuya categoría no existe **no
  sobrevive al JOIN**: la purga recorría cero filas. El desajuste de fondo es
  que el chequeo contaba con SQL y la reparación leía con el ORM, y los dos no
  veían lo mismo.
- **Arreglo**: la purga apaga la relación para leer la tabla tal cual
  (`$query->related = false`, el mismo recurso que usa el núcleo en
  `compileCount()`), cuenta cuántos artículos ha leído, enumera los que va a
  borrar y comprueba el valor que devuelve `FAQ::delete()` —devuelve `false`
  en vez de lanzar— para que «borrados» no sea una suposición.
- **Segundo defecto, en los textos**: el doctor y `docs/SEED.md` recomendaban
  `make seed --only=faq`, que **no funciona**: make se queda con `--only` como
  opción suya y responde `unrecognized option '--only=faq'` (lo reportó el
  usuario al ejecutarlo). Ahora se recomienda `make seed-faq`, objetivo nuevo
  del Makefile que ejecuta el paso `faq`, y la forma general
  `make seed SEED_ARGS=--only=faq`. `tests/SeedDataTest.php` falla si vuelve a
  aparecer la forma rota, si la purga no apaga la relación o si el contador no
  mira el resultado del borrado.

#### 3.13.11 Cómo se comprueba

- `php tools/ui/theme-check.php` → **188** comprobaciones (antes 178), sin
  cambios en esa tanda; **195** desde la tanda de las pantallas de
  administración (§3.14). Las
  nuevas: select2 v4, el buscador por tipo, la ficha en los dos temas,
  `.type-icon::after`, `body #line-chart-here`, las etiquetas del gráfico, el
  guardia del pie de la cola, la fecha de la entrada de apertura, la cota del
  informe y la opción `--repair` del doctor.
- `php tools/quality/sql-audit.php` → **en verde** desde el 2026-10-27. Estuvo
  en rojo por 4 archivos del CLI y el movimiento de la llamada del ORM a
  `class.orm.sql.php`; se cerró con la categoría `cli` que estaba propuesta en
  `docs/CODE_QUALITY.md` (frente 3): la aplicación queda en **36 llamadas, 25
  marcadas y 11 por migrar**, y las **17 del CLI** se informan aparte porque no
  están en el camino de una petición.
- `php tests/run.php` → medido en **php-wasm** (sin `dom` ni `mbstring`):
  **942 correctas, 0 fallos, 5 omitidas**. **Veintitrés** son de la última
  tanda: nueve en `tests/MailerMessageTest.php` (el separador de la respuesta
  citada y la reescritura de los `cid:`, sacados de `Mailer::send()`), once en
  `tests/TicketPostTest.php` (los destinatarios del aviso de mensaje nuevo,
  sacados de `Ticket::postMessage()`) y tres en `tests/MassActionTest.php` (el
  permiso de combinar/enlazar, departamento por departamento). Las **treinta y
  siete** de la tanda anterior siguen ahí:
  dos en `tests/SeedDataTest.php`, una en `tests/QuerySqlTest.php`, una en
  `tests/QueueEmptyStateTest.php` (el `?p=2`), una en `tests/QueueCheckTest.php`
  (el recuento de páginas), dieciocho en `tests/MassActionTest.php` (los textos de
  los diálogos en lote, el análisis de combinar/enlazar y los desplegables de
  asignación), cuatro en `tests/FileManagerTest.php` (el gestor de
  archivos del CLI), cuatro en `tests/TicketSlaTest.php` (el rasgo con los
  vencimientos del ticket) cinco en `tests/TicketsAjaxSplitTest.php` (el
  formulario de estado en su rasgo) y cinco en `tests/SearchMysqlSplitTest.php`
  (el backend de búsqueda en el suyo).
  En el contenedor de referencia, con `dom`, esa cuenta sube en las que se
  omiten aquí.
- `sw.js` sube a **`ost-v36`** para que el service worker suelte las hojas
  viejas (v35 fue la tanda de los frentes 2 y 6; v36, las pantallas de
  administración de §3.14).

#### 3.14 Las pantallas de administración (`table.form_table`)

El panel de agentes tenía dos lenguajes: la cola, la ficha del ticket y los
diálogos siguen la capa moderna, pero **Ajustes** (`scp/settings.php`) y las
pantallas de administración (`scp/admin.php`) se quedaron con el aspecto
original de `scp/css/scp.css`, que es donde más se nota:

| Lo que había | Lo que hay |
|---|---|
| Tabla sin tarjeta: sólo dos bordes `#ddd` a los lados, sin arriba ni abajo | Tarjeta con borde, esquinas redondeadas y sombra de 1 px, igual que las tablas de la cola |
| Celdas con 4 px de aire: la etiqueta y el campo se tocaban | 10-14 px, alineados arriba para que una etiqueta de dos líneas no descuadre el campo |
| Cabecera gris `#929292` con el texto en blanco, y un `h4` que pintaba **una segunda banda** encima (`#5a636b` en claro por la corrección de contraste de `ui.css`) | Una sola banda suave (`--ui-surface-alt`) con el texto del panel y el `h4` transparente |
| `em` de ayuda en `#666` fijo y errores en `#f00` | Tokens (`--ui-text-muted`, `--ui-danger`) y un paso menos de tamaño para los errores |
| Ningún resalte de fila | La fila bajo el puntero se aclara, salvo las celdas `.nohover` (la del botón de guardar) |
| La ayuda contextual (`?`) siempre a plena opacidad | Al 55 % y a pleno al pasar por la fila |

Tres decisiones que no son de adorno:

1. **`border-collapse: separate`**. Con `collapse` el navegador ignora el
   `border-radius` de la tabla: sin `separate` las esquinas se quedan rectas y
   el redondeo no se dibuja.
2. **`:nth-last-child(2)` para la etiqueta**. Las filas de un solo campo
   (`colspan="2"`: la ayuda de la cabecera, el pie con los botones) no son
   etiquetas. Sin ese filtro el texto de ayuda salía en negrita y a 12 px de
   aire arriba, como si fuera el nombre del ajuste.
3. **`overflow: hidden` es seguro aquí**. Los globos de ayuda
   (`scp/js/tips.js`), el calendario (`jquery-ui`) y los desplegables de
   select2 se montan en `<body>`, no dentro de la tabla; el `overflow` sólo
   recorta las esquinas de la banda de cabecera.

`div.section-break` (el separador de sección de los formularios largos, que
también usan los formularios dinámicos en su `SectionBreakWidget`) pasa a ser
una ficha suave, y `ui.css` deja de oscurecer la banda de la cabecera porque
ya no hay blanco sobre gris que corregir. En oscuro, `dark.css` mantiene la
banda en `--dk-surface-3` y el `h4` transparente.

La maqueta `docs/theme-preview.html` trae ahora la pantalla de ajustes
completa —pestañas, `.tab_content`, dos `form_table` y un `section-break` en
medio— para revisarla sin Docker, en los dos temas.

**Cómo se comprueba.** `php tools/ui/theme-check.php` → **195**
comprobaciones (antes 188). Las siete nuevas: la tarjeta con
`border-collapse: separate`, el filtro `:nth-last-child(2)`, la cabecera sin
segunda banda en claro y en oscuro, `ui.css` sin la banda `#5a636b`, el
resalte de fila y la pantalla de ajustes en la vista previa. Se verificó que
la comprobación del filtro falla de verdad cambiando `:nth-last-child(2)` por
`:first-child`.

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

Automático, sin navegador:

```bash
make theme          # 144 comprobaciones: tema, densidad, capa moderna y orden de carga
make unit           # incluye la suite de codificación y la de salida de librerías
```

Manual (5 minutos), con la vista previa abierta
(`docs/theme-preview.html` y `docs/theme-preview-client.html`):

- [ ] La pestaña activa de la barra superior se distingue sin leerla (va rellena).
- [ ] Al desplazar una cola larga, la cabecera de la tabla queda pegada arriba.
- [ ] Con el tema oscuro puesto, la capa moderna **no** deja nada blanco
      (`make dark-audit`).
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
- [ ] Con un lector de pantalla, los botones de solo icono anuncian su acción
      (no «círculo, signo más»).
- [ ] Con `OST_DEFAULT_THEME=dark` no aparece ningún destello blanco al
      cargar (recarga con Ctrl+F5 y observa).
- [ ] En oscuro se leen bien: login, cola de tickets, un ticket con respuesta
      y nota, el diálogo de respuesta y una tabla de configuración.
- [ ] Una entrada con HTML de correo (tabla, imagen, enlace) sigue legible
      sobre el papel claro.
- [ ] Los iconos se ven nítidos y alineados con su texto en las dos densidades
      y en los dos temas (son SVG; compara con `data-svg-icons="off"`).
- [ ] En el alta de cuenta, el medidor sube al escribir (`abc` → Débil,
      `Tr0ub4dor&3` → Buena) y avisa si las dos contraseñas no coinciden.
- [ ] En el panel, el diálogo «Change Password» del agente también muestra
      el medidor (se engancha solo, sin atributos).
- [ ] Un correo con cita, tabla y bloque de código se lee entero en oscuro,
      y el visor de un PDF adjunto se ve sobre fondo blanco.
- [ ] En oscuro, la barra del editor de respuesta muestra los iconos y el
  area de adjuntos se lee (borde, texto y enlace "elegir").
- [ ] La barra de menús del panel (Emails, Settings, Banlist, Templates,
      Diagnostic) usa iconos de un solo color, sin cuadros claros, y el texto
      no se solapa.
- [ ] `make theme` en verde.
- [ ] `make dark-audit` no encuentra superficies claras (ver abajo).

**Auditoría de contraste con navegador** (lo que encontró los huecos de la
tabla anterior). `tools/ui/contrast-audit.js` abre una página, calcula el
contraste real de cada texto visible —incluidos los menús desplegables, que
sólo se pueden medir abiertos— y lista los que no llegan a WCAG 2.1:

```bash
make contrast-audit                                # vista previa del panel
make contrast-audit-client                         # vista previa del portal
node tools/ui/contrast-audit.js --theme light      # comprobar que no se rompió el claro
node tools/ui/contrast-audit.js http://localhost:8090/scp/tickets.php \
     --cookie "OSTSESSID=<tu-sesion>"              # contra la app real
```

**No hace falta instalar nada.** `tools/ui/browser-audit.sh` usa el Node con
Playwright del equipo si lo hay; si no, ejecuta la comprobación dentro de la
imagen oficial de Playwright con Docker (`make docker-dark-audit` la fuerza).
Sólo si no hay ninguna de las dos cosas se omite con un aviso: es opcional y
no entra en `make ci`.

> La imagen oficial **no trae el paquete de Node**: su Dockerfile instala
> `playwright-core`, descarga los navegadores en `/ms-playwright` y borra el
> paquete. Por eso el lanzador lo instala la primera vez en el volumen
> `osticket-playwright` (unos 3 MB). Las siguientes ejecuciones no descargan
> nada. Si no hay red, la auditoría se omite sin romper el resto.

Sale 0 si no hay hallazgos y 1 si los hay, así que se puede encadenar. Las
dos maquetas están a **0 hallazgos en oscuro y en claro**:

```bash
node tools/ui/contrast-audit.js docs/theme-preview.html                # panel
node tools/ui/contrast-audit.js docs/theme-preview-client.html         # portal
node tools/ui/contrast-audit.js docs/theme-preview.html --theme light
node tools/ui/contrast-audit.js docs/theme-preview-client.html --theme light
```

### 5.1 Buscar zonas claras en oscuro

El contraste mide texto; esto mide **superficies**. `tools/ui/dark-audit.js`
recorre la página en oscuro y lista los elementos visibles cuyo fondo sigue
siendo claro: un `#fff` que se coló, una hoja que carga después de
`css/dark.css`, una tabla de un plugin. Es la comprobación que evita el «aquí
se ve un cuadro blanco» que sólo aparece a ojo.

```bash
make dark-audit                 # vista previa del panel
make dark-audit-client          # vista previa del portal
make docker-dark-audit          # lo mismo, siempre dentro de Docker
node tools/ui/dark-audit.js http://localhost:8090/scp/tickets.php \
     --cookie "OSTSESSID=<tu-sesion>"
```

Con Docker la URL `localhost:8090` se traduce a `host.docker.internal:8090`
para que el contenedor llegue a tu instalación.

Hay superficies que **deben** ser claras: el cuerpo de un correo, el visor de
un PDF y el editor enriquecido son «papel» a propósito. Se marcan en el HTML
con `data-allow-light` y el auditor las ignora (también ignora imágenes,
`iframe` y elementos translúcidos). Las dos maquetas dan **0 hallazgos**.

Sale 0 si no hay hallazgos y 1 si los hay, así que se puede encadenar.

Herramientas recomendadas además: Lighthouse (accesibilidad), axe DevTools,
WAVE, y el modo de emulación de `prefers-reduced-motion` del navegador.

---

## 6. Estado y siguientes pasos

### 6.1 Entregado (marcado como completado)

Se marca `[x]` lo terminado y probado en la vista previa; `[ ]` es lo que
queda en §6.2.

| # | Mejora | Estado | Dónde está |
|---|---|---|---|
| 1 | Capa CSS de accesibilidad y consistencia, enlace de salto, regiones vivas, etiquetas de formulario y tablas responsive | ✅ Hecho | `css/ui.css`, `include/staff/header.inc.php`, `include/client/header.inc.php` |
| 2 | **Modo oscuro global**: selector en la cabecera, persistencia por usuario, respeto a `prefers-color-scheme` y valor por defecto configurable. Cubre el panel, el portal del cliente y las vistas previas | ✅ Hecho | `css/dark.css`, `js/theme.js`, `OST_DEFAULT_THEME` |
| 3 | **Densidad** cómoda o compacta, con su propio botón y valor por defecto | ✅ Hecho | `css/density.css`, `OST_DEFAULT_DENSITY` |
| 4 | **Diálogos accesibles**: foco atrapado mientras están abiertos, devuelto al cerrarlos y cierre con Escape | ✅ Hecho | `js/dialog-focus.js` |
| 5 | **Iconos**: los de Font Awesome se marcan como decorativos y los enlaces de solo icono reciben nombre accesible a partir de su `title` | ✅ Hecho | `js/icons.js` |
| 6 | **Iconos en SVG**: 155 iconos dibujados en línea, con color y tamaño heredados, sin depender de la fuente (§3.5) | ✅ Hecho | `js/icons.js` (`SVG_ICONS`) |
| 7 | **Indicador de fuerza de la contraseña** en el portal **y** en el panel, también dentro del diálogo AJAX de cambio de contraseña del agente (§3.6) | ✅ Hecho | `include/templates/password-strength.inc.php`, `js/password-strength.js` |
| 8 | **HTML de terceros en oscuro**: citas, código, tablas, visor de PDF y editor enriquecido legibles (§3.7) | ✅ Hecho | `css/dark.css` §«8bis» |
| 9 | **Iconos de todos los menús del panel** en los dos temas: se retiran los PNG de `#sub_nav` (Emails, Templates, Filters, Users, Teams, Help Topics, Departments, Preferences, Logs, SLA, API, Pages, Forms, Lists, Premade…) y se sustituyen por máscaras SVG con color heredado | ✅ Hecho | `css/ui.css` (§3.5.1) |
| 10 | **Respaldo de `PATH_INFO`**: si el servidor no rellena `PATH_INFO` (servidor embebido de PHP, proxys o FastCGI sin `fastcgi_split_path_info`) se deduce la ruta de `REQUEST_URI` restando el script, y se normaliza la barra inicial. Deja de haber 400 en `scp/ajax.php/...` (§7) | ✅ Hecho | `include/class.osticket.php` (`get_path_info()`) |
| 11 | **Comprobación automática del tema** con 187 verificaciones (178 antes de §3.13) y auditoría de contraste a 0 hallazgos sobre la vista previa, en claro y en oscuro | ✅ Hecho | `tools/ui/theme-check.php`, `tools/ui/contrast-audit.js` |
| 12 | **Iconos de `#nav` y `#sub_nav` con máscaras SVG**: se retiran los PNG/GIF de fondo (varios con fondo blanco) y los glifos de Font Awesome, y el icono pasa a heredar el color del texto en los dos temas (§3.5.1) | ✅ Hecho | `css/ui.css`, `css/dark.css` |
| 13 | **Avisos con máscara SVG**: `error_bar`, `warning_bar`, `notice_bar`, `msg_*` y los `*-banner` dejan de usar `error.png`/`alert.png`/`ok.png` (PNG con fondo blanco) y toman color propio en cada tema (§3.5.2) | ✅ Hecho | `css/ui.css`, `css/dark.css` |
| 14 | **Acciones declarativas** `data-href`, `data-go-back`, `data-submit-on-change`, `data-dialog`, `data-confirm`, `data-remove-closest`, `data-toggle-target`, `data-inherit-toggle`, `data-save-draft` y `data-discard-draft`: dos tandas de la migración de `onclick` inline (§3.9, §6.2-1) | ✅ Hecho | `js/actions.js`, ambos `footer.inc.php` |
| 15 | **Auditoría de manejadores inline** con umbral: `make inline-audit` lista, cuenta y falla si el número sube | ✅ Hecho | `tools/ui/inline-handlers.php` |
| 16 | **Iconos de ayuda que ya no desaparecen**: al pasar a SVG se conservan `href`/`data-*`, y `tips.js` deja de borrar el icono cuando no encuentra la ayuda (§3.5) | ✅ Hecho | `js/icons.js`, `scp/js/tips.js` |
| 17 | **CSP con nonce**: los 98 `<script>` en línea de las plantillas llevan `nonce`, `script-src` deja de anunciar `'unsafe-inline'` y el instalador tampoco lo necesita (§6.2-1b, OST-102) | ✅ Hecho | `include/class.http.php`, `tools/quality/csp-nonce.php`, `tests/CspNonceTest.php` |
| 17b | **Datos de demostración** para poder mirar la interfaz con contenido real: 59 tickets repartidos entre **todos los estados** de la lista interna (abierto, en progreso, contestado, resuelto y cerrado) con hilos de hasta 14 mensajes que alternan cliente, agente y nota interna, 16 tareas abiertas/completadas/vencidas, 12 clientes en 3 organizaciones, 5 agentes, 5 departamentos, 2 equipos, 2 SLA, 11 temas, 5 respuestas y 6 categorías de conocimiento con 33 artículos, creados con la API del panel y borrables con `--fresh` | ✅ Hecho | `include/cli/modules/seed.php`, `make seed`, `docs/SEED.md` |
| 18 | **Capa moderna** (`css/modern.css`): lienzo de hasta 1280 px, tipografía del sistema, cabecera plana, navegación en pastillas con la pestaña activa rellena, contenido en tarjeta, tablas sin rejilla con cabecera pegajosa, búsqueda de una pieza, botones con acción principal diferenciada, avisos con filete de color y pantallas de acceso como tarjeta. Sin un solo color literal: hereda el modo oscuro de los tokens de `ui.css` (§3.10) | ✅ Hecho | `css/modern.css`, tres cabeceras, `sw.js` (v35), `make theme` |
| 17c | **Buzón de pruebas documentado**: Mailpit paso a paso (host `mailpit`, puerto 1025, sin TLS ni usuario, qué mirar, entrante con `api/pipe.php`, cron a mano) y `make test-mail` para un envío sin pasar por la aplicación. Es lo que permite comprobar los correos reales en claro y en oscuro | ✅ Hecho | `docs/MAIL.md`, `tools/dev/mail-test.php` |

| 19 | **Escala del texto**: el cuerpo pasa a 15 px y los campos de formulario fijan su tamaño (`input`, `select`, `textarea` no heredan: salían a los 13.3 px de Arial del navegador). Tres tokens (`--mod-text`, `--mod-text-sm`, `--mod-text-xs`), ningún texto por debajo de 12 px y ninguna medida suelta en el modo compacto (§3.11) | ✅ Hecho | `css/modern.css`, `tests/TextScaleTest.php`, `make theme` |
| 20 | **Etiqueta de prioridad**: el color en línea que `PriorityField::display()` escribe en el `<td>` **y** en el `<div>` ya no pinta la celda entera; queda en una etiqueta redondeada del tamaño de su texto. El contraste en oscuro se mantiene (§3.11) | ✅ Hecho | `css/modern.css`, `css/dark.css`, `docs/theme-preview.html` |
| 21 | **Barra de colas recuperada**: la causa era el mapa de campos del ORM (`SelectSql::addAnnotations()` se quedaba el `fieldMap` y `buildModel()` repartía la fila corrida, dejando `parent_id` contaminado). `queue --nav` lo verifica y `--trace` lo explica paso a paso (§3.5.3) | ✅ Hecho | `include/class.orm.select.php`, `tests/QuerySqlTest.php`, `include/cli/modules/queue.php`, `make queue-nav` |
| 22 | **Editor enriquecido**: barra por grupos con historia (deshacer/rehacer), sangría, subíndice/superíndice, viñetas y numeración sueltas y pantalla completa —cuyo plugin faltaba y por eso el botón no existía—, separadores de grupo y botones planos. `tests/EditorToolbarTest.php` valida cada botón contra el propio Redactor (§3.12.2) | ✅ Hecho | `js/redactor-osticket.js`, `css/modern.css`, `tests/EditorToolbarTest.php` |
| 23 | **El tema oscuro llega por fin al editor**: la superficie editable es `.redactor-in` (no `.redactor-editor`, que no existe en Redactor 3) y la barra necesitaba una regla de cuatro clases para ganar a `redactor.css`. Papel `--dk-editor-paper` en vez de blanco puro (§3.12.1) | ✅ Hecho | `css/dark.css`, `css/mobile.css`, `tools/ui/dark-audit.js` |
| 24 | **Tabla de estadísticas del panel** sin rejilla doble: una línea por fila, cebra, cabecera en versalitas y cifras tabulares (§3.12.4) | ✅ Hecho | `css/modern.css`, `css/dark.css` |
| 25 | **Ficha del ticket y base de conocimiento**: asunto legible en oscuro, nota interna y panel de respuesta tematizados, pestañas del tema, «Priority:» nunca vacío, buscador con pista y estado vacío en las dos salidas de la KB (§3.12.5) | ✅ Hecho | `css/dark.css`, `css/modern.css`, `include/staff/ticket-view.inc.php`, `include/staff/faq-categories.inc.php` |
| 26 | **Arrastrar ficheros**: el aviso de «puedes soltar aquí» deja de ser un `background-color` en línea y pasa a ser una clase (`ost-drop-over`) que cada tema pinta; la zona de adjuntos se lee como tal (§3.12.3) | ✅ Hecho | `js/filedrop.field.js`, `css/modern.css`, `css/dark.css` |
| 27 | **Select2 v4 tematizado**: el tema sólo cubría la API v3 del plugin, así que la caja de la respuesta predefinida y el desplegable de filtros seguían blancos. Bloque completo de v4 en oscuro y caja con tokens en claro; el buscador del tema pasa a `#basic_search input[type="text"]` y alcanza también al de la base de conocimiento (§3.13.1, §3.13.2) | ✅ Hecho | `css/dark.css`, `css/modern.css` |
| 28 | **Ficha del ticket como un solo panel**: dos tablas dentro de otra con tonos distintos, etiquetas pegadas al borde y separación sólo en una mitad. Ahora fondo transparente, etiquetas atenuadas y una línea por fila, en los dos temas (§3.13.3) | ✅ Hecho | `css/modern.css`, `css/dark.css` |
| 29 | **Gráfico del panel**: fuera el degradado de seis capas con `filter: progid:`; etiquetas de los ejes a 12 px de la escala (venían en 8 pt de Monaco) y visibles en oscuro. Los selectores llevan `body` porque `scp/css/dashboard.css` se carga después. De paso, «period:» deja de salir vacío: la opción se marca en el servidor y no con un script que borraba `selected` (§3.13.4, §3.13.7) | ✅ Hecho | `css/modern.css`, `css/dark.css`, `include/staff/dashboard.inc.php` |
| 30 | **Iconos de la línea de tiempo y avatar**: el borde blanco de 16 px de `.type-icon::after` pasa al tema, se retira el segundo icono del aviso de vencimiento y el avatar —que el servidor dibuja sobre lienzo blanco— va en círculo con anillo del tema. El realce de las tablas pasa de la celda a la fila, así una celda vacía no queda de otro tono (§3.13.5, §3.13.6) | ✅ Hecho | `css/dark.css`, `css/modern.css` |
| 31 | **Verdades de la pantalla**: la cola ya no dice «Showing 1 - 23» con la tabla vacía (`$rendered` + `setTotal(0)`), y el tiempo de respuesta no puede ser negativo (la apertura se fecha con el ticket en `SeedManager::backdate()` y el informe acota con `GREATEST`). El origen de las filas invisibles —la vista `ost_ticket__cdata`— lo detecta `make seed-doctor` (§3.13.8, §3.13.9) | ✅ Hecho | `include/staff/templates/queue-tickets.tmpl.php`, `include/cli/modules/seed.php`, `include/class.report.php`, `tests/QueueEmptyStateTest.php`, `tests/ReportTimeTest.php` |
| 32 | **`make seed-repair`**: el doctor prometía `--doctor --repair` en sus pistas y la opción **no existía**. Ahora repara sin borrar ni volver a sembrar: artículos sin categoría (por pregunta del manifiesto), entradas fechadas antes que su padre (y `thread.lastmessage`), restos de hilo y vistas de datos. Repasa la revisión al terminar (`docs/SEED.md`) | ✅ Hecho | `include/cli/modules/seed.php`, `Makefile`, `tests/SeedDataTest.php`, `docs/SEED.md` |
| 38 | **Pantallas de administración con el lenguaje del panel**: `table.form_table` / `.settings_table` (Ajustes, administración y los diálogos de configuración) pasan de dos bordes sueltos, celdas de 4 px y cabecera gris con texto blanco a tarjeta con esquinas redondeadas, filas con aire, una sola banda de cabecera y la etiqueta sólo en las filas de dos columnas (`:nth-last-child(2)`); resalte de fila, errores con token y ayuda contextual atenuada. `ui.css` deja de corregir el contraste de la banda porque ya no existe, y la vista previa trae la pantalla de ajustes completa (§3.14) | ✅ Hecho | `css/modern.css`, `css/dark.css`, `css/ui.css`, `docs/theme-preview.html`, `tools/ui/theme-check.php` |
| 39 | **`Mailer::send()` deja de ser la función más larga**: de 270 a **147** líneas. Dos piezas puras nuevas en `MailerMessage` (`replyTag()`, con los tres casos del separador de la respuesta citada, y `rewriteContentIds()`, el `cid:` de las imágenes incrustadas) y cuatro pasos privados con nombre en `Mailer` (`addRecipients()`, `attachFiles()`, `deliverViaSmtp()` y `deliverViaSendmail()`); ni el envío ni el orden de los transportes cambian. El permiso de combinar/enlazar sale a `MassAction::mergePermissions()` y el diálogo pasa a abrirse sobre el **primero** de la lista, no sobre el último leído Y los destinatarios del aviso de mensaje nuevo salen de `Ticket::postMessage()` a `TicketPost::alertRecipients()` / `alertableRecipients()`, con once pruebas de dobles, así que `postMessage()` baja de 178 a **142** líneas (`docs/CODE_QUALITY.md`, frente 2) | ✅ Hecho | `include/class.mailer.php`, `include/class.mailer.message.php`, `include/class.massaction.php`, `include/ajax.tickets.php`, `tests/MailerMessageTest.php`, `tests/MassActionTest.php`, `include/class.ticket.post.php`, `include/class.ticket.replies.php`, `tests/TicketPostTest.php` |
| 37 | **Frente 6, tercera parte**: el backend de búsqueda de MySQL (`MysqlSearchBackend` y su configuración, 480 líneas) sale a `include/class.search.mysql.php` con su registro (`Signal::connect()`, `register()`) para que el buscador no desaparezca en silencio; `include/class.search.php` pasa de 1.955 a **1.476** líneas y lo incluye al final, después de la clase de la que hereda. `tests/SearchMysqlSplitTest.php` fija el orden y que no queden copias; `SearchRecordTest` pasa a leer el archivo nuevo (`docs/CODE_QUALITY.md`, frente 6) | ✅ Hecho | `include/class.search.mysql.php`, `include/class.search.php`, `tests/SearchMysqlSplitTest.php`, `tests/SearchRecordTest.php` |
| 36 | **Frente 6, segunda mitad**: el formulario de estado del ticket (abrir, cerrar, borrar, marcar como duplicado o respondido) sale a `include/ajax.tickets.status.php` como rasgo `TicketsAjaxStatus`, con los cinco métodos enteros y las rutas del panel intactas: `include/ajax.tickets.php` pasa de 1.677 a **1.333** líneas y deja de ser el archivo más grande del panel. Los ayudantes privados se quedan en la clase porque el rasgo los llama con `self::`; `tests/TicketsAjaxSplitTest.php` lo comprueba todo por código fuente, incluido que no queden copias de los métodos movidos (`docs/CODE_QUALITY.md`, frente 6) | ✅ Hecho | `include/ajax.tickets.status.php`, `include/ajax.tickets.php`, `tests/TicketsAjaxSplitTest.php`, `tools/quality/class-map.php` |
| 35 | **Frentes 2 y 6, tanda del 2026-10-27**: los textos de los diálogos en lote salen de `MassAction` (`assignPrompt()`, `confirm()`, `failure()`, `agentOrder()`) y el **diálogo de asignación** deja de estar duplicado (`memberOptions()`, `teamAssignees()`, `finish()` y las consultas de agentes de cada tipo), así que `massProcess()` baja de 321 a **227** (tickets) y de 287 a **191** (tareas); el análisis de combinar/enlazar sale a `analyzeMerge()` con siete pruebas; `run()` del gestor de archivos del CLI pasa de 408 a **12** líneas, con una acción por método y un aviso de verdad para la acción inventada; y los vencimientos/SLA del ticket se van al rasgo `TicketSla`, con `class.ticket.php` de 1.982 a **1.834** (`docs/CODE_QUALITY.md`, frentes 2 y 6) | ✅ Hecho | `include/class.massaction.php`, `include/ajax.tickets.php`, `include/ajax.tasks.php`, `include/cli/modules/file.php`, `include/class.ticket.sla.php`, `tests/MassActionTest.php`, `tests/FileManagerTest.php`, `tests/TicketSlaTest.php` |
| 34 | **El paginador no deja la tabla vacía**: `?p=` más allá del final pedía filas desde la 26 de una cola de 23, y la tabla salía vacía con el total en 23. Ahora la ventana se calcula después del total y la página se recorta a la última que existe; `make queue-check` dice cuántas páginas hay. Con eso queda cerrado el caso de la cola «Closed · This Year»: la base, la consulta y el ORM daban 23, la hidratación construye 23 modelos (§3.13.8) | ✅ Hecho | `include/staff/templates/queue-tickets.tmpl.php`, `include/cli/modules/queue.php`, `include/cli/modules/queue.report.php`, `tests/QueueEmptyStateTest.php`, `tests/QueueCheckTest.php` |
| 33 | **La purga de la base de conocimiento ve lo que cuenta el doctor**: `--purge-orphans` leía con el ORM, cuyo `select_related('category')` es un JOIN interno, así que enumeraba **cero** artículos con 33 huérfanos en la tabla; ahora lee sin la relación, dice cuántos ha leído y comprueba el resultado de `FAQ::delete()`. Los textos ya no recomiendan `make seed --only=faq` (make lo rechaza): `make seed-faq` es un objetivo nuevo (§3.13.10) | ✅ Hecho | `include/cli/modules/seed.repair.php`, `Makefile`, `tests/SeedDataTest.php`, `docs/SEED.md` |

Cobertura medida con `tools/ui/contrast-audit.js` sobre la vista previa
(reproduce el marcado real de las plantillas): panel (cabecera, colas,
búsqueda, listas, ficha, formularios, pestañas, avisos, diálogos, widgets,
analíticas, panel de inicio, traducciones en línea, entradas de ticket,
notas, ayudas, respuesta a un ticket) y portal del cliente (lista, ficha,
respuesta, acceso y FAQ).

### 6.2 Pendiente

| # | Mejora | Por qué sigue abierta |
|---|---|---|
| 1 | Reemplazar `onclick=` inline por `addEventListener` (habilita una CSP sin `unsafe-inline`, ver OST-102 en `SECURITY_AUDIT.md`) | ✅ Hecho: **0** manejadores en `include/` y `scp/` (desde 124 en 71), en diez tandas con `js/actions.js` (§3.9). `make inline-budget` y `make theme` impiden que vuelvan |
| 1b | Quitar `'unsafe-inline'` de `script-src` | ✅ Hecho: los **98** bloques `<script>` en línea de `include/` y `scp/` llevan `nonce="<?php echo Http::nonce(); ?>"`, la CSP anuncia `script-src 'self' 'nonce-…'` y `make csp-nonce` (dentro de `make ci`) falla si aparece uno sin él. El nonce es **por sesión** porque las pantallas se montan con HTML que llega por AJAX (§3.9, OST-102) |
| 3 | Cerrar la causa de la cola «Closed · This Year» vacía con 23 en el contador | ✅ **Cerrado**: no era la consulta ni el ORM —`--hydrate` construye 23 modelos—, sino el paginador (`?p=` más allá del final). Ver §3.13.8 y la fila 34 de §6.1 |
| 4 | Pasada de la purga en la instalación del usuario | ✅ **Cerrado**: `--purge-orphans` borró los 33 huérfanos, `make seed-faq` creó los 33 de demostración y `make seed-doctor` termina en «Todo cuadra.» con 33 artículos y 6 categorías (§3.13.10) |
| 7 | `make sql-audit` en rojo: 4 archivos nuevos con SQL a mano (14 llamadas) fuera de la línea base | ✅ **Cerrado** el 2026-10-27 con la categoría `cli` en `SqlAudit` (la decisión que estaba propuesta aquí): `include/cli/` es la categoría de las herramientas de operación, como el `driver`. El tope de marcas sigue siendo único para `app`+`cli`, la línea base sólo admite aplicación (con una prueba que lo impide) y el informe saca las 17 llamadas del CLI en su propia línea. La aplicación queda en 36 llamadas / 25 marcadas / 11 por migrar y `make sql-audit` está en verde (`docs/CODE_QUALITY.md`, frente 3) |
| 2 | Comprobar el modo oscuro con correos reales del buzón de la instalación | Ya hay con qué: `make seed` deja tickets y respuestas de verdad y `docs/MAIL.md` explica cómo verlos en Mailpit. Falta la pasada visual con esa bandeja llena |

## 7. Si la interfaz se ve mal después de actualizar

| Síntoma | Causa | Arreglo |
|---|---|---|
| Se queda el recuadro **«Loading …»** encima de la página y la consola dice `e.indexOf is not a function` en `jquery` | Algún elemento lleva `data-dialog` con un número (p. ej. `data-dialog="201"`). Ese atributo es de osTicket y guarda una **URL**: jQuery convierte `"201"` a `Number` y `.load()` falla | Usa `data-ost-dialog="201"` en su lugar. Desde esta versión `scp.js` ignora los valores que no sean texto, así que el modal ya no se queda colgado |
| `make dark-audit` dice **«Falta playwright» dentro de la imagen** | La imagen oficial solo trae los navegadores, no el paquete de Node | Corregido: `browser-audit.sh` instala `playwright-core` en el volumen `osticket-playwright` la primera vez. Si no hay red, se omite la auditoría |
| `make theme` responde **`Could not open input file: /ruta/del/equipo/tools/ui/theme-check.php`** | `tools/php.sh` pasaba al contenedor la ruta del equipo, pero el proyecto está montado en `/var/www/html` | Corregido: `php.sh` traduce las rutas al montaje del contenedor (`OST_CONTAINER_DIR` para cambiarlo). Actualiza el repo |
| **Los cambios de interfaz «no se ven»**: el HTML es nuevo (un texto, un campo o un botón que acabas de añadir) pero el CSS sigue siendo el viejo | Las hojas se sirven con `expires 7d`: el navegador no vuelve a preguntar. Pasó de verdad con una tanda entera de `css/modern.css` | Corregido: las hojas y scripts propios llevan `?v=<filemtime>` (`Http::asset()`), así que una URL nueva trae el archivo nuevo. Basta **una** recarga. Si vienes de una versión anterior, una última recarga forzada (`Ctrl+Shift+R`) y ya no hace falta más |
| `make theme` / `make inline-audit` responden `php: No such file or directory` | No hay PHP en el equipo | Ya no hace falta: `tools/php.sh` ejecuta el comando dentro del contenedor de herramientas (`docker compose run --rm tools …`). Basta con tener Docker y el stack levantado alguna vez |


Síntoma típico: el panel sale **sin estilos**, con los **iconos gigantes y
en negro**, o la consola muestra `400 (Bad Request)` en
`scp/ajax.php/…` con rutas que ya no existen (`lookup2a`, por ejemplo).

No es un fallo del tema: es el **navegador mezclando versiones**. El service
worker (`sw.js`) cachea JS y CSS, y hasta la versión `ost-v8` lo hacía
*caché primero*; tras un `git pull` podía servir una hoja vieja junto a un
JavaScript nuevo. Desde `ost-v8` los estáticos son **red primero**, con la
caché solo como respaldo sin conexión.

Pasos, en orden:

1. **Recarga dura**: `Ctrl + Shift + R` (o `Ctrl + F5`).
2. Si sigue igual, quita el service worker:
   DevTools → **Application → Service Workers → Unregister**, y luego
   **Application → Storage → Clear site data**. Recarga.
3. Comprueba en la consola que ya está todo nuevo:
   ```js
   document.querySelectorAll('svg.ost-icon').length   // > 0
   getComputedStyle(document.querySelector('svg.ost-icon')).width  // ~13px
   ```
   Si sale un ancho de cientos de píxeles, la hoja `css/ui.css` es vieja.
4. En **desarrollo no hay que reconstruir la imagen**: el repo está montado
   en el contenedor (`./:/var/www/html`). Basta con recargar. Solo se
   reconstruye si cambian `docker/php/Dockerfile`, la versión de PHP o las
   extensiones:
   ```bash
   make docker-build && make docker-up      # o: docker compose up -d --build
   ```
   En **producción** (`compose.prod.yaml`) el código va copiado dentro de la
   imagen, así que sí hay que reconstruir:
   ```bash
   docker compose -f compose.yaml -f compose.prod.yaml up -d --build
   ```
5. Si los `400` siguen con el código al día, **lee el cuerpo de la
   respuesta** antes de tocar nada: DevTools → **Network** → clic en la
   petición → pestaña **Response**. El texto distingue los dos casos:

   | Cuerpo del 400 | Qué significa | Qué hacer |
   | --- | --- | --- |
   | `URL not supported` | El enrutador no reconoce la ruta: el servidor no está entregando `PATH_INFO` (o lo entrega con el script delante o sin barra inicial) | Comprueba el `curl` del paso siguiente. `get_path_info()` ya lo deduce de `REQUEST_URI`; si aun así falla, el servidor no es el de `docker compose` (revisa que `docker/nginx/default.conf` tenga `fastcgi_split_path_info` y `fastcgi_param PATH_INFO`) |
   | `Valid CSRF Token Required` | La petición llegó como `POST`/`PUT`/`PATCH`/`DELETE` sin token | No es la barra de colas (`scp.js` la pide por `GET`): es otro componente. Mira a qué URL corresponde en la pestaña Network |
   | `HTTPS Protocol Required` | El panel está marcado como «forzar HTTPS» y la petición no es `GET` | Entra por `https://` o desactiva la opción en Ajustes → Sistema |

   Para ver la ruta exacta que recibió el enrutador, arranca con
   `OST_DEBUG=1` en el `.env` (o `define('OST_DEBUG', true)` en
   `include/ost-config.php`): el 400 pasa a ser
   `URL not supported: '/queue/counts'` y se ve de un vistazo si el problema
   es el `PATH_INFO` o el `JavaScript` que pide una URL que ya no existe.

   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" \
        http://localhost:8090/scp/ajax.php/queue/counts
   ```

   Debe devolver `200` o `403` (pide sesión), nunca `400`. Con sesión de
   agente (`-b cookies.txt`), `200` y un JSON con los contadores:

   ```bash
   curl -s -b cookies.txt http://localhost:8090/scp/ajax.php/queue/counts
   ```
