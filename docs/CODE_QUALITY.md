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
| Archivo más grande | `include/class.ticket.php` — 4.516 líneas |
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
| `include/class.ticket.php` | 4.516 | 2 | Ticket: creación, edición, correo, SLA, adjuntos | 4.809, y `create()` de 536 a 219 líneas |
| `include/class.forms.php` | 4.516 | 37 | Núcleo de formularios y campos | 6.220 líneas, 64 clases |
| `include/class.orm.php` | 3.663 | 34 | ORM propio (consultas, joins, instrumentación) | igual |
| `include/class.queue.php` | 2.341 | 11 | Colas, columnas y ordenación | 3.232 líneas, 38 clases |
| `include/class.thread.php` | 2.338 | 11 | Hilo de entradas, correo entrante, colaboradores | 3.405 líneas, 35 clases |
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

**Dónde está** (medido el 2026-10-27 con `make metrics`): la primera tanda está
hecha y en uso —catorce piezas puras sacadas de las funciones más largas, con
417 pruebas nuevas y once defectos reales corregidos—, y ninguna de esas
funciones ha vuelto a crecer. Desde entonces `Ticket::create()` se partió
además en nueve fases (frente 6), así que la función más larga de la
aplicación ya no es un flujo de 500 líneas.

**Tanda del 2026-10-27** (frentes 2 y 6 a la vez):

- `MassAction::analyzeMerge()` se queda con las **tres comprobaciones del
  diálogo de combinar/enlazar**, que vivían dentro del `foreach` que recorre
  los tickets, mezcladas con la consulta y con los permisos. Entra una lista de
  filas ya leídas y sale el padre que encabeza la lista y el aviso; no toca la
  base, así que tiene prueba propia (siete casos: sin padres, un padre, dos
  padres, enlazar una combinación, hijo combinado, combinación visual y el
  orden en que gana el último aviso). De paso desaparece un aviso de PHP:
  `$parent[0]` se evaluaba sobre `false` cuando no había padre.
- El **diálogo de asignación** deja de estar escrito dos veces. Lo que era
  idéntico en tickets y en tareas sale a `MassAction`: la consulta de agentes
  asignables de cada tipo (`ticketAgentMembers()`, `taskAgentMembers()` —la
  única diferencia real entre las dos, porque tickets parte de los
  departamentos del agente y tareas de los agentes activos—), el armado del
  desplegable (`memberOptions()`, `teamAssignees()`) y el **cierre común** de
  las dos `massProcess()`: mensaje de resultado, aviso de «no se pudo
  procesar» y campos del formulario devueltos escapados (`finish()`).
  `massProcess()` de tickets baja de **276 a 227** y el de tareas de
  **237 a 191**. Seis pruebas nuevas para los desplegables y para el cierre
  (prefijos `s`/`t`, aviso cuando no hay a quién asignar, singular y plural,
  el error propio manda sobre el genérico, y sin POST no se toca nada). La
  consulta de agentes se queda en `MassAction` porque cada tipo tiene la suya,
  pero `memberOptions()` y `teamAssignees()` no tocan la base.
- `MassAction` gana los **textos de los diálogos en lote**, que estaban escritos
  dos veces —una por cada `massProcess()`— letra por letra:
  `assignPrompt()`, `confirm()`, `failure()`, `selection_noun()` y
  `agentOrder()`. El orden de la lista de agentes según el formato de nombre
  era un `switch` duplicado; ahora es una función con prueba. `massProcess()`
  de tickets baja de **321 a 276** y el de tareas de **287 a 237**.
  Cinco pruebas nuevas en `tests/MassActionTest.php` fijan los textos tal como
  estaban (son de caracterización: si alguien cambia un mensaje, salta).
- `run()` de `include/cli/modules/file.php`: **408 → 12 líneas**. Las nueve
  acciones son ahora `actionBackends()`, `actionList()`, `actionDump()`,
  `actionLoad()`, `actionMigrate()`, `actionExport()`, `actionImport()`,
  `actionZip()` y `actionExpunge()`, y el despacho es una tabla
  (`'action' . ucfirst($args['action'])`). De paso se corrigieron dos cosas:
  el `switch` **no tenía `default`**, así que una acción inventada terminaba
  con código 0 y sin decir nada (ahora se para con `unknown action`), y las
  cinco copias de «archivos que cumplen el criterio» y las dos de «el archivo
  único» son `matchingFiles()` y `oneFile()`. Cuatro pruebas en
  `tests/FileManagerTest.php`, una de ellas comprobando que **toda** acción
  del listado de ayuda tiene su método.
- Frente 6: `class.ticket.php` baja de **1.982 a 1.834** líneas al moverse la
  familia de vencimientos y SLA a `include/class.ticket.sla.php` (rasgo
  `TicketSla`: once métodos, 182 líneas). Cuatro pruebas en
  `tests/TicketSlaTest.php`, entre ellas que **no queden definiciones
  duplicadas** —PHP no avisa y una copia vieja en el modelo taparía la del
  rasgo— y que el archivo siga con margen bajo el tope.
- **`Mailer::send()`: de 270 a 147 líneas** (medido con `make metrics`). El
  corte va por donde el propio código ya lo pedía: `MailerMessage` se queda
  con lo que no envía nada y `Mailer` con lo que sí. Entran dos piezas puras
  con nueve pruebas nuevas (`tests/MailerMessageTest.php`): `replyTag()` —los
  tres casos del separador de la respuesta citada: la opción ausente, la
  opción en `false` y la opción con texto— y `rewriteContentIds()` —el
  `preg_replace_callback` de los `cid:`, que ahora recibe el dominio y una
  función que dice qué sufijo lleva cada imagen o `null` para dejarla como
  está—. Lo que se queda en `Mailer` son cuatro pasos privados con su
  docblock: `addRecipients()` (el `switch` de clases de destinatario),
  `attachFiles()` (los adjuntos colgados del `Mailer`), `deliverViaSmtp()`
  (la vuelta por las cuentas SMTP, con el `Sender` de sustitución y la vuelta
  al `From` original) y `deliverViaSendmail()` (el `mail()` de PHP con
  `from_address`). No se tocó ni el envío ni el orden de los transportes: es
  la misma secuencia, ahora con nombres.
- **`Ticket::postMessage()`: de 178 a 142 líneas.** El bloque que decidía a
  quién le llega el aviso de mensaje nuevo —último en responder, asignado o su
  equipo, jefe del departamento y gestor de la cuenta— estaba dentro del
  método, mezclado con el envío. Ahora es `TicketPost::alertRecipients()`, que
  devuelve a quién preguntar en el mismo orden, y `alertableRecipients()`, que
  deja fuera a los vacíos, a los agentes sin correo, a los no disponibles y a
  los repetidos (comparando la dirección). Once pruebas con dobles en
  `tests/TicketPostTest.php`, incluida la del hijo combinado: el mensaje va al
  padre pero la organización del gestor de cuenta se seguía leyendo del ticket
  donde se escribió, y por eso `alertRecipients()` acepta un cuarto argumento.
- `MassAction::mergePermissions()` sale del diálogo de combinar/enlazar: el
  permiso se resuelve **por departamento** y basta uno sin permiso para que el
  diálogo avise. Tres pruebas, con un agente de mentira que reparte roles. De
  paso, el ticket sobre el que se abre el diálogo pasa a ser **el primero** de
  la lista: se usaba la variable del bucle (`$ticket[0]`), que acababa siendo
  el último leído, así que el comentario y el código no coincidían.

El criterio de éxito **no se cumple todavía**: quedan **5 funciones de la
aplicación por encima de 120 líneas** (`massProcess()` de tickets 230,
`Ticket::create()` 219, `massProcess()` de tareas 191, `Mailer::send()` 147 y
`Ticket::postMessage()` 142, que ya sólo pasan por 27 y 22 líneas), pero
**ninguna pasa de 230** y ni el envío de correo ni la respuesta del cliente
son las más largas. Los archivos por encima de 3.000 ya no existen: los tres se repartieron
en el frente 6. Lo que queda pendiente son las dos `massProcess()` —el caso
`assign`, gemelo letra por letra en tickets y tareas—, `Ticket::create()` y
`Ticket::postMessage()`: los cuatro tocan la base, así que antes hay que
ampliar el banco de `make docker-integration` (hoy 19 casos y sólo ORM).

| Objetivo | Antes | Ahora |
|---|---|---|
| Función más larga de la aplicación | 536 líneas | **302** (`install()` del instalador; la de la aplicación es `massProcess()` de tickets con **230**) |
| `massProcess()` de tickets | 377 líneas | **230** (`MassAction`: textos de los diálogos, análisis de la combinación, permisos por departamento y diálogo de asignación) |
| `massProcess()` de tareas | 330 líneas | **191** (`MassAction`) |
| `run()` de `include/cli/modules/file.php` | 408 líneas | **12** (nueve `actionXxx()` + `matchingFiles()` / `oneFile()`) |
| `Mailer::send()` | 297 líneas | **147** (`MailerMessage` + cuatro pasos privados: destinatarios, adjuntos, SMTP y Sendmail) |
| `Ticket::create()` | 536 líneas | **219** (nueve fases en `TicketCreate`) |
| `Ticket::postMessage()` | 190 líneas | **142** (`TicketPost`: `alertRecipients()` y `alertableRecipients()`) |
| `Thread::postEmail()` | 181 líneas | **149** (`ThreadEmailInfo`), y después **33** (cuatro pasos: `applyCollaboratorSender()`, `applyReplyType()`, `stampUnknownSender()` y `postReply()`) |
| `Queue::update()` | 157 líneas | **13** (ocho pasos: `setupTitle()` … `setupCriteria()`) |
| `I18n::loadDefaultData()` | 131 líneas | **9** (siete pasos: `loadModels()` … `loadEmailTemplates()`) |
| `ThreadEntry::lookupByEmailHeaders()` | 126 líneas | **102** (`ThreadEmailInfo::candidates()`) |
| `Thread::lookupByEmailHeaders()` | 66 líneas | **28** (la misma pieza) |
| `ThreadEntry::create()` | 185 líneas | **158** (`ThreadEntryInfo`) |
| `Staff::update()` | 137 líneas | **118** (`StaffCheck`) |
| `Ticket::update()` | 133 líneas | **116** (`TicketEdit`) |
| `Topic::update()` | 126 líneas | **105** (`ModelRules`) |
| `Dept::update()` | 159 líneas | **137** (`ModelRules` + `StaffCheck`), y después **103** (`validate()`) |
| `Organization::update()` | 128 líneas | **120** (`ModelRules`) |
| `Ticket::open()` | 129 líneas | **119** (`TicketCreate::openErrors()`) |
| `Ticket::setStatus()` | 121 líneas | **108** (`logStatusChange()`, `ModelRules`) |
| `Search::IndexOldStuff()` | 137 líneas | **31** (un método por tipo + `SearchRecord`) |
| `File::create()` | 122 líneas | **98** (`FileInput`) |
| `MailParse::getAttachments()` | 127 líneas | **64** (`MailAttachment`) |
| `QuerySet::compileSelect()` | 222 líneas | **100** (`SelectSql`; `fields()` 148) |
| `SelectSql::fields()` | 148 líneas | **57** (cinco tramos: relaciones, `values()`, simple, anotaciones y extras) |
| `Schedule::buildFields()` | 175 líneas | **5** (`ScheduleEntryFormFields`) |
| `Validator::validate()` | 129 líneas | **52** (`ValidatorRule`) |
| Funciones de la aplicación por encima de 120 líneas | — | **5** |
| Funciones de la aplicación por encima de 150 líneas | 10 | **5** (medido el 2026-10-27: `Mailer::send()` 270, `massProcess()` de tickets 227, `Ticket::create()` 219, `massProcess()` de tareas 191 y `Ticket::postMessage()` 178) |
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
2. **Dos funciones intermedias (120–175 líneas) que nadie ha tocado**, y que
   no estaban en el plan: `Report::getTabularData()` (158) y
   `Thread::create()` (158). Son del mismo tipo que las que ya se partieron
   (armar campos, validar, componer mensajes), así que el método que funcionó
   con `Queue::update()`, `I18n::loadDefaultData()` y `Thread::postEmail()`
   sirve para ellas.
3. **Los tres archivos de más de 3.000 líneas** son trabajo del frente 6
   (reparto por familias de clases), no de éste, pero el criterio de éxito del
   frente 2 los incluye. `class.forms.php` es el que más se resiste: 36 clases
   y 4.516 líneas.
4. **Las herramientas del propio proyecto** también aparecen en la lista
   (`_build()` del módulo de idiomas con 190, `install()` del instalador con
   302, `sqlaudit_main()` con 172). El `run()` del gestor de archivos ya no:
   son nueve métodos de menos de 60 líneas. El sembrador de datos de demostración
   (`include/cli/modules/seed.php`) nace ya repartido en diez pasos cortos, uno
   por tipo de registro, para no engordar esa lista. No son código de la aplicación —el criterio
   habla de la aplicación, y las tres primeras son CLI— pero están a la vista
   y se parten cuando se toquen por otro motivo.

   Las dos herramientas que se escribieron en esta tanda de trabajo no han
   añadido deuda: `run()` de `include/cli/modules/perf.php` llegó a **231**
   líneas al crecer el perfilador y se partió en cinco métodos (el mayor,
   `stepsThreadBody()`, 109); `seedAgents()` llegó a 125 y su lista de campos se
   movió a `agentVars()` (82 y 50). Se comprobó con `make metrics` antes de
   dar la tanda por terminada.

Dos defectos reales más salieron al partir `Queue::update()` y `Dept::update()`,
y los dos estaban en el guardado de datos:

- **`CustomQueue::update()` leía `$form` antes de asignarlo.** La línea era
  `$form = $form ?: $this->getForm($vars);`, con `$form` sin definir: un aviso
  de PHP por cada guardado de cola. El valor de partida era `null`, así que
  quitarlo deja exactamente el mismo resultado y el aviso desaparece.
- **`Dept::update()` consultaba el departamento padre dos veces seguidas.**
  `static::lookup($vars['pid'])` guardaba la respuesta en `$p`, que no se usaba
  nunca, y la línea siguiente volvía a pedir el mismo registro con
  `Dept::lookup($vars['pid'])`. Ahora es una sola consulta, y el error de
  «Department selection is required» sale igual.

**Comprobación de las cifras de arriba** (2026-10-26, tras esta tanda, con
`make metrics --top=400`): 7 funciones de la aplicación por encima de 120
líneas —quince menos que al empezar la tanda, ninguna nueva—, 7 por encima de 150 y
(las siete que quedan pasan de 150: de ahí en adelante cada una exige una prueba
de integración que hoy no existe)
3 archivos por encima de 3.000 (`class.ticket.php` 4.516, `class.forms.php`
4.515, `class.orm.php` 3.643). La función más larga de la aplicación sigue
siendo `massProcess()` con 321 líneas. Fuera del criterio, por ser librerías
empaquetadas o herramientas: `hl_tag` (448) y `hl_balance` (334) de
`htmLawed.php`, `decode` (278) de `JSON.php`, `checkVariableUsage` (248) de las
pruebas del instalador.

**Criterio:** sacar de las funciones largas los tramos que no tocan la base de
datos, a una clase pequeña con nombre propio y con pruebas. Los tramos que sí
tocan la base de datos se quedan donde están hasta que haya una prueba de
integración con base de datos.

Pasos hechos: **417 casos nuevos** sin base de datos.

| Pieza | Qué contiene | Pruebas |
|---|---|---|
| `include/class.ticket.post.php` | `TicketPost::normalizeVars()` (origen e IP del mensaje), `withoutPoster()` (quita al autor de los destinatarios), `shouldAutoRespond()` (rebotes y respuestas automáticas) | `tests/TicketPostTest.php`, 14 casos |
| `include/class.thread.email.php` | `ThreadEmailInfo::accepts()` (ticket cerrado que no se reabre), `varsFromMail()` (traducción del correo a variables de la entrada), `entryTypeForUserClass()` (mensaje del cliente o nota del agente), `poster()`, `receivedFrom()`, `candidates()`/`embeddedId()` (por qué message-id se prueba primero: `mid`, `in-reply-to`, `references` al revés y el `Ref-Mid:` del cuerpo) y `collaboratorType()` (un colaborador en copia escribe mensaje, no nota, y con qué usuario) | `tests/ThreadEmailTest.php`, 32 casos |
| `include/class.thread.entry.php` | `ThreadEntryInfo::title()` y `poster()` (qué se guarda), `replyFlags()` (respuesta a todos / al usuario), `bodyFlags()` (HTML equilibrado y nota de sistema), `dropStrippedImages()` y `markInline()` (adjuntos incrustados) | `tests/ThreadEntryInfoTest.php`, 22 casos |
| `include/class.ticket.create.php` | `TicketCreate::requiredFields()` (qué exige cada origen: web, staff, api, email), `fieldFilter()` (qué se valida según quién abre), `dueDateError()`, `source()` y `openErrors()` (al abrir desde el panel: origen conocido y, sin usuario elegido, correo con forma de correo y nombre), más las nueve fases de `create()` | `tests/TicketCreateTest.php`, 23 casos |
| `include/class.massaction.php` | `MassAction::actions()` (la tabla de acciones de tickets y de tareas, con sus verbos), `selection()`/`selectionError()` (cuántos se han marcado y el aviso si no hay ninguno), `ids()`/`splitIds()` (identificadores de `GET`, `POST` o `REQUEST`, separados por comas o en lista), `mergeAction()` (combinar o enlazar según la ruta), `moveToFront()` (el padre primero), `statusFor()` (reabrir/cerrar), `successMessage()`/`partialMessage()` y `selectedScript()` (el script que copia las casillas marcadas al diálogo) | `tests/MassActionTest.php`, 37 casos |
| `include/class.mailer.message.php` | `MailerMessage::cleanSubject()` (asunto en una línea, sin saltos), `extraHeaders()` (boletín, respuesta automática y aviso, con el orden de siempre), `wrapMid()` (el `div` invisible con el identificador del mensaje), `textBody()` (HTML a texto y `Ref-Mid`) y `contentIdDomain()` (dominio de las imágenes incrustadas) | `tests/MailerMessageTest.php`, 24 casos |
| `include/class.queue.sort.php` | `QueueSortPlan::reconcile()`: qué pasa con las ordenaciones de una cola guardada cuando el formulario manda otra lista —las que se quedan y en qué posición, las que se quitan y las que se añaden—, sin tocar la base de datos. La prueba incluye una copia literal del bucle anterior y compara las dos salidas con siete entradas, cadenas del POST incluidas | `tests/QueueSortTest.php`, 9 casos |
| `include/class.orm.select.php` | `SelectSql::fields()` 148 → **57**: qué columnas pide un SELECT (`raíz.*`, `values()`, campos diferidos, relaciones con JOIN, anotaciones, `extra['select']` y `GROUP BY`), ahora en cinco tramos (`relatedFields()`, `valueFields()`, `plainFields()`, `addAnnotations()`, `extraFields()`) que devuelven sus columnas y su GROUP BY | `tests/QuerySqlTest.php`, 39 casos |
| `include/class.validator.rule.php` | `ValidatorRule::check()`: la tabla de tipos de la definición de un campo (`int`, `double`, `text`, `date`, `email`, `phone`, `zipcode`, `username`, `password`, `url`, `cs-domain`, `cs-url`, `ipaddr`) y qué pasa cada valor, devolviendo el mensaje de error del campo o `null`; `everyValue()` (listas de valores) y los ayudantes de correo, URL, teléfono y usuario | `tests/ValidatorRuleTest.php`, 18 casos |
| `include/class.ticket.edit.php` | `TicketEdit`: los cuatro campos que se validan al editar un ticket y sus mensajes, la regla de la fecha límite (ticket cerrado, fecha legible y futura, con el conversor inyectado), la comprobación del origen y **qué cambios quedan en el historial** (`trackedChanges()`) | `tests/TicketEditTest.php`, 13 casos |
| `include/class.search.record.php` | `SearchRecord`: cómo se arma cada registro del índice (`object_type`, id, título, contenido) para los cinco tipos —entrada del hilo, ticket, usuario, organización y artículo de la base de conocimiento— con sus diferencias: la entrada vacía no se indexa, el ticket no recorta y el usuario y la organización sí, y las palabras clave van delante de la pregunta | `tests/SearchRecordTest.php`, 18 casos |
| `include/class.file.input.php` | `FileInput`: lo que se decide antes de guardar un archivo. Descodificar base64, resolver la llamada diferida (`data_cbk`) una sola vez, el tipo (el del cliente, si no el de `finfo`, si no el genérico), el tamaño cuando el cliente no lo manda (backend, `mb_strlen` en `8bit`, `strlen`) y cuándo merece la pena buscar un archivo idéntico | `tests/FileInputTest.php`, 17 casos |
| `include/class.mail.attachment.php` | `MailAttachment`: qué parte de un correo es un adjunto (`isAttachment()`: `attachment`, `inline` o tipo `image`/`application`) y cómo se llama (`filename()`: `filename`, `filename*` RFC 5987, `name`, `name*`, `filename="…"`, imagen sin nombre, `message/rfc822` y la forma fragmentada `filename*0=`). `describe()` devuelve nombre y tipo ya decididos, `part()` normaliza la parte MIME (PEAR no define las propiedades que el correo no trae) y `size()` cuenta bytes | `tests/MailAttachmentTest.php`, 35 casos, cinco de ellos con correos MIME de verdad |
| `include/class.model.rules.php` | `ModelRules`: las banderas por estado (`active`/`archived`), la regla de «no dejar la lista vacía» (`wouldEmpty()`), el destino de una asignación (`assignTarget()`: `s123` a un agente, `t45` a un equipo), las referencias opcionales que deben existir (`unknownReferences()`), las banderas de a quién se puede asignar (`assignmentFlags()`), qué señales de «editado» manda un guardado (`editedSignals()`, `editedColumns()`), la última bandera que cambió en una organización (`lastFlagAudit()`), la comparación de la lista de contactos (`idListChanged()`) el volcado de columnas con su tipo (`fieldValues()`), el permiso que pide cada estado del ticket (`statusPermission()`) y a quién se le deja un ticket al reabrirlo (`reopenAssignee()`) | `tests/ModelRulesTest.php`, 40 casos |
| `include/class.staff.check.php` | `StaffCheck`: campos obligatorios del agente, la regla del **último administrador** activo (que ya escondía un defecto: comparaba con una variable que sólo existe cuando cambian usuario o correo) y el formato de los accesos a departamentos y de las pertenencias a equipos. `Dept::update()` reutiliza `accessPairs()`, así que las dos pantallas arman los accesos igual | `tests/StaffCheckTest.php`, 11 casos |
| `include/class.schedule.fields.php` | `ScheduleEntryFormFields::build()`: los 13 campos del formulario de horario (nombre, fecha, todo el día, hora de inicio y fin, repetición, día de la semana, del mes y del año), sus valores por omisión y **cuándo se muestra cada uno** (`allday`, `repeats`, `monthly`, `yearly`), armados por bloques (`head()`, `weekly()`, `monthly()`, `yearly()`) | `tests/ScheduleFieldsTest.php`, 17 casos |

Las piezas están en uso (`Ticket::postMessage()`, `Thread::postEmail()`,
`ThreadEntry::create()`, `Ticket::create()`, los dos `massProcess()`,
`Mailer::send()`, `compileSelect()`, `Validator::validate()`,
`ScheduleEntryForm::buildFields()`, `Staff::update()`, `Topic::update()` y
`Dept::update()`; `TicketEdit` se usa en `Ticket::update()`). No cambia nada de lo
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

**Al partir ese tramo en `plainFields()` se perdió una condición**, y con ella
la lista de tickets de `/scp/`. El código original pedía `raíz.*` sólo si la
consulta no era agregada (`!$queryset->aggregated`); la extracción dejó la
condición fuera, así que una consulta agregada —la que sólo pide `COUNT(...)`—
volvía a pedir `raíz.*` y su SELECT quedaba con dos columnas. Ejecutada sola no
se nota. Dentro de una anotación, que es como `TicketThreadCount::annotate()`
pinta el número de mensajes de cada ticket, deja de ser una subconsulta escalar
y MySQL responde «[1241] Operand should contain 1 column(s)»: la cola se queda
sin filas y el error sólo aparece en el log del contenedor. Ninguna de las 26
consultas que había entonces era agregada, así que la suite siguió en verde
durante todo el frente. Ahora hay dos casos —una consulta agregada sola y una
subconsulta escalar dentro de una anotación— que reproducen ese SQL sin base de
datos; el segundo falla con el código que había y pasa con el arreglo.

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

La prueba con correos de verdad de `getAttachments()` destapó tres más del
mismo tipo, y las tres estaban en el camino de **cada correo que entra**:
`Mail_Parse::$header` y `Mail_Parse::$notes` no estaban declaradas (una
deprecación de PHP 8.2 por cada mensaje recibido) y `Mail_mimeDecode::$_input`
tampoco, en el decodificador MIME empaquetado —el aviso salía dos veces por
correo—. Declararlas es una línea en cada sitio y el log deja de llenarse.
En la misma tanda se les puso tipo de retorno a los cinco métodos de
`Iterator` de `TnefStreamReader`/`TnefAttributeStreamReader` (cinco avisos más
por cada `winmail.dat`) y se corrigió el parámetro implícitamente anulable de
`tests/SeedPurgeTest.php`, que PHP 8.4 avisa en la primera línea de la suite.

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
| **aplicación** | **36** | 14 | El código de osTicket, sin las herramientas de CLI | es la deuda, y baja |
| herramientas de CLI | 17 | 5 | `include/cli/`: sembrador, diagnóstico, mantenimiento, perfiles | no |
| instalador | 5 | 2 | `setup/` e `include/class.setup.php`: corre antes de que exista el esquema | no |
| actualizador (histórico) | 33 | 16 | `include/upgrader/streams/`: migraciones de esquemas viejos | no |
| driver de base de datos | 6 | 1 | `include/mysqli.php`: ahí `db_query()` *es* el trabajo | no |
| dobles de prueba | 22 | 1 | `tests/support/`: SQL mal escrito a propósito | no |

Medido el 2026-10-27, con las herramientas de línea de comandos en su propia
categoría (antes contaban como aplicación): **36 llamadas en la aplicación, 25
marcadas como no migrables** con su motivo (`sql-audit:legacy`) y **11 por
migrar** (`5 SELECT`, `3 DML`, `2 DDL` y `1 «otras»`; las 11 construidas
concatenando). El objetivo era bajar de 100 y se cumple con holgura. **Ninguna
concatenación de datos de la petición** (eso lo vigila `tools/audit` con
OST-SQLI y también el propio `sql-audit`, que falla si aparece una en el punto
de llamada). Lo que resta son consultas de campos dinámicos, el índice
`FULLTEXT` de los informes y la carga de idiomas; ninguna es una lista
paginada. Las **17 llamadas del CLI** (sembrador, diagnóstico de colas,
mantenimiento de la base, perfiles) se informan aparte y no entran en el
objetivo: no están en el camino de una petición.

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
- Hay un **tope de marcas** (`SqlAudit::LEGACY_MAX`): si se pudiera marcar
  todo, el contador volvería a no significar nada. Está en **27** —se subió
  desde 25 al entrar los módulos del sembrador— y se usa **entero**, así que el
  frente 3 no admite una marca más sin migrar otra cosa a la vez. Las dos
  nuevas son de `include/cli/modules/seed.php`: el borrado de filas de hilo sin
  dueño es un `DELETE` con `LEFT JOIN` (el ORM no lo sabe expresar) y
  `scalar()` es el ayudante de sólo lectura de las comprobaciones del doctor.

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

Las 27 marcas son de diez archivos, y ninguna es una consulta de la
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
| `include/cli/modules/seed.php` | 2 | el borrado de filas de hilo sin dueño es un `DELETE` con `LEFT JOIN`, fuera del ORM; `scalar()` es el ayudante de sólo lectura de las comprobaciones del doctor, que arma sus consultas con constantes. Desde el 2026-10-27 el sembrador es categoría `cli`, así que estas dos marcas se informan aparte y no cuentan en el objetivo de la aplicación |

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
- **`select_related()` es un `JOIN` interno, y esconde filas**: `FAQ::$meta`
  declara `select_related('category')`, así que `FAQ::objects()->all()` sólo
  devuelve artículos **cuya categoría existe**. La purga de la base de
  conocimiento leía por ahí y enumeraba cero huérfanos con 33 en la tabla
  (`docs/UI_GUIDE.md` §3.13.10). Cuando hay que recorrer filas que pueden
  tener la relación rota —huérfanas, «sin padre», «sin dueño»—, se apaga la
  relación (`$query->related = false`, lo mismo que hace `compileCount()`) o
  se cuenta con SQL, pero **no se comparan dos vistas distintas del mismo
  dato**: un chequeo con `LEFT JOIN` y una reparación con `INNER JOIN` nunca
  cuadran.

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
`tools/quality/sql-baseline.json` (11 llamadas en 5 archivos) y **falla** si:

- un archivo nuevo trae SQL a mano,
- cualquier archivo sube respecto de la línea base,
- una llamada concatena datos de la petición sin escapar,
- una marca `sql-audit:legacy` no lleva motivo, o se pasa del tope.

Ya está dentro de `make ci`, detrás de `input-audit`. Para consolidar una
bajada: `php tools/quality/sql-audit.php --update`.

**Cerrado el 2026-10-27: la categoría `cli`.** `make sql-audit` estaba en rojo
por cuatro archivos nuevos con SQL a mano (**14 llamadas**) fuera de la línea
base. Ninguna era una consulta de la aplicación que un modelo pudiera
describir, pero tampoco valía marcarlas como `legacy` sin más. La decisión
—anotada aquí durante semanas— se aplicó tal cual:

> `include/cli/` son las **herramientas de operación** (sembrador,
> diagnóstico de colas, mantenimiento de la base, perfilado, idiomas). Corren
> desde la línea de comandos contra una base que ya existe y varias **tienen
> que** preguntar por el esquema o por tablas que el ORM no describe
> (`information_schema`, `SHOW STATUS`, `OPTIMIZE TABLE`, las tablas
> materializadas de los formularios). Igual que el `driver`: ahí
> `db_query()` es el trabajo, no la deuda.

`SqlAudit::categories()` pasa a `app, installer, upgrader, driver, cli,
tests`, y el informe imprime su propia línea:

```
  herramienta de CLI           17 llamadas en   5 archivos
Aplicación: 36 llamadas, 25 de ellas marcadas como no migrables -> 11 por migrar
```

Tres detalles de la implementación, para que la categoría no sea una puerta de
atrás:

1. **El tope de marcas sigue siendo uno solo.** `LEGACY_MAX` (27) se aplica a
   las marcas de `app` **y** de `cli` juntas: separar en categorías no puede
   servir para marcar más cosas sin justificarlas.
2. **La línea base sólo admite aplicación.** Se actualizó a mano para dejar
   el **movimiento** de la llamada del ORM en su archivo nuevo
   (`class.orm.php` → `class.orm.sql.php`) y para sacar
   `include/cli/modules/import.php`, que ya no es aplicación. Una prueba
   (`testLaLineaBaseSoloTieneAplicacion`) falla si alguien mete un archivo de
   otra categoría en el JSON.
3. **El SQL sin escapar se sigue mirando en todas partes.** `unsafe()` no
   distingue categorías (salvo los dobles de prueba): un `db_query()` con
   datos de la petición sin escapar es un fallo también en el CLI.

Resultado: **36 llamadas en la aplicación, 25 marcadas, 11 por migrar** (el
objetivo del frente era 100) y **`make sql-audit` en verde**. La prueba
`testLasHerramientasDeCliNoSonAplicacion` fija que `queue.php`, `db.php`,
`perf.php` y `seed.php` cuentan como CLI y que la categoría no se lleva por
delante archivos de la aplicación.

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

### Frente 6 — Partir los archivos de más de 2.000 líneas ✅ Hecho

- Qué hacer: `class.forms.php` (6.220 líneas, **64 clases**) y
  `class.ticket.php` en rasgos (`trait`) o clases colaboradoras, sin cambiar
  nombres públicos. Aquí sí conviene ir con calma: es donde más se rompe.
- **Cumplido el 2026-10-27: ningún archivo propio pasa de 2.000 líneas.** Los
  seis que lo hacían quedaron así:

  | Archivo | Antes | Ahora | Cómo |
  |---|---|---|---|
  | `class.forms.php` | 4.515 | **60** | índice; 37 clases a 7 archivos por familia |
  | `class.ticket.php` | 4.529 | **1.981** | una clase; 26 métodos a 6 rasgos |
  | `class.orm.php` | 3.643 | **1.661** | 24 clases a `class.orm.sql.php` y `class.orm.result.php` |
  | `include/cli/modules/seed.php` | 2.934 | **1.887** | una clase; 20 métodos al rasgo `SeedWrites` |
  | `class.search.php` | 2.030 | **1.955** | campos anotados y contadores a su archivo |
  | `class.dynamic_forms.php` | 2.026 | **1.937** | el buscador de la lista a su archivo |

  Más `class.queue.php` (2.400 → **1.600**), `class.thread.php` (2.351 →
  **1.900**) y `ajax.tickets.php` (2.021 → **1.771**). El mayor del proyecto es
  ahora `class.ticket.php` con **1.982**.

  **Refuerzo del 2026-10-27.** La regla se rompió después sin que nadie lo
  notara: `include/cli/modules/seed.php` llegó a **2.157** (la revisión y la
  reparación del sembrador se añadieron detrás) y `include/cli/modules/queue.php`
  a **2.065** (el informe del diagnóstico de colas). Los dos se han vuelto a
  partir por el mismo procedimiento —métodos enteros, sin tocar su código—:

  | Archivo | Antes | Ahora | Qué se fue |
  |---|---|---|---|
  | `include/cli/modules/seed.php` | 2.157 | **1.737** | `doctor()`, `repair()`, `repairFaqCategories()` y `repairEntryDates()` a `seed.repair.php` (rasgo `SeedRepair`, 442) |
  | `include/cli/modules/queue.php` | 2.065 | **1.682** | `verdict()`, `hasClosedStateCriteria()`, `wantedByCriteria()`, `printReport()` y `mentionsPeriod()` a `queue.report.php` (rasgo `QueueReport`, 409) |

  Ninguno de los dos es un archivo de clases: son un módulo del CLI con estado
  de ejecución (`$this->out()`, `$this->warn()`), así que la pieza correcta es
  un **rasgo** y no una clase colaboradora, igual que `SeedWrites`. Los `use`
  de las pruebas que leen el código como texto (`SeedDataTest`, `QueueCheckTest`)
  pasaron a mirar también los rasgos: si sólo mirasen el archivo de la clase, un
  traslado las haría fallar y la tentación sería borrarlas. `tools/ui/theme-check.php`
  hace lo mismo con las dos piezas del sembrador que comprueba.

  Medido después del reparto: **ningún archivo propio pasa de 2.000 líneas**.

  **Margen (2026-10-27).** El mayor era `class.ticket.php` con **1.982**: la
  siguiente función que se le añadiera rompía la regla. La familia de
  vencimientos y SLA se fue entera a `include/class.ticket.sla.php` (rasgo
  `TicketSla`, 11 métodos: `isOverdue()`, `getDueDate()`, `getSLADueDate()`,
  `updateEstDueDate()`, `getEstDueDate()`, `setSLAId()`, `selectSLAId()`,
  `markOverdue()`, `clearOverdue()`, `checkOverdue()` y `duedateField()`), y el
  modelo queda en **1.834**. Los mayores son ahora `class.ticket.php` (1.834),
  `class.dynamic_forms.php` (1.937) y `class.thread.php` (1.901); el de la
  búsqueda bajó a 1.476 al irse el backend de MySQL a su archivo.
  `tests/TicketSlaTest.php` comprueba que el rasgo se carga y se usa, que los
  once métodos **no quedan duplicados** en el modelo (PHP no avisa: la copia
  vieja taparía la del rasgo) y que el archivo sigue por debajo de 1.900.

  **Segundo reparto, el mismo día: `include/ajax.tickets.php`.** Era el archivo
  más grande del panel (**1.677** líneas, y la mitad de su tamaño de antes de
  esta tanda). Los cinco métodos que comparten el formulario de estado —
  `changeTicketStatus()`, `setTicketStatus()`, `changeSelectedTicketsStatus()`,
  `setSelectedTicketsStatus()` y `markAs()`, 347 líneas— se van enteros a
  `include/ajax.tickets.status.php` como rasgo `TicketsAjaxStatus`, igual que
  ya se hacía con `ajax.tickets.user.php`: son métodos de la misma clase, usan
  `$thisstaff` y los ayudantes de `AjaxController`, así que un rasgo los
  devuelve a `TicketsAjaxAPI` sin cambiar ninguna ruta del panel. El archivo
  queda en **1.333** y sale del top de los más grandes. Los dos ayudantes
  privados (`_changeTicketStatus()`, `_changeSelectedTicketsStatus()`) se
  quedan en la clase: el rasgo los llama con `self::`, que apunta a la clase
  que lo usa. `tests/TicketsAjaxSplitTest.php` fija las cuatro cosas que un
  movimiento así rompe en silencio: que el `include_once` vaya **antes** de la
  clase y haya su `use`, que los cinco métodos estén una sola vez y no queden
  copias en el archivo viejo, que los ayudantes privados sigan donde el rasgo
  los busca, y que el archivo siga por debajo de 1.400 líneas.

  El movimiento no añade deuda: `tools/quality/input-baseline.json` reparte los
  **10 accesos directos a `$_POST`/`$_REQUEST`** del bloque entre los dos
  archivos (38 en `ajax.tickets.php` → 28 + 10 en el nuevo), el total sigue en
  1.232 y `input-audit` vuelve a decir «sin subidas». `sql-audit` no cambia: el
  bloque no tiene SQL a mano.

  **Tercer reparto: `include/class.search.php`.** Era el archivo más grande del
  proyecto (**1.955** líneas). `MysqlSearchBackend` y `MySqlSearchConfig` —el
  backend FULLTEXT, su índice por lotes y su configuración, 480 líneas— se van
  enteras a `include/class.search.mysql.php`, **con su registro**: el
  `Signal::connect('system.install', …)` y el `MysqlSearchBackend::register()`
  que había justo detrás de la clase. Sin ellos el buscador dejaría de existir
  sin dar ningún error, que es la trampa clásica de mover una clase que se
  autoregistra. El original queda en **1.476** y lo incluye **al final**,
  después de `class.search.fields.php`, porque `MysqlSearchBackend` hereda de
  `SearchBackend`, que se queda allí. `tests/SearchMysqlSplitTest.php` fija el
  orden del `include_once`, que las dos clases no queden duplicadas, que el
  registro se haya movido y que el archivo siga por debajo de 1.500.
  `SearchRecordTest` leía el código de `class.search.php` para comprobar el
  `IndexOldStuff()` partido: ahora lee el archivo nuevo, donde está el método.

  De paso, una herramienta se miraba a sí misma: `run.mjs` deja en php-wasm una
  copia sin guardia de `cli` junto al original (`tools/quality/.class-map.wasm.php`),
  y `class-map.php` la contaba como un segundo `ClassMap` —un falso «error
  fatal». Ahora ignora los archivos que empiezan por punto (las copias
  temporales de las herramientas) y el informe vuelve a salir limpio.

  **Cómo se comprobó cada reparto**, sin fiarse del aspecto del archivo:
  1. Los límites de cada clase o método los da el **tokenizador de PHP**
     (docblock y modificadores incluidos). Contar llaves a mano no vale: las
     cadenas con interpolación y los `'{"dept_id":'` descuadran la cuenta.
  2. **Multiconjunto de líneas**: ninguna línea del original desaparece; las
     nuevas son sólo las cabeceras y los `include`/`use`.
  3. **Texto idéntico** de cada clase o método movido, comparado byte a byte
     contra el original.
  4. `make classes` (sin duplicados), `make input-audit` y la suite completa.

  Las tres trampas que aparecieron, todas detectadas por las pruebas y no por
  lectura: la **interfaz `Threadable`** no se puede mover al archivo que se
  incluye al final (`ObjectThread` la implementa y se declararía antes de que
  exista); **`class.orm.result.php` tiene que ir después de `class.util.php`**
  porque `InstrumentedList` hereda de `BaseList`; y `TicketPerfTest`,
  `OrmPropertiesTest`, `SeedDataTest` y `SeedPurgeTest` **leen el código como
  texto**, así que ahora siguen los `use Trait;` en vez de mirar sólo el archivo
  de la clase.

  **Un cuarto fallo, este sí llegó al navegador.** `class.dynamic_forms.php`
  termina en `?>`; el `include_once()` del archivo nuevo se añadió detrás, así
  que dejó de ser código y pasó a ser **texto de salida**: todas las páginas del
  portal —el login incluido— aparecían con la línea del `include_once` impresa
  encima del `<!DOCTYPE>`, y `/scp/` se quedaba en blanco porque la salida previa
  impedía mandar las cabeceras. La suite no lo podía ver: ninguna prueba
  renderiza una página, y el multiconjunto de líneas daba por bueno el traslado
  porque la línea seguía estando. Lo cazó una captura del usuario.

  Ahora hay guardián: `tests/LibraryOutputTest.php` recorre las 195 librerías de
  `include/` y falla si alguna escribe **texto fuera de PHP a nivel superior**
  (lo que hay dentro de un método es una plantilla que se devuelve, no salida).
  Se comprobó que la prueba falla de verdad volviendo a meter el `include_once()`
  detrás del `?>`: señala el archivo y la línea. Después del arreglo, cargar
  `class.dynamic_forms.php` escribe **0 bytes**.
- Lo hecho: `class.queue.php` bajó de 3.232 a **2.341** y `class.thread.php`
  de 3.405 a **2.318**, separando sus columnas y filtros y su historial de
  cambios y cuerpos de entrada.
- **`ajax.tickets.php` bajó de 2.021 a 1.771** con la primera pieza hecha con
  **rasgos** (`trait`) en vez de archivos de clases: `TicketsAjaxUser`
  (`ajax.tickets.user.php`, 282) se lleva los ocho métodos que buscan al
  usuario del ticket y editan su ficha. Un rasgo es lo correcto aquí porque son
  métodos de la misma API —usan `$thisstaff` y `$this->response()`— y no una
  clase aparte con estado propio. El `use TicketsAjaxUser;` va dentro de la
  clase y el `include_once` **antes** de declararla, porque en PHP el rasgo
  tiene que existir cuando se compila la clase.
  Efecto colateral que hubo que resolver: el contador del frente 4 pasó a ver
  «un archivo nuevo con acceso directo» (17 accesos que antes estaban dentro de
  `ajax.tickets.php`). No es deuda nueva —el total no cambió, 1.232— así que la
  línea base se actualizó: `ajax.tickets.php` 55 → 38 y el rasgo con 17. En la
  misma pasada se consolidaron diez accesos que ya se habían migrado a `Http` en
  tandas anteriores y seguían contados: la línea base pasa de 1.242 a 1.232.
- **`class.orm.php` bajó de 3.643 a 1.661**, el reparto más grande del frente
  después de los formularios. Las 34 clases quedaron así: el modelo en el
  archivo original (`ModelMeta`, `VerySimpleModel`, las anotaciones, `QuerySet`
  y `Q`), las expresiones y los compiladores SQL —`SqlFunction` y sus hijas,
  `SqlCompiler`, `MySqlCompiler`, `MySqlPreparedExecutor`, `MySqlExecutor`— en
  `class.orm.sql.php` (1.483) y los conjuntos de resultados en
  `class.orm.result.php` (584). **El orden de carga importa y no es el mismo en
  los dos casos**: las expresiones tienen que estar declaradas antes que el
  modelo (sus valores por omisión son `SqlFunction::NOW()`), así que ese archivo
  se incluye al principio; los conjuntos de resultados usan `BaseList`, de
  `class.util.php`, y se incluyen después de los `require_once`.
  `tests/OrmPropertiesTest.php` pasó a analizar los tres archivos: si se dejaba
  uno fuera, sus propiedades dinámicas quedarían sin revisar y la prueba
  pasaría por el motivo equivocado.
- **`class.search.php` (1.955) y `class.dynamic_forms.php` (1.937) también
  bajan de 2.000**: se fueron los campos anotados y los contadores del ticket
  (`class.search.fields.php`, 104) y el buscador de la lista de selección
  (`class.dynamic_forms.widget.php`, 107).
- **`class.queue.php` (1.600) y `class.thread.php` (1.900) ya están por debajo
  de 2.000.** Del primero se fueron diez clases a `class.queue.objects.php`
  (815): columnas y condiciones, exportación y ordenaciones, con `CustomQueue`
  sola en el original. Del segundo, seis a `class.thread.objects.php` (478):
  aviso cruzado, hilos de objeto, `ThreadEntries`, `TicketThread`, las acciones
  de entrada y la actividad. La interfaz `Threadable` **se queda donde estaba**
  a propósito: `ObjectThread` la implementa y vive en el archivo nuevo, que se
  incluye al final; si la interfaz se hubiera movido con él, `ObjectThread` se
  declararía antes de que existiera. El patrón es siempre el mismo:
  mover clases enteras a un archivo con nombre propio, incluir ese archivo
  desde el original y comprobar con `make classes` que no falta ni sobra
  ninguna.
- Lo hecho también: `class.forms.php` bajó de 6.220 a **4.515** líneas al
  separar los widgets de campo y los formularios de asignación.
- **`class.forms.php` ya no es un archivo: es un índice de 60 líneas.** Sus 37
  clases se repartieron por familias, movidas enteras y sin tocar su código:
  `class.forms.core.php` (592: `Form`, los formularios derivados y el marco de
  pintado), `class.forms.field.php` (894: `FormField`, el campo base),
  `class.forms.fields.php` (768: texto, contraseña, área de texto, teléfono,
  sí/no, elección, número y color), `class.forms.datetime.php` (567),
  `class.forms.ticket.php` (1.061: tema, SLA, prioridad, zona horaria,
  departamento, responsable, estado y banderas), `class.forms.file.php` (437) y
  `class.forms.inline.php` (293). El índice incluye las siete familias en orden
  de dependencia (el campo base antes que sus hijas) y conserva los seis
  `FormField::addFieldTypes()` y los dos `include_once()` que ya tenía.
  Comprobado de dos maneras: el mapa de clases sigue viendo las mismas 37
  clases y **el texto de cada clase es idéntico** al del archivo original.
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
- Siguiente, por relación riesgo/beneficio: los dos `massProcess()` de
  `ajax.tickets.php` y `ajax.tasks.php`, que son ya las funciones más largas de
  la aplicación. Eso es frente 2: sin prueba de integración no se tocan.
- Por qué: 6.000 líneas en un archivo hacen que cada búsqueda cueste y que
  los conflictos de fusión sean inevitables.
- Riesgo: alto. Sólo después de los frentes 1 y 2.
- Lo que queda dentro del frente, ya sin el criterio de las 2.000: `Ticket`
  sigue siendo una clase grande (1.981 líneas repartidas en seis rasgos) y
  `class.forms.php` es un índice de 60 líneas con siete archivos por familia. La
  siguiente vuelta, si se quiere seguir, es mover **métodos** de esos rasgos a
  clases colaboradoras con estado propio, que es lo que el frente 2 ya viene
  haciendo (`TicketCreate`, `TicketPost`, `MassAction`).

---

## 4. Orden recomendado

1. **Frente 1** (pruebas) — ✅ hecho.
2. **Frente 2** (funciones largas → clases pequeñas con pruebas) — en curso.
3. **Frente 4** (fachada de entrada) — ✅ la fachada y la regla están; la
   migración va archivo a archivo según se toquen.
4. **Frente 5** (JS inline) — ✅ `onclick` a cero desde hace varias tandas y
   `<script>` con `nonce`: la CSP ya no anuncia `'unsafe-inline'`.
5. **Frente 3** (SQL a mano) — ✅ por áreas y con pruebas: 30 → 13 llamadas.
6. **Frente 6** (archivos gigantes) — ✅ hecho, y **reforzado el 2026-10-27**:
   ningún archivo propio pasa de 2.000 líneas (los mayores son
   `class.dynamic_forms.php` con 1.937 y `class.thread.php` con 1.901; el
   backend de búsqueda salió a `class.search.mysql.php` y el formulario de
   estado del ticket a `ajax.tickets.status.php`). Los dos `massProcess()` y `postMessage()` son funciones, no
   archivos, y siguen en el frente 2. Lo que queda dentro del frente ya no es
   tamaño de archivo: `Ticket` (1.982 en seis rasgos) y los siete archivos de
   formularios piden mover **métodos** a clases colaboradoras con estado
   propio, que es exactamente el frente 2 (`TicketCreate`, `TicketPost`,
   `MassAction`).

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
| SQL escrito a mano en la aplicación (frente 3) | 11 en 5 archivos, más 25 excepciones con motivo (las del CLI se informan aparte) | `make sql-audit` |
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
