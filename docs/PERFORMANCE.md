# Rendimiento: abrir un ticket

«Al abrir un ticket tarda» no dice *dónde*. La página del ticket
(`scp/tickets.php?id=…`) hace muchas cosas: carga el ticket y sus relaciones,
el hilo con sus entradas, los eventos, los adjuntos, los colaboradores, las
tareas y los campos del formulario. Cualquiera de ellas puede ser la cara.

Antes de optimizar se mide. `php manage.php perf` hace **lo mismo que la
página**, por pasos, y cronometra cada uno con las consultas que provoca.

## Uso

```bash
# El último ticket modificado (el que acabas de abrir)
make ticket-perf

# Uno concreto
make ticket-perf TICKET=405

# Las 20 consultas que más tiempo suman, no las 12 de por omisión
make ticket-perf TICKET=405 PERF_ARGS="--top=20"

# Salida JSON, para comparar antes y después
make ticket-perf TICKET=405 PERF_ARGS="--json"
```

## Qué mide

| Paso | Qué incluye |
| --- | --- |
| `Ticket::lookup()` | La consulta del ticket y su hidratación |
| Encabezado | Departamento, rol, agente, usuario, equipo, SLA y bloqueo |
| Hijo/enlazado | `getMergeType()` y `getChildren()` |
| `getThread()` | El hilo |
| Colaboradores | Las cuatro llamadas que hace la página |
| `getEntries()` | Las entradas del hilo, hidratadas como en el `foreach` del template |
| Eventos | Los eventos del hilo |
| Adjuntos | La consulta de adjuntos del hilo |
| Carga de acciones | El `include` de `class.thread_actions.php`, que es lo que registra los botones de cada entrada |
| Cuerpo → HTML | Lo que la plantilla hace **una vez por entrada**: `getBody()->toHtml()`, con el cuerpo sin cachear |
| Acciones por entrada | `hasActions()` y `getMergeData()` de cada entrada: los botones que se ofrecen en cada una. **Ojo:** sin cargar el archivo antes, el registro está vacío y el paso mide cero |
| Proemio del template | Lo que la plantilla hace antes del bucle: precargar adjuntos, contar entradas y eventos, ordenar |
| `render()` | El hilo completo pintado (se descarta la salida, se mide el trabajo) |
| `render()` otra vez | La segunda pasada, con las clases y los modelos ya cargados: separa lo que se paga una vez de lo que se paga en cada pintado |
| Formulario | `DynamicFormEntry::forTicket()`, los campos personalizados |
| Tareas: el número | Lo único que la página hace con las tareas: `getNumTasks()` y `getNumOpenTasks()`, el número de la pestaña |
| Tareas: listado | La consulta del listado, que **sólo** se hace al abrir la pestaña (llega por AJAX) |

Para que las medidas sean comparables, el perfilado usa el **peor caso**: un
administrador (ve todos los departamentos y todos los botones).

## Cómo se lee

```
Ticket #428 — perfil de la carga
Opciones: --ticket=428 · --top=20 · --step=«render»
-------------------------------------------------------------
  Paso                                   ms   SQLms  SQL  Esq  N
  Ticket::lookup()                      13.4     1.7    1   10  1
  Hilo: render() completo               24.5     7.1   10    2  9335
  Hilo: render() otra vez (caché tibia)  9.7     4.0    5    0  9335
  ...
Total: 77.1 ms en 17 pasos · 32 consultas reales (15.5 ms en SQL)
       + 22 de introspección de esquema (17.5 ms): ...
       = 33.0 ms en base de datos y 44.1 ms en PHP (plantillas, objetos, saneado del HTML)

Las 12 consultas que más tiempo suman
   12.4 ms ·   7 × · SELECT * FROM ost_thread_entry WHERE id = ?
    ...
El paso más caro: «Hilo: render() completo» (24.5 ms, 10 consultas)
La consulta más repetida: 7 veces — SELECT * FROM ost_thread_entry WHERE id = ?
```

* **ms** de un paso es tiempo de reloj, no sólo de SQL: incluye el trabajo de
  PHP y el pintado del template.
* **SQLms** es lo que de ese tiempo se fue en la base de datos. El resto del
  paso —`ms` menos `SQLms`— es PHP: plantillas, objetos, formateo. Es la
  primera pregunta que hay que contestar antes de optimizar nada, y la línea
  `= … en base de datos y … en PHP` del total la resume.
* **SQL** son las consultas **reales** que lanzó el paso, y **Esq** las de
  introspección del esquema. Van en columnas distintas a propósito: en la
  consola, un paso que toca ocho tablas nuevas suma ocho `INFORMATION_SCHEMA`
  que la web no paga, y contarlas juntas hacía creer que
  `Ticket::lookup()` necesitaba once consultas.
* La línea **Opciones** dice qué se aplicó de verdad en esa ejecución (sólo
  aparece lo que se pasó; sin opciones no se imprime). Nació de un defecto:
  el módulo declaraba `var $_options = array();` y `Module::parseOptions()`
  daba por hecho que ya había analizado las opciones —el centinela era
  `is_array($this->_options)`—, así que `--ticket`, `--top`, `--step`,
  `--schema` y `--json` **se ignoraban en silencio** y el informe mostraba
  siempre el último ticket y doce filas. Con la línea delante, un filtro que
  no llega se ve en la primera pantalla en vez de descubrirse comparando
  filas a ojo.
* La lista final agrupa por **patrón**: la misma consulta con otro id es la
  misma fila, y se ordena por tiempo **total**, no por una ejecución. Una
  consulta de 1 ms repetida 40 veces pesa más que una de 8 ms, y es la que hay
  que arreglar.

> El perfilador se engancha en `db_query()`: sólo cuando
> `$GLOBALS['__db_profile']` tiene una función (lo pone este comando). No hay
> que activar nada en la aplicación, no escribe en el registro y no queda nada
> encendido al terminar el proceso.

## Cómo se usó de verdad: el caso del ticket 428

Con la salida real de un ticket de 4 entradas, el reparto fue:

| Dato | Valor |
| --- | --- |
| Total | 77-92 ms según si APCu está activo |
| Introspección de esquema | 17-21 ms · 22 consultas (sólo en la consola) |
| Consultas reales | 32 · 15-17 ms |
| **PHP** | **≈ 44 ms** |

Con eso, la conclusión fue inmediata: **no era la base de datos**. Y dentro del
hilo, el desglose descartó dos sospechas caras de perseguir:

- el cuerpo de las entradas a HTML: **0.4 ms** para las cuatro;
- las acciones por entrada: **1.3 ms**.

Lo que sí quedó a la vista fue el coste de repetición: `render()` pintado dos
veces baja de 24.5 a 9.7 ms, y la consulta de precarga de adjuntos se ejecuta
varias veces por carga. Ese es el tipo de cosa que este informe existe para
enseñar sin leer el ORM.

## Lo que ya se arregló

Estas dos cosas se veían en el código sin necesidad de medir, y se
comprobaron con el perfilador:

1. **Una consulta por entrada al pintar el hilo.**
   `include/staff/templates/thread-entries.tmpl.php` recorría las entradas y
   hacía `ThreadEntry::lookup($entry->id)` dentro del bucle, aunque
   `ThreadEntry::sortEntries()` ya devuelve los modelos hidratados. En un hilo
   de 20 entradas eran 20 consultas por carga, y además se perdía la anotación
   `has_attachments` que trae `Thread::getEntries()`.

2. **Los colaboradores se contaban cuatro veces.**
   `Thread::getNumCollaborators()` hacía un `COUNT(*)` en cada llamada y la
   página lo llama desde la pestaña, el menú y la cabecera del hilo. Ahora se
   cuenta una vez y se recuerda en la instancia del hilo.

3. **El autor de cada aviso del hilo costaba una consulta.** Lo dijo el
   perfil real del ticket 428, no una lectura del código: dos consultas por
   carga atribuidas a `include/class.thread.event.php:112`, que es la línea que
   hace `$this->user->getName()` al pintar un aviso. `ThreadEvent` no declaraba
   esas relaciones, así que cada aviso con autor las buscaba por su cuenta (y
   otra vez en la segunda pasada del render). Ahora `user` y `agent` están en
   el `select_related` de la meta del modelo, así que viajan en la consulta de
   los avisos: `tests/QuerySqlTest.php` comprueba con el compilador de SQL que
   el `LEFT JOIN` se añade.

> Ojo con pedirlo desde `render()`: `$thread->getEvents()` devuelve una
> `InstrumentedList` y `select_related()` sobre ella recae en un **clon** de la
> consulta (su `__call()` proxya a `objects()`, que clona). No tiene efecto y no
> da error: hay una prueba que vigila que no se vuelva a intentar por ahí.

## Detallar un paso

`--step=TEXTO` lista, debajo del informe, las consultas de los pasos cuyo
nombre contenga ese texto. Es lo que convierte «este paso cuesta 38 ms» en
«estas cuatro consultas son la misma, una por entrada»:

```bash
make ticket-perf TICKET=405 PERF_ARGS="--step=hilo"
# y si se quiere ver también la introspección del esquema
make ticket-perf TICKET=405 PERF_ARGS="--step=hilo --schema"
```

```
Consultas de «Hilo: render() completo» — 26.1 ms, 13 SQL
--------------------------------------------------------------
   1.5 ms ·   4 × · SELECT ... FROM ost_thread_entry__merge WHERE thread_entry_id = ?
        desde include/class.thread.php:1012
```

Y debajo de cada consulta, la línea de código que la disparó (`desde …`). Es lo
que evita leer el ORM para adivinar quién pide qué.

Si el texto no coincide con ningún paso, el informe **lo dice y lista los pasos
medidos**, para poder repetir el comando con el nombre correcto.

## De dónde salen las consultas

Cada consulta se atribuye a la primera línea de osTicket que la disparó (se
saltan los marcos del ORM y de `mysqli.php`, que sólo son el mensajero). El
informe lo resume en un bloque propio:

```
De dónde salen las consultas (sin la introspección del esquema)
--------------------------------------------------------------
    7.2 ms ·  12 × ·  4 SQL distintas · include/class.thread.php:1012
    4.1 ms ·  20 × ·  1 SQL distinta  · include/class.ticket.php:1188
```

La columna `SQL distintas` distingue una línea que repite **la misma** consulta
(una por entrada: hay que arreglarla con `select_related` o precargando) de una
que pide cuatro cosas distintas (no es un bucle, es trabajo suyo).

El origen se recoge **después** de parar el cronómetro de cada consulta: el
coste de mirar la pila no se cuenta como tiempo de base de datos.

## La introspección del esquema se cuenta aparte

El ORM, la primera vez que ve una tabla en un proceso, pregunta sus columnas a
`INFORMATION_SCHEMA`. Son consultas de verdad, pero **no son de la página**:
con APCu se cachean 30 minutos. El informe las separa:

```
Total: 25.4 ms en 12 pasos · 24 consultas reales (9.3 ms en SQL)
       + 22 de introspección de esquema (16.0 ms): en la web las paga APCu una vez cada 30 min
```

Y no salen en el ranking salvo que se pidan con `--schema`. Si aparecían arriba
era fácil perseguir un fantasma: eran 22 tablas distintas, no 22 veces la misma
consulta.

## Los dos artefactos de la consola

Un perfil hecho con `php manage.php perf` mide en la consola, y la consola no
es la web. Hay dos cachés que en la web están y en la consola no:

| Caché | Sin ella | Efecto en el informe |
| --- | --- | --- |
| **APCu** | Cada tabla nueva pregunta sus columnas a `INFORMATION_SCHEMA` | La columna `Esq` y su línea aparte |
| **opcache** | Cada `include` vuelve a compilar la plantilla | Los pasos que pintan (`render()`) se inflan una vez **por entrada** |

Desde que `make ticket-perf` activa las dos (`-d opcache.enable_cli=1 -d
apc.enable_cli=1`), el informe se parece a lo que ve el usuario. Con
`make ticket-perf-raw` se ven los artefactos, que es útil para entenderlos pero
no para decidir qué optimizar.

Si el informe termina con un aviso sobre opcache, el `render()` de esa salida
no es el de la web: la plantilla de cada entrada se ha compilado de nuevo. Y si
avisa de APCu, es importante:

```
Nota: APCu no está activo en este proceso (apc.enable_cli=0).
```

Con APCu, osTicket guarda en memoria el esquema de cada tabla y la
introspección (`SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS …`) se paga
**una vez por tabla cada 30 minutos**, no en cada petición. En la línea de
comandos `apc.enable_cli` está en `0` (ver `docker/php/conf.d/00-osticket.ini`),
así que ese proceso **no tiene caché** y el perfil muestra esas consultas como
si fueran de cada carga.

Consecuencia práctica: el número de consultas del perfil por CLI es más alto
que el de la página real. Para comparar de verdad:

```bash
# Cuántas consultas hace una petición web de verdad
docker compose exec -T php php -d apc.enable_cli=1 manage.php perf --ticket=405
```

(o mirar el `Cluster`/`Query count` en la barra de depuración del navegador).

## Cuando el tiempo es PHP: el perfil por función

`perf` contesta «este paso tarda 27 ms». Si ese paso es `render()` o
`Ticket::lookup()`, el siguiente escalón es saber **en qué función** están esos
milisegundos. Para eso está Xdebug, que el contenedor ya trae instalado
(`docker/php/Dockerfile`), y un lector propio del perfil:
`tools/quality/php-profile.php`.

```bash
# Perfila la carga del ticket 428 y enseña las 20 funciones más caras
make ticket-perf-xdebug TICKET=428

# Con más filas, o solo lo que toque a una parte del código
make ticket-perf-xdebug TICKET=428 TOP=40
docker compose exec -T php php tools/quality/php-profile.php \
    --filter=thread /tmp/cachegrind.out.*
```

Qué hace el objetivo: lanza `manage.php perf` con `XDEBUG_MODE=profile` y lee
el `cachegrind.out.*` que Xdebug deja en `/tmp` del contenedor. El modo se pasa
como **variable de entorno** y no como `-d xdebug.mode=profile` porque en
Xdebug 3 `XDEBUG_MODE` manda sobre el ini, y el contenedor trae
`XDEBUG_MODE=develop,debug` (`docker/php/Dockerfile`).

Cómo se lee la tabla: la columna de la izquierda es el **tiempo propio** —lo
que gasta la función sin contar lo que llama— y no el tiempo acumulado. Es a
propósito: el acumulado siempre corona a `main()` o a `include`, porque todos
los demás cuelgan de ahí. Con el tiempo propio se ve dónde está el trabajo.
Los nombres salen con su archivo al lado para que se pueda saltar al código.

Dos advertencias, por orden de importancia:

* **El perfilador no mide, estorba.** Con Xdebug en modo `profile` todo va
  entre 2 y 5 veces más lento. Los milisegundos absolutos de esta tabla **no**
  se comparan con los de `make ticket-perf`; lo que vale es el **reparto**: qué
  porcentaje del tiempo se lleva cada función.
* La primera llamada incluye la compilación de las plantillas. El objetivo
  activa opcache y APCu para que eso no aparezca como si fuera trabajo de la
  función.

Si el patrón de archivos no encuentra nada, la herramienta lo dice y sale
con error: un informe de «0 µs en 0 funciones» hacía dudar del perfil cuando
el problema era que Xdebug no había escrito el archivo (o que el glob no
apuntaba a `/tmp` del contenedor).

El lector del formato (`PhpProfile::parse()`) está cubierto por
`tests/PhpProfileTest.php`: resuelve los nombres comprimidos `(1)` del
cachegrind, no suma al tiempo propio la línea de una llamada (`calls=`) y
tolera un perfil vacío sin romperse. **Pendiente de comprobar con un perfil
real de este contenedor**: la herramienta está probada contra el formato, pero
todavía no se ha ejecutado sobre la salida de Xdebug de una instalación con
datos, así que las cifras por función siguen sin confirmar.

## Si un paso falla

Un paso roto no se lleva el informe: se marca `ERROR` en su fila, el motivo
aparece debajo de la tabla y el resto se mide igual. Así se puede perfilar una
instalación con datos raros sin que el comando muera.

## Qué mirar si sigue lento

* Si el paso caro es `render()`, el coste está en el número de entradas del
  hilo: la lista final dirá si hay consultas repetidas por entrada (adjuntos,
  usuarios, respuestas). Un hilo con 200 mensajes es caro por diseño.
* Si el caro es `Ticket::lookup()` o el encabezado, el problema es de índices o
  de una instalación muy grande, no de la plantilla.
* Si **todos** los pasos son rápidos y la página sigue tardando, el tiempo no
  está en el servidor: mira la pestaña *Red* del navegador (tamaño de los
  adjuntos, imágenes en línea, JS). `make docker-bench` mide la petición
  completa contra el contenedor.
* Si el retraso aparece sólo la primera vez tras arrancar, es el arranque en
  frío de PHP/OPcache, no osTicket.
