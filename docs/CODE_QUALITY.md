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
| Archivos PHP propios | 518 |
| Líneas | 134.669 |
| Funciones | 6.072 |
| Clases, interfaces y rasgos | 675 en 201 archivos |
| Tamaño medio de función | 12,1 líneas |
| Archivo más grande | `include/class.ticket.php` — 4.532 líneas |
| Función más larga del código propio | `run()` de `include/cli/modules/file.php` — 408 líneas |
| Función más larga de la aplicación | `massProcess()` de `include/ajax.tickets.php` — **321** (eran 377) |

**No hay ninguna función de 1.400 líneas** y la más larga de la aplicación
baja de 400: `create()` pasó de 536 a **219** al partirse por fases
(frente 6). La media son 12 líneas; el problema son los valores atípicos, y
ahora se ven (los tres primeros son de herramientas propias o de la CLI, no de
la aplicación):

| Función | Líneas | Archivo |
|---|---|---|
| `run()` (importador por CLI) | 408 | `include/cli/modules/file.php` |
| `massProcess()` | 321 | `include/ajax.tickets.php` |
| `massProcess()` | 287 | `include/ajax.tasks.php` |
| `Mailer::send()` | 270 | `include/class.mailer.php` |
| `Ticket::create()` | 219 | `include/class.ticket.php` (eran 536) |
| `_build()` (módulo de idiomas) | 190 | `include/cli/modules/i18n.php` |
| `Ticket::postMessage()` | 178 | `include/class.ticket.php` |
| `Schedule::buildFields()` | 175 | `include/class.schedule.php` |

| Señal | Ocurrencias |
|---|---|
| SQL a mano (`db_query`) | 104 reales (38 en la aplicación) |
| `db_input()` (escape manual) | 164 |
| Accesos directos a `$_POST`/`$_REQUEST`/`$_GET` | 1.248 (frente 4) |
| `global $...` | 560 |
| `eval()` | 15 |
| `onclick=`/`onchange=` en HTML | 0 (eran 127; ver frente 5) |
| `<script>` en línea en plantillas | 98 (todos con `nonce`; eran 161) |

La media de 12 líneas por función es **buena**. Los valores atípicos son de
dos tipos: siete archivos por encima de 2.000 líneas, y las funciones de la
lista de arriba, que en su mayoría son de herramientas propias
(`tools/`, `setup/`, `cli/`) o de librerías copiadas (`htmLawed`, `JSON.php`,
`Spyc`), no de la aplicación.

Los archivos que más pesan:

| Archivo | Líneas | Clases | Qué contiene | Antes |
|---|---|---|---|---|
| `include/class.ticket.php` | 4.531 | 2 | Ticket: creación, edición, correo, SLA, adjuntos | 4.809, y `create()` de 536 a 219 líneas |
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
make unit          # 368 pruebas en ~0,3 s
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
368 pruebas correctas, 0 fallidas, 1 omitida
```

`make ci` incluye `unit` desde esta versión: si una prueba falla, no hay
integración.

### Frente 2 — Reducir las funciones y archivos que concentran la dificultad 🔄 En curso

**Dónde está** (medido el 2026-10-26 con `make metrics`): la primera tanda está
hecha y en uso —siete piezas puras sacadas de las funciones más largas, con
160 pruebas nuevas y seis defectos reales corregidos—, y ninguna de esas
funciones ha vuelto a crecer. Desde entonces `Ticket::create()` se partió
además en nueve fases (frente 6), así que la función más larga de la
aplicación ya no es un flujo de 500 líneas.

El criterio de éxito **no se cumple todavía**: quedan **22 funciones de la
aplicación por encima de 120 líneas** y **tres archivos por encima de 3.000**
(`class.ticket.php`, `class.forms.php` y `class.orm.php`).

| Objetivo | Antes | Ahora |
|---|---|---|
| Función más larga de la aplicación | 536 líneas | **321** (`massProcess()` de tickets) |
| `massProcess()` de tickets | 377 líneas | **321** (`MassAction`) |
| `massProcess()` de tareas | 330 líneas | **287** (`MassAction`) |
| `Mailer::send()` | 297 líneas | **270** (`MailerMessage`) |
| `Ticket::create()` | 536 líneas | **219** (nueve fases en `TicketCreate`) |
| `Ticket::postMessage()` | 190 líneas | **178** (`TicketPost`) |
| `Thread::postEmail()` | 181 líneas | **149** (`ThreadEmailInfo`) |
| `ThreadEntry::create()` | 185 líneas | **158** (`ThreadEntryInfo`) |
| `QuerySet::compileSelect()` | 222 líneas | **100** (`SelectSql`; `fields()` 148) |
| `Schedule::buildFields()` | 175 líneas | **5** (`ScheduleEntryFormFields`) |
| `Validator::validate()` | 129 líneas | **52** (`ValidatorRule`) |
| Funciones de la aplicación por encima de 120 líneas | — | **22** |
| Funciones de la aplicación por encima de 150 líneas | 10 | **9** |
| Archivos por encima de 3.000 líneas | 3 | **3** |

**Lo que queda, y por qué no es sólo «seguir partiendo»:**

1. **El banco de integración no cubre el flujo de tickets.** Existe y está en
   verde (`tests/integration/OrmIntegrationTest.php`, 19 casos contra una base
   desechable), pero sólo prueba el ORM. Ni `Ticket::create()`, ni los dos
   `massProcess()`, ni `Mailer::send()` tienen una prueba suya. Son las cuatro
   más largas de la aplicación y las cuatro que tocan la base de datos de
   punta a punta: **sin esa prueba no se pueden partir sin riesgo**, y eso es
   exactamente lo que el frente tiene pendiente desde el principio.
   `make docker-integration` es el camino.
2. **Diecisiete funciones intermedias (121–175 líneas) que nadie ha tocado**,
   y que no estaban en el plan: `Dept::update()` (159), `Report::getTabularData()` (158), `Thread::create()`
   (158), `Queue::update()` (157), `SelectSql::fields()` (148, la pieza que se
   sacó de `compileSelect()`), `Search::IndexOldStuff()` (137),
   `Staff::update()` (137), `Ticket::update()` (133), `I18n::loadDefaultData()`
   (131), `Ticket::open()` (129), `Organization::update()` (128), `MailParse::getAttachments()` (127),
   `Thread::lookupByEmailHeaders()` (126), `Topic::update()` (126),
   `File::create()` (122) y `Ticket::setStatus()` (121). Varias son del mismo
   tipo que las que ya se partieron (armar campos, validar, componer
   mensajes); otras son `update()` de modelo, donde lo que hay es una lista de
   campos y su validación.
3. **Los tres archivos de más de 3.000 líneas** son trabajo del frente 6
   (reparto por familias de clases), no de éste, pero el criterio de éxito del
   frente 2 los incluye. `class.forms.php` es el que más se resiste: 36 clases
   y 4.516 líneas.
4. **Las herramientas del propio proyecto** también aparecen en la lista
   (`run()` de `include/cli/modules/file.php` con 408 líneas, `_build()` del
   módulo de idiomas con 190, `install()` del instalador con 302,
   `sqlaudit_main()` con 172). El sembrador de datos de demostración
   (`include/cli/modules/seed.php`) nace ya repartido en diez pasos cortos, uno
   por tipo de registro, para no engordar esa lista. No son código de la aplicación —el criterio
   habla de la aplicación, y las tres primeras son CLI— pero están a la vista
   y se parten cuando se toquen por otro motivo.

**Criterio:** sacar de las funciones largas los tramos que no tocan la base de
datos, a una clase pequeña con nombre propio y con pruebas. Los tramos que sí
tocan la base de datos se quedan donde están hasta que haya una prueba de
integración con base de datos.

Pasos hechos: **195 casos nuevos** sin base de datos.

| Pieza | Qué contiene | Pruebas |
|---|---|---|
| `include/class.ticket.post.php` | `TicketPost::normalizeVars()` (origen e IP del mensaje), `withoutPoster()` (quita al autor de los destinatarios), `shouldAutoRespond()` (rebotes y respuestas automáticas) | `tests/TicketPostTest.php`, 14 casos |
| `include/class.thread.email.php` | `ThreadEmailInfo::accepts()` (ticket cerrado que no se reabre), `varsFromMail()` (traducción del correo a variables de la entrada), `entryTypeForUserClass()` (mensaje del cliente o nota del agente), `poster()` y `receivedFrom()` | `tests/ThreadEmailTest.php`, 19 casos |
| `include/class.thread.entry.php` | `ThreadEntryInfo::title()` y `poster()` (qué se guarda), `replyFlags()` (respuesta a todos / al usuario), `bodyFlags()` (HTML equilibrado y nota de sistema), `dropStrippedImages()` y `markInline()` (adjuntos incrustados) | `tests/ThreadEntryInfoTest.php`, 22 casos |
| `include/class.ticket.create.php` | `TicketCreate::requiredFields()` (qué exige cada origen: web, staff, api, email), `fieldFilter()` (qué se valida según quién abre), `dueDateError()` y `source()` | `tests/TicketCreateTest.php`, 18 casos |
| `include/class.massaction.php` | `MassAction::actions()` (la tabla de acciones de tickets y de tareas, con sus verbos), `selection()`/`selectionError()` (cuántos se han marcado y el aviso si no hay ninguno), `ids()`/`splitIds()` (identificadores de `GET`, `POST` o `REQUEST`, separados por comas o en lista), `mergeAction()` (combinar o enlazar según la ruta), `moveToFront()` (el padre primero), `statusFor()` (reabrir/cerrar), `successMessage()`/`partialMessage()` y `selectedScript()` (el script que copia las casillas marcadas al diálogo) | `tests/MassActionTest.php`, 37 casos |
| `include/class.mailer.message.php` | `MailerMessage::cleanSubject()` (asunto en una línea, sin saltos), `extraHeaders()` (boletín, respuesta automática y aviso, con el orden de siempre), `wrapMid()` (el `div` invisible con el identificador del mensaje), `textBody()` (HTML a texto y `Ref-Mid`) y `contentIdDomain()` (dominio de las imágenes incrustadas) | `tests/MailerMessageTest.php`, 24 casos |
| `include/class.orm.select.php` | `SelectSql::fields()`: qué columnas pide un SELECT (`raíz.*`, `values()`, campos diferidos, relaciones con JOIN, anotaciones, `extra['select']` y `GROUP BY`) | `tests/QuerySqlTest.php`, 26 casos |
| `include/class.validator.rule.php` | `ValidatorRule::check()`: la tabla de tipos de la definición de un campo (`int`, `double`, `text`, `date`, `email`, `phone`, `zipcode`, `username`, `password`, `url`, `cs-domain`, `cs-url`, `ipaddr`) y qué pasa cada valor, devolviendo el mensaje de error del campo o `null`; `everyValue()` (listas de valores) y los ayudantes de correo, URL, teléfono y usuario | `tests/ValidatorRuleTest.php`, 18 casos |
| `include/class.schedule.fields.php` | `ScheduleEntryFormFields::build()`: los 13 campos del formulario de horario (nombre, fecha, todo el día, hora de inicio y fin, repetición, día de la semana, del mes y del año), sus valores por omisión y **cuándo se muestra cada uno** (`allday`, `repeats`, `monthly`, `yearly`), armados por bloques (`head()`, `weekly()`, `monthly()`, `yearly()`) | `tests/ScheduleFieldsTest.php`, 17 casos |

Las nueve clases ya están en uso (`Ticket::postMessage()`,
`Thread::postEmail()`, `ThreadEntry::create()`, `Ticket::create()`, los dos
`massProcess()`, `Mailer::send()`, `compileSelect()`, `Validator::validate()`
y `ScheduleEntryForm::buildFields()`). No cambia nada de lo
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

`Validator::validate()` mezclaba tres cosas en un bucle de 129 líneas: campos
obligatorios y vacíos, la comprobación de tipo y el armado de los errores. La
comprobación de tipo —una tabla de `switch` por cada tipo— pasa a
`ValidatorRule::check()`, que recibe un valor y devuelve el mensaje o `null`.
Lo demás se queda en `validate()`, que baja a 52 líneas. Ese traslado sacó un
defecto real: si llegaba un arreglo a un campo de tipo fecha, correo o teléfono,
`strtotime()` reventaba con un 500 (o dejaba un «Array to string conversion» en
el log); ahora es el error del campo, como cualquier otro valor inválido.

`ScheduleEntryForm::buildFields()` eran 175 líneas de definiciones de campo con
reglas cruzadas: la hora de inicio y de fin dependen de «todo el día», y el día
de la semana, el día del mes y el «in» sólo aparecen según cómo repita la
entrada. Pasa a `ScheduleEntryFormFields::build()`, que devuelve el mismo
arreglo (mismas claves, mismo orden, mismos valores por omisión y las mismas
restricciones de visibilidad) y queda en 5 líneas. Las 17 pruebas comprueban los
13 campos uno a uno: su tipo, si es obligatorio, su valor por omisión y su
condición de visibilidad. Aquí no había ningún defecto de comportamiento, pero
sí uno de robustez: `options()` leía `$options['timezone']` sin comprobar que
existiera y dejaba un «Undefined array key» en el log cada vez que el formulario
se construía sin opciones.

Esa prueba de `compileSelect()` destapó cuatro defectos de PHP 8 que el ORM
arrastraba y que sólo
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

`Ticket::create()` fue la función más larga de la aplicación (536 líneas). Bajó
a 219 con las piezas de arriba y, después, al partirse en nueve fases
(frente 6); lo que queda en `include/class.ticket.php` es el flujo con base de
datos de punta a punta (buscar tema, departamento, correo, crear el usuario,
guardar el formulario, los colaboradores y el mensaje) y no se puede seguir
troceando sin pruebas de integración.

Siguiente paso, por orden de riesgo:

1. **Fijar `Ticket::create()` con una prueba de integración**: un ticket web,
   uno de agente y uno por correo, con sus colaboradores y su mensaje. La
   función ya está partida en nueve fases (`include/class.ticket.create.php`),
   pero lo que queda en `class.ticket.php` son 219 líneas de orquestación con
   base de datos: hasta que esa prueba esté en verde, moverlas es a ciegas.
2. `massProcess()` (321 y 287) y `Mailer::send()` (270): una prueba de
   integración por rama antes de mover código. En `Mailer::send()` lo que no
   se puede probar sin enviar es justo lo que queda (cuentas SMTP, sendmail y
   el reparto `to`/`cc`/`bcc`); las cabeceras y los cuerpos ya están en
   `tests/MailerMessageTest.php`.
3. Las funciones de 121 a 175 líneas: por la misma vía, pero son muchas y
   ninguna es un riesgo para producción, así que van detrás.

- Riesgo: bajo en el primer paso (código nuevo + pruebas, sin cambiar firmas).
- Éxito: ninguna función de la aplicación por encima de 120 líneas y ningún
  archivo por encima de 3.000 líneas.

#### Prueba de integración (lo que desbloquea el frente)

El banco vive en `tests/integration/` y su infraestructura en `tests/lib/`:

| Pieza | Qué hace |
|---|---|
| `tests/lib/Database.php` | Crea `<base>_test` (nunca la de la instalación), le carga `setup/inc/streams/core/install-mysql.sql` con el prefijo configurado y expone `query()`/`value()`/`rows()`. Si no puede conectar, explica por qué |
| `tests/lib/IntegrationTestCase.php` | Base de esas pruebas: **omite con motivo** si no hay base y **falla** si se pidió `--require-db` (un verde silencioso sería peor que un error) |
| `tests/integration/OrmIntegrationTest.php` | 19 casos: guardar y releer, valores vacíos y nulos, vaciar y borrar campos, `count()` contra el `COUNT(*)` de siempre, `IN`, `exclude`, `Q::any` con grupos anidados, `Q::not`, `contains`, orden y límite, `update()` y `delete()` en bloque con sus filas afectadas, `NOW()` de la base y que el esquema del instalador se cargue entero |
| `tests/OrmDirtyTest.php` | 10 casos sin base: qué cuenta como campo cambiado (una cadena vacía y un cero sí; un `'5'` de la base frente a un `5` asignado, no) y que el ORM tenga sus dependencias cargadas (`Signal`, `Misc`) |
| `tests/DatabaseHelperTest.php` | 14 casos sin base: cómo se parte un volcado (punto y coma dentro de cadenas, comillas escapadas, comentarios `--` y `#`) y que el nombre de la base de pruebas no pueda ser el de la instalación |
| `tests/OrmPropertiesTest.php` | 3 casos sin base: recorre `class.orm.php`, `class.orm.select.php` y `class.util.php` y comprueba con `property_exists()` que cada `$this->propiedad = …` esté declarada. Detecta la propiedad **y la línea** |
| `make integration` | `tests/run.php --integration --require-db`, o PHPUnit (`--testsuite integration`) si está instalado |
| `make docker-integration` | Levanta `db` y ejecuta lo anterior dentro del contenedor `tools` |

El separador de sentencias se prueba contra el archivo real del instalador:
son **133 sentencias** y todas empiezan por `CREATE`, `DROP`, `ALTER`,
`INSERT`, `REPLACE`, `SET`, `UPDATE` o `DELETE`. Si alguien añade una tabla y
el separador se come la sentencia, `make unit` lo dice —sin necesidad de base
de datos—.

Las pruebas de integración quedan **fuera de `make unit` y de `make ci`** a
propósito: `ci` tiene que seguir corriendo en cualquier sitio sin Docker.

**La primera ejecución encontró tres cosas**, la segunda una cuarta y las
tandas siguientes dos más: es exactamente para lo que está.

1. **El banco no cargaba `Signal` ni `Misc`.** El ORM los necesita (`save()` y
   `delete()` emiten señales; `db_connect()` siembra el generador aleatorio con
   `Misc`), pero sólo se cargan en la aplicación completa. Se añadieron al
   bootstrap de las pruebas y `class.orm.php` declara ahora su dependencia de
   `class.signal.php` en la cabecera, en vez de suponer el orden de carga.
2. **El ORM perdía los valores vacíos.** En `VerySimpleModel::set()` la
   comparación era `$old != $value`; en PHP, `null == ''` y `null == 0` son
   ciertos, así que asignar una cadena vacía o un cero a un campo que aún no
   estaba **no** marcaba el modelo como cambiado. `save()` termina sin hacer
   nada cuando no hay campos cambiados, de modo que el valor no llegaba a la
   base y la llamada devolvía `true` igualmente. Se ve en una instalación como
   un campo que se vacía en el formulario y conserva el valor anterior, y
   cuesta encontrarlo porque no hay error. Ahora se compara la nulidad aparte.
   La misma trampa hacía que poner `null` sobre una cadena vacía no la borrara.
3. **Dos avisos de PHP en `include/mysqli.php`**: leía `$__db->unbuffered_result`
   sin comprobar que existiera (en cada consulta, oculto por el nivel de
   errores de la aplicación) y `$res` podía quedarse sin asignar si `query()`
   lanzaba.
4. **Siete propiedades sin declarar en el ORM**, que PHP 8.2 marca como
   obsoletas —una deprecación por consulta—:

   | Clase | Propiedad | Por qué |
   |---|---|---|
   | `QuerySet` | `$_iterator` | el iterador ya creado; cada primera vuelta de una consulta lo creaba |
   | `ModelInstanceManager` | `$annotations` | **errata**: estaba declarada como `$annnotations`, con tres enes |
   | `MySqlExecutor` | `$types` | tipos de columna que `_setupCast()` rellena para convertir los valores |
   | `ModelMeta` | `$parent` | sólo existe cuando el modelo hereda de otro |
   | `SqlFunction` | `$func`, `$args` | los pone el constructor |
   | `SqlCode` | `$code` | ídem |
   | `ModelResultSet` | `$model`, `$queryset` | los rellena `InstrumentedList` |

   La errata es la que más escuece: el nombre correcto no existía, así que
   **cada** modelo instanciado creaba la propiedad al vuelo, y la declaración
   con la errata no servía absolutamente para nada.

   Para que no vuelva, `tests/OrmPropertiesTest.php` analiza los tres archivos
   del ORM con el tokenizador, se queda con cada escritura `$this->propiedad`
   y comprueba que esté declarada (en la clase o en un padre). No ejecuta
   nada, así que funciona sin base de datos y dice clase y línea:

   ```
   propiedades dinámicas (PHP 8.2 las marca obsoletas):
   ModelInstanceManager::$annotations (línea 1883), MySqlExecutor::$types (línea 3409)
   ```

   Se comprobó quitando las declaraciones a propósito: el fallo aparece con el
   nombre y la línea de cada una.

5. **El aviso «no puedes quitarte el único administrador» nunca se mostraba.**
   `Staff::update()` comparaba los administradores activos con `$uid`, una
   variable que sólo existe en la rama que cambia el usuario o el correo. En la
   rama que quita el permiso de administrador, `$uid` no estaba definida: PHP
   emitía un aviso, la comparación daba falso y el cambio se guardaba. Ahora la
   lista se recoge con `Staff::objects()->filter(['isadmin' => 1, 'isactive' => 1])
   ->values_flat('staff_id')` y se compara con `$this->getId()`. Mismo aviso,
   misma condición, pero ahora se comprueba.
6. **`Ticket::getUserStats()` filtraba por una columna que ya no existe.** El
   SQL unía la tabla de tickets consigo misma y filtraba por `ticket.status`,
   columna que en 1.18 es `status_id` con su estado en `ticket_status.state`.
   La consulta fallaba siempre que se pedía, y el fallo sólo se veía en el log.
   Ahora son dos conteos por el ORM (`status__state`), que además usan el
   índice de `user_id`.

**Organización de archivos.** Un archivo por familia de clases, con el nombre
del archivo diciendo qué hay dentro y las dependencias declaradas arriba
(`include_once`), no supuestas por el orden de carga de `bootstrap.php`. El
mapa se consulta con `make classes`; los candidatos que quedan son
`class.forms.php` (37 clases) y `class.thread.php` (11).

### Frente 3 — Terminar la migración al ORM ✅ Objetivo cumplido

- Qué hacer: los `db_query()` restantes son SQL escrito a mano que evita el
  ORM (y con él, el escapado y la instrumentación). Migrarlos por áreas,
  empezando por los `SELECT` simples.
- Por qué: cada `db_query` con concatenación es una superficie de inyección
  potencial y un punto que no se beneficia de las mejoras del ORM.
- Riesgo: medio-alto. Requiere revisar cada consulta y sus índices.
- Éxito: `db_query` por debajo de 100 y sin ninguna concatenación de datos de
  la petición (el escáner `tools/audit` ya vigila lo segundo).

**La cifra primero.** «183 `db_query`» era un `grep`, y un `grep` cuenta
comentarios, la definición del driver y el SQL partido en varias líneas. La
medida de verdad la da `tools/quality/sql-audit.php`, que quita los
comentarios antes de contar (`PhpSource::withoutComments()`, conservando las
líneas) y separa cuatro cosas que no son lo mismo:

| Categoría | Llamadas | Archivos | Qué es | ¿Se toca? |
|---|---|---|---|---|
| **aplicación** | **38** | 15 | El código de osTicket | es la deuda, y baja |
| instalador | 5 | 2 | `setup/` e `include/class.setup.php`: corre antes de que exista el esquema | no |
| actualizador (histórico) | 33 | 16 | `include/upgrader/streams/`: migraciones de esquemas viejos | no |
| driver de base de datos | 6 | 1 | `include/mysqli.php`: ahí `db_query()` *es* el trabajo | no |
| dobles de prueba | 22 | 1 | `tests/support/`: SQL mal escrito a propósito | no |

De las 38 de la aplicación, **25 están marcadas como no migrables** con su
motivo (`sql-audit:legacy`), así que **quedan 13 por migrar**: el objetivo
era bajar de 100 y se cumple con holgura. Quedan **6 `SELECT`, 3 DML, 2 DDL
y 2 «otras»**; **ninguna concatenación de datos de la petición** (eso lo
vigila `tools/audit` con OST-SQLI y también el propio `sql-audit`, que falla
si aparece una en el punto de llamada). Lo que resta son consultas de campos
dinámicos, el índice `FULLTEXT` de los informes, la carga de idiomas y la
importación por lotes; ninguna es una lista paginada.

**La marca, y por qué no es una salida.** Hay SQL que no puede pasar por el
ORM y no por pereza:

```php
// sql-audit:legacy OPTIMIZE TABLE es mantenimiento, no hay ORM
@db_query('OPTIMIZE TABLE '.SYSLOG_TABLE);
```

- La marca **exige motivo**: una marca vacía hace fallar `make sql-audit`, y
  el motivo se imprime en el informe para que dentro de un año se sepa por
  qué sigue ahí.
- Alcanza **tres líneas hacia arriba**, así que no se puede «marcar el
  archivo» de una vez.
- Hay un **tope de 25 marcas** (`SqlAudit::LEGACY_MAX`): si se pudiera marcar
  todo, el contador volvería a no significar nada. Ahora mismo se usa
  **entero** (25), así que el frente 3 no admite una marca más sin migrar
  otra cosa a la vez.

**Un falso positivo del escáner, corregido.** `tools/audit/scan.php` marcaba
como XSS cualquier `echo` cuyo texto contuviera `$_GET`, aunque fuera una
cadena **escapada** que sólo nombra la variable:

```php
echo "input-audit — accesos directos a \$_GET, \$_POST y \$_REQUEST\n\n";
```

Eso es texto literal (la barra invertida impide la interpolación) y hacía
fallar `make docker-test` por un mensaje de ayuda. La regla ignora ahora las
apariciones escapadas; las que sí interpolan se siguen marcando, y está
comprobado con los dos casos.

Las 25 marcas son de nueve archivos, y ninguna es una consulta de la
aplicación que se pueda escribir con el ORM (o, en el caso de `class.staff.php`,
que se pueda escribir **sin coste**: la versión ORM está al lado, en el mismo
método, y tarda 2 ms más en cada carga de sesión del agente):

| Archivo | Marcas | Por qué |
|---|---|---|
| `include/class.search.php` | 7 | índice `FULLTEXT`: `REPLACE`, borrado con `JOIN`, `CREATE TABLE`, `INSERT` por lotes, `SHOW STATUS` para elegir motor |
| `include/class.config.php` | 4 | compatibilidad con esquemas anteriores a 1.7 (`namespace`, `schema_signature`, `ostversion`) |
| `include/class.cron.php` | 3 | `OPTIMIZE TABLE` de las tablas de bloqueo, syslog y borradores |
| `include/class.export.php` | 3 | volcado: pregunta por `information_schema` y recorre tablas arbitrarias en streaming |
| `include/staff/system.inc.php` | 2 | tamaño en disco (`information_schema`, incluida la tabla de trozos de adjunto) |
| `include/class.setup.php` | 2 | sentencias del esquema al instalar, antes de que el ORM tenga algo que describir |
| `include/class.timezone.php` | 1 | `SELECT` de expresiones sin tabla: el ORM siempre añade `FROM` |
| `include/class.staff.php` | 1 | `getDepartments()`: la versión ORM (al lado, comentada) hace un `OR` sobre `path` y tarda 2 ms más, y esto corre en cada carga de sesión |
| `include/class.analytics.php` | 2 | primera respuesta: es un `MIN()` por ticket, o sea una tabla derivada, y el ORM no une vistas calculadas; SLA: `t.closed <= t.duedate` compara **dos columnas**, y el ORM compara columnas con valores |

**Lo migrado**, por áreas y con la suite en verde después de cada tanda:

| Archivo | Antes | Después | Qué se hizo |
|---|---|---|---|
| `include/class.sla.php` | 4 | 0 | `delete()` completo por el ORM |
| `include/staff/department.inc.php` | 4 | 0 | gerentes (`Staff`), correos (`Email`, dos veces) y grupos de plantillas (`EmailTemplateGroupModel`) |
| `include/staff/settings-emails.inc.php` | 3 | 0 | los dos `<select>` de correo y plantilla (el `SELECT DISTINCT` de tipos se queda) |
| `scp/departments.php` | 3 | 0 | recuento de miembros y acciones en lote, excluyendo el departamento por omisión |
| `include/class.topic.php` | 2 | 0 | desvincular tickets al borrar un tema y el orden de los temas |
| `include/staff/settings-tasks.inc.php` | 1 | 0 | prioridades y plantillas |
| `include/staff/settings-tickets.inc.php` | 1 | 0 | prioridades |
| `include/staff/settings-agents.inc.php` | 1 | 0 | plantillas de respuesta |
| `include/staff/settings-users.inc.php` | 1 | 0 | plantilla de bienvenida |
| `include/class.template.php` | 15 | 0 | `EmailTemplateModel` nuevo: carga, alta, borrado y estado de grupos y plantillas |
| `include/class.staff.php` | 3 | 1 | tokens de restablecimiento y aviso del último administrador |
| `include/class.auth.php` | 2 | 0 | `getAllowedBackends()` de agentes y de usuarios, con `Q::any` |
| `include/class.api.php` | 5 | 0 | `ApiKeyModel` nuevo: carga, alta, borrado y búsqueda por clave |
| `include/class.osticket.php` | 2 | 0 | `logSystem()` y `purgeLogs()` (con `try/catch`: registrar no puede tumbar la petición) |
| `include/class.log.php` | 1 | 0 | `SyslogModel` nuevo, para `Log::load()` |
| `include/class.banlist.php` | 1 | 0 | `isBanned()` con filtro relacional `filter__isactive` |
| `include/class.filter_action.php` | 2 | 0 | las dos `getConfigurationOptions()` |
| `scp/apikeys.php`, `scp/canned.php`, `scp/logs.php` | 2 + 2 + 1 | 0 | acciones en lote por `filter(['id__in' => …])->update()` / `->delete()` |
| `scp/banlist.php`, `scp/templates.php` | 2 + 2 | 0 | habilitar/deshabilitar en lote y el atajo `?default_for=` |
| `include/staff/email.inc.php`, `filter.inc.php`, `settings-emails.inc.php`, `template.inc.php` | 1 cada uno | 0 | los `<select>` de prioridades, correos y conjuntos de plantillas |
| `include/class.mailparse.php` | 1 | 0 | prioridad por urgencia, con `values_flat()->limit(1)` |
| `include/class.draft.php` | 1 | 0 | `cleanup()` con `Q::any` sobre `created`/`updated` |
| `include/class.canned.php` | 1 | 0 | `getFilters()` por `actions__type` + `actions__configuration__contains` |
| `include/class.faq.php` | 1 | 0 | alta de la relación FAQ–tema (el `INSERT IGNORE` pasa a `try/catch OrmException`) |
| `include/class.plugin.php` | 1 | 0 | `getPluginByName()` |
| `include/class.client.php` | 1 | 0 | tokens de restablecimiento del usuario |
| `include/class.task.php` | 1 | 0 | estadísticas del agente: cuatro conteos con `Q::any`/`Q::not` |
| `include/class.ticket.php` | 1 | 0 | `getUserStats()` |
| `include/staff/apikeys.inc.php` | 1 | 0 | lista paginada: `ApiKeyModel::objects()` + `Pagenate` |
| `include/staff/banlist.inc.php` | 1 | 0 | lista paginada con búsqueda (`val__contains`) |
| `include/staff/syslogs.inc.php` | 1 | 0 | lista paginada con rango de fechas (`FROM_UNIXTIME()` como `SqlFunction`) |
| `include/staff/templates.inc.php` | 1 | 0 | lista paginada con `COUNT()` anotado sobre el `LEFT JOIN` a departamentos |
| `include/staff/filters.inc.php` | 1 | 0 | lista con `COUNT()` de reglas y `MIN()` del tema y el departamento; el filtro del sistema se excluye con `exclude()` |
| `include/staff/cannedresponses.inc.php` | 1 | 0 | lista con `COUNT()` de adjuntos condicionado (`attachments__inline`) y `MIN()` del nombre del departamento |
| `include/staff/templates/users.tmpl.php` | 1 | 0 | lista con la cuenta (`account__status`), su `id`, el correo y los tickets, todo anotado sobre el `LEFT JOIN` |
| `include/class.analytics.php` | 14 | 2 | resumen, agentes, departamentos, origen, temas, carga y antigüedad del atraso por el ORM; series por `DATE()`/`HOUR()` con `values(['alias' => SqlFunction])` |

Ninguno sube. Lo que sigue en esos archivos es SQL de otra naturaleza (por
ejemplo, en `settings-emails.inc.php` queda el `SELECT DISTINCT` que alimenta
la lista de tipos de correo). Además, `include/class.knowledgebase.php` era un
archivo huérfano —nadie lo incluía y su clase no existía en ningún otro
sitio— y se borró.

**Una pieza nueva:** `EmailTemplateGroupModel`, un `VerySimpleModel` sobre
`EMAIL_TEMPLATE_GRP_TABLE`. `EmailTemplateGroup` es la clase que compone y
cachea las plantillas, no un modelo de fila; sin él, cada grupo de plantillas
seguía leyéndose con SQL a mano.

**Detalles que costaron y quedan documentados**, porque se repetirán:

- **`$queryset` no se puede convertir a cadena** ni ejecuta: `getQuery()`
  devuelve `sql`/`params` sin tocar la base. Las pruebas del ORM comprueban
  el SQL generado, no el resultado.
- **`order_by()` añade** al orden por defecto del modelo. Para reemplazarlo
  hay que pasar `order_by(false)` y luego el criterio.
- **`CONCAT_WS(", ", lastname, firstname)`** salta los vacíos; al pasarlo a
  PHP hay que `array_filter(..., 'strlen')` o cambia el texto.
- **`count()` ejecuta** (`compileCount()` hace `$exec->getRow()`): sin
  `mysqli` revienta, y por eso las pruebas usan `compileSelect()`.
- **Las listas paginadas no necesitan un paginador nuevo**: `Pagenate` sólo
  calcula `start`/`limit`, así que basta `->limit($pageNav->getLimit())
  ->offset($pageNav->getStart())`. Los totales salen de `count()`, y el orden
  de `order_by()` hay que **limpiarlo del alias de la tabla** (`log.created`
  → `created`), porque el ORM usa los nombres del modelo.
- **`values()` devuelve arrays asociativos** con las columnas pedidas (más las
  anotaciones), así que el bucle de la plantilla sigue leyendo `$row['campo']`.
  Para contar filas de un `JOIN` con `GROUP BY` sirve
  `annotate(['depts' => SqlAggregate::COUNT('depts__id')])`, que además añade
  el `GROUP BY` de la clave primaria.
- **`values()` acepta un arreglo con alias** (`values(['ident' => 'id'])`) y,
  desde el frente 3, **expresiones calculadas**:
  `values(['day' => SqlFunction::DATE(new SqlField('created'))])` emite
  `DATE(A1.\`created\`) AS \`day\`` y pone **la expresión** —no el alias ni la
  clave primaria— en el `GROUP BY`. Con `annotate()` al lado, el agregado se
  calcula por día o por hora en la base, sin traer las filas a PHP.
- **Agrupar por expresión exige `order_by(false)`**: el orden por defecto del
  modelo es una columna suelta y, con `ONLY_FULL_GROUP_BY` (el valor por
  omisión en MySQL 5.7+), la consulta sería inválida.

**Un filtro vacío ya no emite `WHERE`.** El error que llegó del contenedor en
producción era éste:

```
PHP Fatal error: Uncaught InconsistentModelException: Unable to prepare query:
SELECT COUNT(*) FROM (SELECT A1.log_id FROM ost_syslog A1 WHERE ) ___
```

`include/staff/syslogs.inc.php` arma el criterio del listado según los filtros
de la petición. Cuando no hay ninguno queda un arreglo vacío; `filter()` lo
guardaba igual y el compilador emitía `WHERE ` **sin condición**. MySQL
responde con un error de sintaxis, el ORM lo convierte en
`InconsistentModelException` y la página termina en un 500. `exclude()` con un
`Q` vacío hacía lo mismo, con un `NOT` colgando.

El arreglo está en el ORM y no en el llamador, porque cualquier lista puede
llegar vacía: `filter()` y `exclude()` ignoran los filtros sin condiciones
(`QuerySet::isEmptyFilter()`) y `getWhereHavingClause()` descarta las
condiciones vacías antes de unirlas, tanto en `WHERE` como en `HAVING`. Cinco
pruebas de `tests/QuerySqlTest.php` fijan el SQL resultante.

**La regla automática.** `make sql-audit` compara con
`tools/quality/sql-baseline.json` (13 llamadas en 6 archivos) y **falla** si:

- un archivo nuevo trae SQL a mano,
- cualquier archivo sube respecto de la línea base,
- una llamada concatena datos de la petición sin escapar,
- una marca `sql-audit:legacy` no lleva motivo, o se pasa del tope.

Ya está dentro de `make ci`, detrás de `input-audit`. Para consolidar una
bajada: `php tools/quality/sql-audit.php --update`.

### Frente 4 — Entrada única y tipada 🔄 En curso (la regla ya se cumple)

- Qué hacer: no tocar los 1.242 accesos uno a uno. Añadir una fachada —
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
línea base tiene 141 archivos con 1.242 accesos; `include/class.http.php`, que
es la fachada, queda fuera del recuento porque leer esas variables es su
trabajo. `make metrics` sí la cuenta, por eso da 1.248.

Primera pasada de migración (los dos archivos que el frente 2 ya había
tocado):

- `include/ajax.tasks.php`: 13 → 4 accesos. La búsqueda (`limit`, `q`), el
  cambio de estado y el borrado en lote leen ya por la fachada.
- `include/ajax.tickets.php`: 53 → 49. Asignar, transferir y borrar en lote.

Queda pendiente migrar el resto **cuando se toque cada archivo**, no antes.

### Frente 5 — Sacar el JavaScript de las plantillas (CSP) ✅ Hecho

- Qué hacer: **los `onclick=` ya están a cero** (`make inline-audit`, diez
  tandas con `js/actions.js`). Quedaban los bloques `<script>` dentro de las
  plantillas: necesitaban `nonce` o pasar a archivos para poder quitar
  `'unsafe-inline'`.
- Por qué: es la única forma de endurecer de verdad la CSP (ver OST-102 en
  `SECURITY_AUDIT.md`). Mientras exista `unsafe-inline`, una inyección de
  HTML ejecuta código.
- Éxito: `script-src` sin `'unsafe-inline'` — **cumplido** en el portal y en
  el panel.

**Lo hecho.** Se eligió el nonce antes que mover 98 bloques a archivos: no
cambia el orden de carga de ningún widget y se puede comprobar con una regla.

| Pieza | Qué hace |
|---|---|
| `Http::nonce()` | Nonce base64 (128 bits) **por sesión**, guardado en `$_SESSION['__csp_nonce']`; `resetNonce()` para pruebas |
| `Http::nonceAttr()` | Devuelve `nonce="…"` listo para pegar en la etiqueta |
| `Http::nonceScripts($html)` | Añade el atributo a los `<script>` que no lo lleven: es lo que cubre `addExtraHeader()` (y con él los complementos) y el heredoc de `MassAction::selectedScript()` |
| `Http::csp()` | `script-src 'self' 'nonce-…'`, sin `'unsafe-inline'`; `['nonce' => false]` es la salida de emergencia documentada |
| `tools/quality/csp-nonce.php` | `make csp-nonce`, dentro de `make ci`: falla si un `<script>` en línea no lleva nonce |
| `tests/CspNonceTest.php` | 13 casos: forma y estabilidad del nonce, `script-src`, `nonceScripts()` y la propia auditoría |

**Por qué el nonce es por sesión.** El CSP de un documento también gobierna
los scripts que ese documento inserta. Casi todas las pantallas de osTicket
se montan con HTML que llega por AJAX (diálogos, listas, entradas de ticket);
con un nonce por respuesta, el del trozo no coincidiría con el del documento
y el navegador lo rechazaría. jQuery 3.7 copia el `nonce` del nodo al
ejecutarlo (`DOMEval`), así que basta con que la etiqueta lo traiga.

**Cómo se comprueba sin base de datos.** La auditoría sólo mira los tramos
`T_INLINE_HTML` del archivo (con el código PHP enmascarado, para que
`nonce="<?php echo Http::nonce(); ?>"` no parta la etiqueta en trozos): un
`<script>` dentro de una cadena o de un comentario no cuenta, y uno sin
nonce se informa con archivo y línea. Resultado hoy: **98 en línea, 0 sin
nonce**.

**Lo que queda de la CSP**: `'unsafe-eval'` en el panel (editores y gráficas
antiguas) y `'unsafe-inline'` en `style-src` (cientos de atributos `style=`
que no ejecutan código).

### Frente 6 — Partir los archivos de más de 2.000 líneas 🔄 En curso

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
- **`Ticket::create()`, de 536 a 219 líneas.** No se movió código sin más:
  se partió por fases, cada una en `TicketCreate` y con nombre propio
  (`requestErrors`, `topicForms`, `formErrors`, `routing`, `saveForms`,
  `addCollaborators`, `linkOriginThread`, `applyStatusAndAssignment`,
  `notify`). `create()` queda como el guion que las llama en orden, y las
  cuatro primeras tienen prueba unitaria.
  - De paso aparecieron **dos avisos de PHP** que el log recibía en cada alta
    de ticket: `Validator` leía `$field['min']` y `$errors['err']` sin
    comprobar que existieran. Ahora hay valores por omisión en el bucle de
    campos y `isset()` en el resumen; reproducirlos es parte de
    `tests/TicketCreateTest.php`.
  - También se borró un `$id = 0;` que no se usaba y un `$dept` que quedó sin
    consumidor al mover la fase de avisos.
- Siguiente, por relación riesgo/beneficio: `Ticket::postMessage()` y los dos
  `massProcess()` de `ajax.tickets.php` y `ajax.tasks.php`, que son ya las
  funciones más largas de la aplicación.
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
4. **Frente 5** (JS inline) — ✅ `onclick` a cero desde hace varias tandas y
   `<script>` con `nonce`: la CSP ya no anuncia `'unsafe-inline'`.
5. **Frente 3** (SQL a mano) — ✅ por áreas y con pruebas: 30 → 13 llamadas.
6. **Frente 6** (archivos gigantes y funciones de 500 líneas) — `class.queue.php`,
   `class.thread.php`, `class.forms.php` y `create()`; siguen los dos
   `massProcess()` y `postMessage()`.

## 5. Lo que NO conviene hacer

- **Reescribir osTicket.** El coste de reimplementar tickets, formularios
  dinámicos, SLA, correo y colas supera cualquier ganancia de estilo.
- **Cambiar la convención de nombres** (`class.ticket.php`, `scp/`) para
  "modernizar". Rompe enlaces, plugins y documentación sin mejorar nada.
- **Un refactor masivo de `$_POST`.** 1.242 sitios, con lógica de negocio
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

Los dos contadores que tienen regla propia —y que por eso no dependen de que
alguien mire el informe— son:

| Regla | Línea base | Comando |
|---|---|---|
| Accesos directos a `$_GET`/`$_POST`/`$_REQUEST` (frente 4) | 1.242 en 141 archivos | `make input-audit` |
| SQL escrito a mano en la aplicación (frente 3) | 13 en 6 archivos, más 25 excepciones con motivo | `make sql-audit` |
| `<script>` en línea sin nonce (frente 5) | 0 de 98 | `make csp-nonce` |

Un refactor está justificado si **baja** alguna señal sin que suba ninguna
otra, y si `make ci` sigue en verde.

## 7. Las 21 páginas de la raíz (revisión del 2026-10-23)

La raíz del proyecto es el arranque de todo: 21 archivos `.php` y 1.881 líneas
que mezclan páginas públicas (`open.php`, `tickets.php`, `view.php`), arranques
compartidos (`client.inc.php`, `secure.inc.php`, `main.inc.php`,
`bootstrap.php`), utilidades (`file.php`, `avatar.php`, `logo.php`,
`captcha.php`, `manifest.php`) y el CLI (`manage.php`).

**No se renombró nada.** Los nombres de estos archivos son las URL del
producto —y están en los correos, en los enlaces de acceso y en la
documentación—, así que la revisión se limitó a corregir lo que estaba mal y a
quitar lo que no se ejecutaba.

| Archivo | Papel | Qué se corrigió |
|---|---|---|
| `index.php` | portada del portal | sin cambios |
| `open.php` | alta de ticket | `Http::redirect()` al crear (antes cabecera suelta sin `exit`); captcha sin avisos si falta el campo o la sesión |
| `tickets.php` | ver el ticket y responder | lecturas de `$_REQUEST`/`$_POST` con valor por omisión; CRLF → LF |
| `view.php` | enlaces de acceso del correo | comprobaba `id` y redirigía con `t`: el enlace no llevaba al ticket |
| `login.php` | acceso y enlace por número | `lpasswd`, `lemail`, `bk` y el destino guardado, sin avisos |
| `account.php` | alta de cuenta | `backend`, `passwd1`, `passwd2` y `do` con valor por omisión |
| `profile.php` | perfil con sesión | `class.user.php` por `INCLUDE_DIR` |
| `pwreset.php` | restablecer contraseña | `token`, `do` y `userid` con valor por omisión |
| `logout.php` | cierre de sesión | `auth` opcional |
| `file.php` | descarga de adjuntos | `$thisuser` no existe: un cliente con sesión volvía al login; `$_GET` con valores por omisión |
| `avatar.php` | avatares generados | `size` opcional |
| `logo.php` | logo del portal | `Http::redirect()` (antes cabecera sin `exit`) |
| `captcha.php` | imagen del captcha | `image/png` y `no-store`; 500 explícito si falta GD |
| `manifest.php` | manifiesto de la PWA | sin cambios |
| `offline.php` | página de mantenimiento | `Http::redirect()` en vez de cabecera + `include` |
| `ajax.php` | despachador del portal | sin cambios |
| `client.inc.php` | arranque del portal | `SCRIPT_NAME`/`REQUEST_METHOD` con valor por omisión; `profile.php` por `ROOT_DIR` |
| `secure.inc.php` | arranque con sesión | `dirname(__FILE__)` en vez de rutas relativas al directorio de trabajo |
| `main.inc.php` | arranque común | `bootstrap.php` por `__DIR__`; fuera el bloque de «magic quotes» |
| `bootstrap.php` | constantes, config y conexión | fuera el relleno de `exif_imagetype` duplicado y cuatro bloques de código muerto; aviso en el log si `SECRET_SALT` es el predecible |
| `manage.php` | CLI | `bootstrap.php` por `__DIR__` |

**Los tres defectos que no eran cosméticos:**

1. **`view.php`** leía `$_GET['t']` pero comprobaba `isset($_GET['id'])`; con
   el enlace de acceso (`view.php?t=…`) la redirección no ocurría nunca.
2. **`file.php`** consultaba `$thisuser`, una variable que no existe en el
   arranque del cliente. La condición «¿hay sesión?» era siempre verdadera y,
   con «exigir sesión para los archivos» activado, un cliente con sesión
   volvía al login en cada descarga.
3. **`Http::redirect()` en lugar de `header('Location')`** en `open.php`,
   `logo.php` y `offline.php`: una cabecera suelta no detiene el script, así
   que la petición seguía ejecutándose (y en `offline.php` renderizaba la
   portada entera después de la redirección).

**Lo que se dejó como estaba, a propósito:**

- **El valor de `SECRET_SALT`** cuando no está definido en `ost-config.php`.
  Es predecible (`md5(prefijo + correo del administrador)`), pero cambiarlo
  invalida las sesiones y los datos derivados de una instalación en marcha. Se
  añadió un aviso en el log y la corrección sigue siendo declararlo en la
  configuración (`docs/SECURITY_AUDIT.md`).
- **`error_reporting`** del arranque (oculta avisos y deprecaciones). En el
  contenedor los `php.ini` de `docker/php/conf.d/` sí los registran, que es
  donde se diagnostica; cambiarlo en producción llenaría el log de avisos de
  código heredado.
- **La estructura de `bootstrap.php`.** Se podía mover su clase a
  `include/class.bootstrap.php`, pero el archivo lo incluyen instalador,
  actualizador, CLI y plugins con rutas ya escritas: el riesgo no compensa.

La prueba `tests/EntryPointTest.php` fija lo anterior: que las 21 páginas
parsean, que no hay BOM ni finales de línea mezclados, que ninguna manda una
cabecera `Location` a mano, y las tres regresiones concretas.
