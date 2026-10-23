# Calidad del código: radiografía y plan

osTicket no es "espagueti" en el sentido clásico: tiene un ORM propio, clases
por dominio y una separación razonable entre `include/` (lógica), `scp/`
(páginas del panel) y `include/staff/` (plantillas). Lo que arrastra son
**cuatro problemas estructurales** que se notan al tocarlo: clases que crecen
sin límite, SQL escrito a mano al lado del ORM, JavaScript embebido en las
plantillas y ausencia total de pruebas automáticas.

Este documento mide esos problemas y propone un orden para atacarlos. No es
una lista de deseos: cada frente tiene un criterio de éxito medible.

---

## 1. Cómo se mide

```bash
make metrics          # o: php tools/quality/metrics.php
make metrics-json     # para comparar dos momentos
```

`tools/quality/metrics.php` recorre sólo el código propio (excluye `vendor/`,
`mpdf`, `pear`, `laminas-mail`) y cuenta tamaño, funciones largas y las
señales de deuda de la tabla siguiente. Es una herramienta de medida, no un
test: nunca falla, sólo informa.

**Úsala antes y después de cada refactor.** Si un cambio sube
`accesos directos a $_POST` o el tamaño medio de función, se ha ido en la
dirección contraria.

> **La medida se corrigió el 2026-10-23.** Hasta entonces el tamaño de cada
> función se calculaba contando `{` y `}` sobre el texto: una llave dentro de
> una cadena (`'Hola {nombre}'`), de un heredoc o de una expresión regular
> descuadraba la cuenta y hacía parecer gigantes funciones de 20 líneas. Por
> eso `Queue::getCriteria()` «tenía» 1.414 líneas y `class.file.php` cuatro
> funciones de 730. Ahora se recorre la lista de tokens de PHP
> (`token_get_all`), donde las cadenas son un solo token, y las señales de
> JavaScript en línea se cuentan sólo en las plantillas (`include/`, `scp/`)
> y sólo como atributos reales (`onclick="…"`), no como `onsubmit =` de
> JavaScript. Las cifras de abajo son las corregidas.

---

## 2. Radiografía (corregida el 2026-10-23)

| Métrica | Valor |
|---|---|
| Archivos PHP propios | 511 |
| Líneas | 133.280 |
| Funciones | 5.924 |
| Clases, interfaces y rasgos | 654 en 182 archivos |
| Tamaño medio de función | 10,8 líneas |
| Archivo más grande | `include/class.ticket.php` — 4.844 líneas |
| Función más larga del código propio | `run()` de `include/cli/modules/file.php` — 407 líneas (herramienta de línea de comandos) |
| Función más larga de la aplicación | `Ticket::postMessage()` — 178 líneas |

**No hay ninguna función de 1.400 líneas.** La más larga de la aplicación
ronda las 180 y la media son 11. El problema de este código no es el tamaño de
las funciones: es el tamaño de los **archivos** y la mezcla de
responsabilidades dentro de ellos.

| Señal | Ocurrencias |
|---|---|
| SQL a mano (`db_query`) | 183 |
| `db_input()` (escape manual) | 164 |
| Accesos directos a `$_POST`/`$_REQUEST`/`$_GET` | 1.269 |
| `global $...` | 560 |
| `eval()` | 15 |
| `onclick=`/`onchange=` en HTML | 0 (eran 127; ver frente 5) |
| `<script>` embebido en plantillas | 162 |

La media de 10,8 líneas por función es **buena**. Los valores atípicos son de
dos tipos: siete archivos por encima de 2.000 líneas, y las funciones de la
lista de arriba, que en su mayoría son de herramientas propias
(`tools/`, `setup/`, `cli/`) o de librerías copiadas (`htmLawed`, `JSON.php`,
`Spyc`), no de la aplicación.

Los archivos que más pesan:

| Archivo | Líneas | Clases | Qué contiene | Antes |
|---|---|---|---|---|
| `include/class.ticket.php` | 4.844 | 2 | Ticket: creación, edición, correo, SLA, adjuntos | igual |
| `include/class.forms.php` | 4.516 | 37 | Núcleo de formularios y campos | 6.220 líneas, 64 clases |
| `include/class.orm.php` | 3.663 | 34 | ORM propio (consultas, joins, instrumentación) | igual |
| `include/class.queue.php` | 2.341 | 11 | Colas, columnas y ordenación | 3.232 líneas, 38 clases |
| `include/class.thread.php` | 2.318 | 11 | Hilo de entradas, correo entrante, colaboradores | 3.405 líneas, 35 clases |
| `include/ajax.tickets.php` | 2.077 | 2 | Punto de entrada AJAX de tickets | igual |
| `include/class.dynamic_forms.php` | 2.027 | 12 | Campos dinámicos y datos de formulario | igual |

`Queue::getCriteria()` no es el problema que parecía: tiene 30 líneas y
devuelve el criterio ya resuelto. Lo que pesaba en `class.queue.php` eran sus
3.232 líneas con **38 clases** (columnas, decoraciones, condiciones, filtros y
formularios) en el mismo archivo. Ya está repartido:

| Archivo | Clases | Qué contiene |
|---|---|---|
| `include/class.queue.php` | 11 | `CustomQueue`, `QueueColumn`, `QueueConfig`, `QueueExport`, `QueueSort` y sus *glue* |
| `include/class.queue.column.php` | 17 | `QueueColumnAnnotation` y sus 13 decoraciones (contadores, iconos), `DataSourceField`, las condiciones y `LazyDisplayWrapper` |
| `include/class.queue.filter.php` | 10 | `QueueColumnFilter` y los filtros de enlace y fecha, más sus formularios de configuración |

`class.thread.php` estaba en la misma situación: mezclaba el hilo, la entrada
de correo y todo el historial de cambios. Ahora:

| Archivo | Clases | Qué contiene |
|---|---|---|
| `include/class.thread.php` | 11 | `Thread`, `ThreadEntry`, `ObjectThread`, `TicketThread`, `ThreadActivity`… |
| `include/class.thread.event.php` | 18 | `ThreadEvent` y sus eventos (asignación, cierre, edición, enlaces) |
| `include/class.thread.body.php` | 6 | `ThreadEntryBody` (texto y HTML) y las entradas de mensaje, respuesta y nota |
| `include/class.thread.email.php` | — | piezas puras de `postEmail()` (ver frente 2) |
| `include/class.thread.entry.php` | — | piezas puras de `create()` (ver frente 2) |

Y `class.forms.php` era el mayor del proyecto con diferencia. Ahora:

| Archivo | Clases | Qué contiene |
|---|---|---|
| `include/class.forms.php` | 37 | `Form`, los campos (`TextboxField`, `ChoiceField`, `DatetimeField`…), `VisibilityConstraint` y `FieldUnchanged` |
| `include/class.forms.widget.php` | 21 | `Widget` y todo lo que dibuja cada campo en pantalla |
| `include/class.forms.assignment.php` | 6 | Formularios de asignación, reclamo, liberación, marcado, referencia y transferencia |

El reparto se comprueba con `make classes`, que lista qué clase vive en qué
archivo y falla en dos casos que `make lint` no puede ver, porque cada
archivo es válido por separado:

- dos archivos declaran la misma clase sin protección (error fatal según el
  orden de carga),
- un `include`/`require` con ruta literal apunta a un archivo que no existe
  (el error aparece sólo al ejecutar esa rama).

Los polyfills y dobles de prueba (`if (!class_exists(...))`) se listan aparte:
no chocan nunca.

---

## 3. Los seis frentes, por relación impacto/riesgo

### Frente 1 — Red de seguridad antes de tocar nada (imprescindible) ✅ Hecho

No había ninguna prueba del comportamiento de la aplicación:
`setup/test/run-tests.php` valida el instalador, no el producto. **Cualquier
refactor sin esto es una apuesta.**

Lo que hay ahora:

| Pieza | Para qué |
|---|---|
| `tests/bootstrap.php` | Entorno mínimo: define `ROOT_DIR`, `INCLUDE_DIR`, `PEAR_DIR`, sustituye `__()` y carga `Format` y `Validator`. Sin base de datos, sin configuración, sin sesión |
| `tests/lib/UnitTestCase.php` | Clase base con la API de PHPUnit que usan las pruebas (asserts, `expectException`), para poder ejecutarlas sin `vendor/` |
| `tests/run.php` | Ejecutor propio: recorre `tests/*Test.php` y llama a los métodos `test*`. Es lo que se usa cuando no hay Composer |
| `tests/FormatTest.php` | 23 casos: tamaños, teléfonos, `truncate`, `mask`, `slugify`, escapado de HTML, cadenas de consulta… |
| `tests/ValidatorTest.php` | 25 casos: correo (incluido el modo lista y `localhost`), IP y redes CIDR (v4 y v6), URL, teléfono, usuario, fórmula y el validador de formularios |
| `tests/TicketPostTest.php` | 14 casos: origen e IP del mensaje, destinatarios sin el autor, rebotes |
| `tests/ThreadEmailTest.php` | 19 casos: qué correo se acepta, mensaje del cliente o nota del agente |
| `tests/ThreadEntryInfoTest.php` | 22 casos: título, firma, respuesta a todos, adjuntos incrustados |
| `tests/ClassMapTest.php` | 20 casos: lectura de clases e includes del propio código |
| `phpunit.xml.dist` | Configuración de PHPUnit para quien tenga `vendor/` |

```bash
make unit          # 123 pruebas en ~0,15 s
make unit-list     # ver qué cubre
make test          # alias de `unit`
make test-legacy   # suite del instalador (necesita base de datos)
```

Con `composer install` presente, `make unit` usa PHPUnit; si no, usa
`tests/run.php`. **Las dos vías ejecutan las mismas pruebas y dan el mismo
resultado**, así que un clon recién bajado se puede comprobar sin red y dentro
del contenedor.

Son **pruebas de caracterización**: no dicen «esto es lo correcto», dicen
«esto es lo que hace hoy». Si un refactor cambia el resultado, la prueba falla
y obliga a decidir si el cambio era intencionado.

Al escribirlas aparecieron tres defectos reales, corregidos sin cambiar el
comportamiento observable:

1. `Format::filesize2bytes()` desplazaba la cadena en vez del entero y PHP 8
   emitía «A non-numeric value encountered» en cada llamada.
2. `Validator::validate()` leía `$this->input[$k]` sin comprobar que existiera:
   un campo opcional ausente llenaba el log de «Undefined array key».
3. `Validator::is_url()` hacía lo mismo con `$info['host']` en las URLs
   relativas.

`make ci` incluye `unit` desde esta versión: si una prueba falla, no hay
integración.

### Frente 2 — Reducir las funciones y archivos que concentran la dificultad 🔄 En curso

El plan original hablaba de partir `Queue::getCriteria()` (1.414 líneas). Esa
cifra era un error de medida (§1): la función tiene 30 líneas. Con los números
buenos, el frente cambia de objetivo:

| Objetivo | Antes | Ahora |
|---|---|---|
| Funciones de la aplicación por encima de 150 líneas | 5 | **4** (ninguna pasa de 178) |
| `Ticket::postMessage()` | 190 líneas | **178** (`TicketPost`) |
| `Thread::postEmail()` | 181 líneas | **149** (`ThreadEmailInfo`) |
| `ThreadEntry::create()` | 185 líneas | **158** (`ThreadEntryInfo`) |
| Archivos por encima de 2.000 líneas | 5 | 5 |

**Criterio:** sacar de las funciones largas los tramos que no tocan la base de
datos, a una clase pequeña con nombre propio y con pruebas. Los tramos que sí
tocan la base de datos se quedan donde están hasta que haya una prueba de
integración con base de datos.

Pasos hechos: hasta ahora, 55 casos nuevos sin base de datos.

| Pieza | Qué contiene | Pruebas |
|---|---|---|
| `include/class.ticket.post.php` | `TicketPost::normalizeVars()` (origen e IP del mensaje), `withoutPoster()` (quita al autor de los destinatarios), `shouldAutoRespond()` (rebotes y respuestas automáticas) | `tests/TicketPostTest.php`, 14 casos |
| `include/class.thread.email.php` | `ThreadEmailInfo::accepts()` (ticket cerrado que no se reabre), `varsFromMail()` (traducción del correo a variables de la entrada), `entryTypeForUserClass()` (mensaje del cliente o nota del agente), `poster()` y `receivedFrom()` | `tests/ThreadEmailTest.php`, 19 casos |
| `include/class.thread.entry.php` | `ThreadEntryInfo::title()` y `poster()` (qué se guarda), `replyFlags()` (respuesta a todos / al usuario), `bodyFlags()` (HTML equilibrado y nota de sistema), `dropStrippedImages()` y `markInline()` (adjuntos incrustados) | `tests/ThreadEntryInfoTest.php`, 22 casos |

Las tres clases ya están en uso (`Ticket::postMessage()`, `Thread::postEmail()`
y `ThreadEntry::create()`). No cambia nada de lo que hacen, y ahora la parte
que más se rompe —de quién es el correo, a quién se responde y cómo se marca la
entrada— tiene pruebas.

Siguiente paso, por orden de riesgo:

1. `Schedule::buildFields()` (175) y `Dept::update()` (159): separar la
   validación de la escritura.
2. Los archivos grandes: `class.queue.php` tiene cuatro clases juntas; mover
   `QueueColumnCondition*` a su propio archivo es mecánico y comprobable con
   `class_exists()`.

- Riesgo: bajo en el primer paso (código nuevo + pruebas, sin cambiar firmas).
- Éxito: ninguna función de la aplicación por encima de 120 líneas y ningún
  archivo por encima de 3.000 líneas.

**Organización de archivos.** Un archivo por familia de clases, con el nombre
del archivo diciendo qué hay dentro y las dependencias declaradas arriba
(`include_once`), no supuestas por el orden de carga de `bootstrap.php`. El
mapa se consulta con `make classes`; los candidatos que quedan son
`class.forms.php` (64 clases) y `class.thread.php` (35).

### Frente 3 — Terminar la migración al ORM

- Qué hacer: los 183 `db_query()` restantes son SQL escrito a mano que evita
  el ORM (y con él, el escapado y la instrumentación). Migrarlos por áreas,
  empezando por los `SELECT` simples.
- Por qué: cada `db_query` con concatenación es una superficie de inyección
  potencial y un punto que no se beneficia de las mejoras del ORM.
- Riesgo: medio-alto. Requiere revisar cada consulta y sus índices.
- Éxito: `db_query` por debajo de 100 y sin ninguna concatenación de datos de
  la petición (el escáner `tools/audit` ya vigila lo segundo).

### Frente 4 — Entrada única y tipada

- Qué hacer: no tocar los 1.269 accesos uno a uno. Añadir una fachada —
  `Http::get()`, `Http::post()`, `Http::postInt()`— y **usarla en el código
  nuevo**; migrar sólo los archivos que se vayan tocando por otro motivo
  (regla del boy scout).
- Por qué: estandariza la validación sin una migración masiva de riesgo alto.
- Riesgo: bajo si se aplica sólo a código nuevo.
- Éxito: ningún archivo nuevo usa `$_POST[...]` directamente; el contador
  total baja de forma sostenida sin subir nunca.

### Frente 5 — Sacar el JavaScript de las plantillas (CSP)

- Qué hacer: **los `onclick=` ya están a cero** (`make inline-audit`, diez
  tandas con `js/actions.js`). Quedan los bloques `<script>` dentro de las
  plantillas: necesitan `nonce` o pasar a archivos para poder quitar
  `'unsafe-inline'` (y `'unsafe-eval'` en el panel).
- Por qué: es la única forma de endurecer de verdad la CSP (ver OST-102 en
  `SECURITY_AUDIT.md`). Mientras exista `unsafe-inline`, una inyección de
  HTML ejecuta código.
- Riesgo: medio, pero **incremental**: cada pantalla migrada se puede probar
  sola.
- Éxito: `script-src` sin `'unsafe-inline'` en el portal del cliente (hoy
  `onclick=` ya está a cero; falta el nonce de los `<script>`).

### Frente 6 — Partir los archivos de más de 2.000 líneas 🔄 Empezado

- Qué hacer: `class.forms.php` (6.220 líneas, **64 clases**) y
  `class.ticket.php` en rasgos (`trait`) o clases colaboradoras, sin cambiar
  nombres públicos. Aquí sí conviene ir con calma: es donde más se rompe.
- Lo hecho: `class.queue.php` bajó de 3.232 a **2.341** y `class.thread.php`
  de 3.405 a **2.318**, separando sus columnas y filtros y su historial de
  cambios y cuerpos de entrada. El patrón es siempre el mismo:
  mover clases enteras a un archivo con nombre propio, incluir ese archivo
  desde el original y comprobar con `make classes` que no falta ni sobra
  ninguna.
- Lo hecho también: `class.forms.php` bajó de 6.220 a **4.516** líneas al
  separar los widgets de campo y los formularios de asignación.
- Siguiente, por relación riesgo/beneficio: `class.ticket.php`, ya el mayor
  (4.844 líneas). Su parte de correo y notificaciones (`postMessage` y
  alrededores) es la que más crece con cada versión.
- Por qué: 6.000 líneas en un archivo hacen que cada búsqueda cueste y que
  los conflictos de fusión sean inevitables.
- Riesgo: alto. Sólo después de los frentes 1 y 2.
- Éxito: ningún archivo por encima de 2.000 líneas. De momento ninguno pasa
  de 5.000 (el mayor, `class.ticket.php`, tiene 4.844), y los que estaban por
  encima de 3.200 se han reducido un 30 %.

---

## 4. Orden recomendado

1. **Frente 1** (pruebas) — ✅ hecho.
2. **Frente 2** (funciones largas → clases pequeñas con pruebas) — en curso.
3. **Frente 4** (fachada de entrada) — barato y frena el sangrado.
4. **Frente 5** (JS inline) — ✅ los `onclick` están a cero; quedan los
   `<script>`.
5. **Frente 3** (SQL a mano) — por áreas, con pruebas.
6. **Frente 6** (archivos gigantes) — empezado por `class.queue.php`;
   sigue `class.forms.php`, con calma.

## 5. Lo que NO conviene hacer

- **Reescribir osTicket.** El coste de reimplementar tickets, formularios
  dinámicos, SLA, correo y colas supera cualquier ganancia de estilo.
- **Cambiar la convención de nombres** (`class.ticket.php`, `scp/`) para
  "modernizar". Rompe enlaces, plugins y documentación sin mejorar nada.
- **Un refactor masivo de `$_POST`.** 1.269 sitios, con lógica de negocio
  mezclada: es el cambio con peor relación riesgo/beneficio.
- **Tocar `eval()` del ORM** sin pruebas. Los 13 usos sostienen la generación
  dinámica de clases; sustituirlos exige un rediseño, no un reemplazo.

## 6. Cómo se mide el progreso

```bash
make metrics        # radiografía completa
make metrics-json > antes.json
# ... refactor ...
make metrics-json > despues.json
diff <(python3 -m json.tool antes.json) <(python3 -m json.tool despues.json)
```

Un refactor está justificado si **baja** alguna señal sin que suba ninguna
otra, y si `make ci` sigue en verde.
