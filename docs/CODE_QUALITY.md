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

> **La medida se ha corregido dos veces, y las dos importan.**
>
> 1. Hasta el 2026-10-23 se contaban `{` y `}` sobre el texto: una llave
>    dentro de una cadena (`'Hola {nombre}'`), de un heredoc o de una
>    expresión regular descuadraba la cuenta. Por eso `Queue::getCriteria()`
>    «tenía» 1.414 líneas y `class.file.php` cuatro funciones de 730.
> 2. Al pasar a `token_get_all` quedó otro error: se seguía una sola función
>    a la vez. Si un método contiene un cierre (`function () {...}`), el
>    cierre ocupaba el sitio del método y **el método desaparecía de la
>    medida**; lo mismo con las llaves de interpolación (`"{$var}"`) y con
>    las funciones de interfaz sin cuerpo. Resultado: `Ticket::create()`,
>    con 536 líneas, no aparecía en ninguna lista, y faltaban 114 funciones
>    del total.
>
> Ahora la medida vive en `tools/quality/measure.php`, lleva una pila de
> funciones abiertas y tiene 13 pruebas propias (`tests/CodeMeasureTest.php`)
> para que no vuelva a pasar. Las cifras de abajo son las buenas.

---

## 2. Radiografía (medida el 2026-10-23)

| Métrica | Valor |
|---|---|
| Archivos PHP propios | 517 |
| Líneas | 134.045 |
| Funciones | 6.076 |
| Clases, interfaces y rasgos | 672 en 199 archivos |
| Tamaño medio de función | 12 líneas |
| Archivo más grande | `include/class.ticket.php` — 4.809 líneas |
| Función más larga del código propio | `Ticket::create()` — 500 líneas (eran 536) |
| Función más larga de una herramienta | `run()` de `include/cli/modules/file.php` — 408 líneas |

**No hay ninguna función de 1.400 líneas**, pero sí una de **500**:
`Ticket::create()`. La media son 12 líneas; el problema son los valores
atípicos, y ahora se ven:

| Función | Líneas | Archivo |
|---|---|---|
| `Ticket::create()` | 500 | `include/class.ticket.php` |
| `massProcess()` | 321 | `include/ajax.tickets.php` |
| `massProcess()` | 285 | `include/ajax.tasks.php` |
| `Mailer::send()` | 270 | `include/class.mailer.php` |
| `Ticket::postMessage()` | 178 | `include/class.ticket.php` |
| `Schedule::buildFields()` | 175 | `include/class.schedule.php` |

| Señal | Ocurrencias |
|---|---|
| SQL a mano (`db_query`) | 183 |
| `db_input()` (escape manual) | 164 |
| Accesos directos a `$_POST`/`$_REQUEST`/`$_GET` | 1.249 (frente 4) |
| `global $...` | 560 |
| `eval()` | 15 |
| `onclick=`/`onchange=` en HTML | 0 (eran 127; ver frente 5) |
| `<script>` embebido en plantillas | 161 |

La media de 12 líneas por función es **buena**. Los valores atípicos son de
dos tipos: siete archivos por encima de 2.000 líneas, y las funciones de la
lista de arriba, que en su mayoría son de herramientas propias
(`tools/`, `setup/`, `cli/`) o de librerías copiadas (`htmLawed`, `JSON.php`,
`Spyc`), no de la aplicación.

Los archivos que más pesan:

| Archivo | Líneas | Clases | Qué contiene | Antes |
|---|---|---|---|---|
| `include/class.ticket.php` | 4.809 | 2 | Ticket: creación, edición, correo, SLA, adjuntos | igual |
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
  (el error aparece sólo al ejecutar esa rama). Se busca en el directorio
  del archivo y en sus superiores, porque osTicket incluye `staff.inc.php`
  desde `scp/apps/` contando con que el script de entrada vive en `scp/`.

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
| `tests/FormatTest.php` | 32 casos: tamaños, teléfonos, `truncate`, `mask`, `slugify`, escapado de HTML, cadenas de consulta… |
| `tests/ValidatorTest.php` | 25 casos: correo (incluido el modo lista y `localhost`), IP y redes CIDR (v4 y v6), URL, teléfono, usuario, fórmula y el validador de formularios |
| `tests/TicketPostTest.php` | 14 casos: origen e IP del mensaje, destinatarios sin el autor, rebotes |
| `tests/ThreadEmailTest.php` | 19 casos: qué correo se acepta, mensaje del cliente o nota del agente |
| `tests/ThreadEntryInfoTest.php` | 22 casos: título, firma, respuesta a todos, adjuntos incrustados |
| `tests/ClassMapTest.php` | 20 casos: lectura de clases e includes del propio código |
| `tests/CodeMeasureTest.php` | 13 casos: el medidor de `make metrics` (cierres, heredoc, interpolación, HTML) |
| `tests/TicketCreateTest.php` | 18 casos: campos por origen, validación por quién abre, fecha límite |
| `tests/MassActionTest.php` | 37 casos: tabla de acciones de tickets y tareas, verbos, identificadores, mensajes, script del diálogo |
| `tests/HttpInputTest.php` | 19 casos: lectura con valor por defecto, cadenas vacías, listas y conversión a entero sin avisos |
| `tests/InputAuditTest.php` | 19 casos: qué cuenta y qué no, archivos saltados, comparación con la línea base |
| `tests/MailerMessageTest.php` | 24 casos: asunto en una línea, cabeceras por tipo de aviso, envoltorio del identificador, cuerpo de texto, dominio de las imágenes |
| `phpunit.xml.dist` | Configuración de PHPUnit para quien tenga `vendor/` |

```bash
make unit          # 285 pruebas en ~0,3 s
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

Al escribirlas aparecieron cinco defectos reales, corregidos sin cambiar el
comportamiento observable:

1. `Format::filesize2bytes()` desplazaba la cadena en vez del entero y PHP 8
   emitía «A non-numeric value encountered» en cada llamada.
2. `Validator::validate()` leía `$this->input[$k]` sin comprobar que existiera:
   un campo opcional ausente llenaba el log de «Undefined array key».
3. `Validator::is_url()` hacía lo mismo con `$info['host']` en las URLs
   relativas.
4. `Format::sanitize()`, `striptags()`, `htmldecode()`, `strip_emoticons()` y
   `localizeInlineImages()` pasaban el texto tal cual a `preg_replace()`,
   `strip_tags()` y `htmlspecialchars_decode()`. Con un texto nulo, PHP 8.1
   escribe en el log una deprecación por llamada (y PHP 9 lo convierte en
   error). Ahora un texto nulo es un texto vacío.
5. El filtro `transcode_filter` de `class.charset.php` declaraba
   `filter()`/`onCreate()` sin el tipo de retorno que exige
   `php_user_filter` desde PHP 8.0: dos avisos **en cada arranque** de la
   aplicación, no sólo en las pruebas.
6. `Format::html_balance()` hacía `$doc->preserveWhitespace = false;`. La
   propiedad de `DOMDocument` se llama `preserveWhiteSpace` (con S
   mayúscula), así que esa línea creaba una propiedad dinámica: **nunca hizo
   nada** y desde PHP 8.2 PHP avisa «Creation of dynamic property
   DOMDocument::$preserveWhitespace is deprecated» en cada limpieza de HTML
   (es decir, en cada mensaje guardado). Se quita la línea, que es lo único
   que mantiene el comportamiento: activar de verdad la propiedad elimina
   los nodos de sólo espacios y, con ellos, el espacio entre etiquetas en
   línea.

El aviso que originó el punto 4 salió en el log del contenedor, no en la
salida de `make unit`. Por eso el ejecutor de pruebas ya no lo permite pasar:

- `tests/run.php` instala un manejador de errores alrededor de cada prueba y
  **cuenta cualquier aviso de PHP como fallo**, con el mensaje y la línea.
- `phpunit.xml.dist` añade `failOnDeprecation` y `failOnNotice`.

Así, un `null` que hoy sólo ensucia el log rompe la integración antes de
llegar a producción. Lo silenciado con `@` se sigue respetando.

**El entorno de referencia es el contenedor.** El ejecutor del equipo de
desarrollo es php-wasm (PHP 8.3 **sin** `dom`, `mbstring`, `intl` ni
`mysqli`); la imagen de `docker/php` es PHP 8.4.27 con `dom`. Por eso hay
avisos —el de `preserveWhitespace` es el caso— que sólo aparecen en
`make docker-test`. `tests/run.php` imprime ahora la versión de PHP y las
extensiones cargadas al empezar, y las pruebas que necesitan `dom` se marcan
como omitidas en vez de pasar en falso:

```
PHP 8.3.11 — dom: no, mbstring: no, intl: no, mysqli: no
...
285 pruebas correctas, 0 fallidas, 4 omitidas
```

`make ci` incluye `unit` desde esta versión: si una prueba falla, no hay
integración.

### Frente 2 — Reducir las funciones y archivos que concentran la dificultad 🔄 En curso

El plan original hablaba de partir `Queue::getCriteria()` (1.414 líneas). Esa
cifra era un error de medida (§1): la función tiene 30 líneas. Con los números
buenos, el frente cambia de objetivo:

| Objetivo | Antes | Ahora |
|---|---|---|
| Función más larga de la aplicación | 536 líneas | **500** |
| `Ticket::create()` | 536 líneas | **500** (`TicketCreate`) |
| `Ticket::postMessage()` | 190 líneas | **178** (`TicketPost`) |
| `Thread::postEmail()` | 181 líneas | **149** (`ThreadEmailInfo`) |
| `ThreadEntry::create()` | 185 líneas | **158** (`ThreadEntryInfo`) |
| `massProcess()` de tickets | 377 líneas | **321** (`MassAction`) |
| `massProcess()` de tareas | 330 líneas | **285** (`MassAction`) |
| `Mailer::send()` | 297 líneas | **270** (`MailerMessage`) |
| `QuerySet::compileSelect()` | 222 líneas | **100** (`SelectSql`) |
| Funciones de la aplicación por encima de 150 líneas | 10 | 10 |
| Archivos por encima de 2.000 líneas | 7 | 7 (ninguno pasa de 4.809) |

**Criterio:** sacar de las funciones largas los tramos que no tocan la base de
datos, a una clase pequeña con nombre propio y con pruebas. Los tramos que sí
tocan la base de datos se quedan donde están hasta que haya una prueba de
integración con base de datos.

Pasos hechos: **160 casos nuevos** sin base de datos.

| Pieza | Qué contiene | Pruebas |
|---|---|---|
| `include/class.ticket.post.php` | `TicketPost::normalizeVars()` (origen e IP del mensaje), `withoutPoster()` (quita al autor de los destinatarios), `shouldAutoRespond()` (rebotes y respuestas automáticas) | `tests/TicketPostTest.php`, 14 casos |
| `include/class.thread.email.php` | `ThreadEmailInfo::accepts()` (ticket cerrado que no se reabre), `varsFromMail()` (traducción del correo a variables de la entrada), `entryTypeForUserClass()` (mensaje del cliente o nota del agente), `poster()` y `receivedFrom()` | `tests/ThreadEmailTest.php`, 19 casos |
| `include/class.thread.entry.php` | `ThreadEntryInfo::title()` y `poster()` (qué se guarda), `replyFlags()` (respuesta a todos / al usuario), `bodyFlags()` (HTML equilibrado y nota de sistema), `dropStrippedImages()` y `markInline()` (adjuntos incrustados) | `tests/ThreadEntryInfoTest.php`, 22 casos |
| `include/class.ticket.create.php` | `TicketCreate::requiredFields()` (qué exige cada origen: web, staff, api, email), `fieldFilter()` (qué se valida según quién abre), `dueDateError()` y `source()` | `tests/TicketCreateTest.php`, 18 casos |
| `include/class.massaction.php` | `MassAction::actions()` (la tabla de acciones de tickets y de tareas, con sus verbos), `selection()`/`selectionError()` (cuántos se han marcado y el aviso si no hay ninguno), `ids()`/`splitIds()` (identificadores de `GET`, `POST` o `REQUEST`, separados por comas o en lista), `mergeAction()` (combinar o enlazar según la ruta), `moveToFront()` (el padre primero), `statusFor()` (reabrir/cerrar), `successMessage()`/`partialMessage()` y `selectedScript()` (el script que copia las casillas marcadas al diálogo) | `tests/MassActionTest.php`, 37 casos |
| `include/class.mailer.message.php` | `MailerMessage::cleanSubject()` (asunto en una línea, sin saltos), `extraHeaders()` (boletín, respuesta automática y aviso, con el orden de siempre), `wrapMid()` (el `div` invisible con el identificador del mensaje), `textBody()` (HTML a texto y `Ref-Mid`) y `contentIdDomain()` (dominio de las imágenes incrustadas) | `tests/MailerMessageTest.php`, 24 casos |
| `include/class.orm.select.php` | `SelectSql::fields()`: qué columnas pide un SELECT (`raíz.*`, `values()`, campos diferidos, relaciones con JOIN, anotaciones, `extra['select']` y `GROUP BY`) | `tests/QuerySqlTest.php`, 26 casos |

Las siete clases ya están en uso (`Ticket::postMessage()`,
`Thread::postEmail()`, `ThreadEntry::create()`, `Ticket::create()`, los dos
`massProcess()`, `Mailer::send()` y `compileSelect()`). No cambia nada de lo
que hacen, y ahora
la parte que más se rompe —de quién es el correo, a quién se responde, cómo se
marca la entrada, qué se exige al abrir un ticket, qué se hace en lote con los
seleccionados, con qué cabeceras sale un aviso y qué SELECT se genera— tiene
pruebas.

`MassAction` no toca la base de datos: es la tabla de acciones (con sus verbos:
«assigned» en un ticket y «claimed» en una tarea; «reopen» en tickets y
«reopened» en tareas, diferencias que ya existían y se conservan), la lectura
de los identificadores marcados y los mensajes. El resto de `massProcess()`
—permisos, consultas y correo— se queda donde estaba.

De paso se corrigieron tres defectos reales de los despachadores:

1. `explode(',', $_POST['tids'])` reventaba si el formulario enviaba una lista
   (`tids[]`), que es justo lo que arma el diálogo. `MassAction::ids()` acepta
   las dos formas.
2. Al combinar tickets, el `new SqlField('ticket_id')` se añadía **dentro** del
   bucle que quitaba los campos, así que el identificador del padre entraba en
   la lista de campos a borrar.
3. `$expr` podía quedar sin definir, y se usaba `$ticket[0]` sin comprobar que
   el ticket existiera.

El script que copia las casillas marcadas al diálogo (`selectedScript()`) se
compara byte a byte con el que había en cada archivo: es idéntico salvo el
`form#tickets` / `form#tasks`. Así se refactoriza sin cambiar lo que ve el
agente.

`compileSelect()` compilaba la lista de columnas en el mismo método que arma
el WHERE, el ORDER BY y los UNION. Ese tramo —el que decide si se piden
`raíz.*`, unas columnas concretas o los campos de una relación— pasa a
`SelectSql::fields()`, que no toca la base de datos: recibe la consulta, el
compilador y el alias, y devuelve texto. La prueba compara el SQL completo
(con sus `:1`, `:2` y sus parámetros) para 26 consultas: simple, filtros,
orden, límite, bloqueos, `values()`, diferidos, `distinct`, anotaciones,
columnas extra y JOIN.

Esa prueba destapó cuatro defectos de PHP 8 que el ORM arrastraba y que sólo
se veían al compilar según qué consulta:

1. `SqlCompiler::$annotations` y `CompiledExpression::$type` no estaban
   declaradas: **cada consulta** creaba una propiedad dinámica (deprecación de
   PHP 8.2 en el log).
2. `SqlField::$field`, igual, en cada ordenación por función.
3. Los operadores `contains`, `in`, `range`… se declaraban como
   `array('self', '__contains')`. PHP 8.2 deprecó `'self'` en callables; ahora
   se resuelven a `[$this, '__contains']`.
4. `QuerySet::extra()` leía `$this->extra[$section]` sin comprobar que
   existiera: «Undefined array key» la primera vez que se usaba cada sección.

En `Mailer::send()` lo que queda es lo que no se puede probar sin enviar:
cuentas SMTP, envío por Sendmail y la traducción de destinatarios a
`to`/`cc`/`bcc` según su clase. Las cabeceras, el asunto y los cuerpos sí se
prueban, y el `div` del identificador se comparó carácter a carácter con el
que generaba el código anterior.

`Ticket::create()` es la función más larga de la aplicación (500 líneas,
eran 536). Bajó con esas piezas; lo que queda es un flujo con base de datos
de punta a punta (buscar tema, departamento, correo, crear el usuario, guardar
el formulario, los colaboradores y el mensaje) y no se puede trocear sin
pruebas de integración.

Siguiente paso, por orden de riesgo:

1. `Ticket::create()` (500): lo que queda no se puede tocar con seguridad
   hasta tener pruebas con base de datos. Ahí el siguiente paso del plan deja
   de ser mover código y pasa a ser **montar esa prueba de integración**
   (contenedor con MySQL ya disponible: `make docker-up`). Queda aplazado por
   decisión del equipo; el frente 4 va antes.

- Riesgo: bajo en el primer paso (código nuevo + pruebas, sin cambiar firmas).
- Éxito: ninguna función de la aplicación por encima de 120 líneas y ningún
  archivo por encima de 3.000 líneas.

**Organización de archivos.** Un archivo por familia de clases, con el nombre
del archivo diciendo qué hay dentro y las dependencias declaradas arriba
(`include_once`), no supuestas por el orden de carga de `bootstrap.php`. El
mapa se consulta con `make classes`; los candidatos que quedan son
`class.forms.php` (37 clases) y `class.thread.php` (11).

### Frente 3 — Terminar la migración al ORM

- Qué hacer: los 183 `db_query()` restantes son SQL escrito a mano que evita
  el ORM (y con él, el escapado y la instrumentación). Migrarlos por áreas,
  empezando por los `SELECT` simples.
- Por qué: cada `db_query` con concatenación es una superficie de inyección
  potencial y un punto que no se beneficia de las mejoras del ORM.
- Riesgo: medio-alto. Requiere revisar cada consulta y sus índices.
- Éxito: `db_query` por debajo de 100 y sin ninguna concatenación de datos de
  la petición (el escáner `tools/audit` ya vigila lo segundo).

### Frente 4 — Entrada única y tipada 🔄 En curso (la regla ya se cumple)

- Qué hacer: no tocar los 1.243 accesos uno a uno. Añadir una fachada —
  `Http::get()`, `Http::post()`, `Http::postInt()`— y **usarla en el código
  nuevo**; migrar sólo los archivos que se vayan tocando por otro motivo
  (regla del boy scout).
- Por qué: estandariza la validación sin una migración masiva de riesgo alto.
- Riesgo: bajo si se aplica sólo a código nuevo.
- Éxito: ningún archivo nuevo usa `$_POST[...]` directamente; el contador
  total baja de forma sostenida sin subir nunca.

**Lo hecho:**

| Pieza | Qué hace | Pruebas |
|---|---|---|
| `Http::get()`, `post()`, `request()` | Leen la variable y devuelven el valor por defecto si la clave no está. Un valor vacío sigue siendo un valor; una lista se devuelve tal cual | `tests/HttpInputTest.php`, 19 casos |
| `Http::getInt()`, `postInt()`, `requestInt()` | Igual, pero como entero. Una lista devuelve el valor por defecto en vez de un aviso de PHP | ídem |
| `tools/quality/input-audit.php` | Cuenta los accesos directos por archivo y los compara con la línea base (`tools/quality/input-baseline.json`) | `tests/InputAuditTest.php`, 19 casos |
| `make input-audit` | `--check`: falla si aparece un archivo nuevo con acceso directo o si alguno sube. Ya está dentro de `make ci` | — |

La regla no se queda en un documento: **el contador no puede subir**. La
línea base tiene 141 archivos con 1.243 accesos; `include/class.http.php`, que
es la fachada, queda fuera del recuento porque leer esas variables es su
trabajo. `make metrics` sí la cuenta, por eso da 1.249.

Primera pasada de migración (los dos archivos que el frente 2 ya había
tocado):

- `include/ajax.tasks.php`: 13 → 4 accesos. La búsqueda (`limit`, `q`), el
  cambio de estado y el borrado en lote leen ya por la fachada.
- `include/ajax.tickets.php`: 53 → 49. Asignar, transferir y borrar en lote.

Queda pendiente migrar el resto **cuando se toque cada archivo**, no antes.

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
  de 5.000 (el mayor, `class.ticket.php`, tiene 4.809), y los que estaban por
  encima de 3.200 se han reducido un 30 %.

---

## 4. Orden recomendado

1. **Frente 1** (pruebas) — ✅ hecho.
2. **Frente 2** (funciones largas → clases pequeñas con pruebas) — en curso.
3. **Frente 4** (fachada de entrada) — ✅ la fachada y la regla están; la
   migración va archivo a archivo según se toquen.
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
- **Un refactor masivo de `$_POST`.** 1.243 sitios, con lógica de negocio
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
