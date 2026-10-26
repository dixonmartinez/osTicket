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
| `render()` | El hilo completo pintado (se descarta la salida, se mide el trabajo) |
| Formulario | `DynamicFormEntry::forTicket()`, los campos personalizados |
| Tareas: el número | Lo único que la página hace con las tareas: `getNumTasks()` y `getNumOpenTasks()`, el número de la pestaña |
| Tareas: listado | La consulta del listado, que **sólo** se hace al abrir la pestaña (llega por AJAX) |

Para que las medidas sean comparables, el perfilado usa el **peor caso**: un
administrador (ve todos los departamentos y todos los botones).

## Cómo se lee

```
Ticket #405 — perfil de la carga
-------------------------------------------------------------
  Paso                                   ms   SQL   N
  Ticket::lookup()                       1.2     3   1
  Encabezado: dept, rol, agente, …       0.8     4   1
  Hilo: render() completo               42.7    31   7
  ...
Total: 58.4 ms en 11 pasos · 57 consultas (49.1 ms en SQL)

Las 12 consultas que más tiempo suman
   12.4 ms ·   7 × · SELECT * FROM ost_thread_entry WHERE id = ?
    ...
El paso más caro: «Hilo: render() completo» (42.7 ms, 31 consultas)
La consulta más repetida: 7 veces — SELECT * FROM ost_thread_entry WHERE id = ?
```

* **ms** de un paso es tiempo de reloj, no sólo de SQL: incluye el trabajo de
  PHP y el pintado del template.
* **SQL** son las consultas que lanzó el paso.
* La lista final agrupa por **patrón**: la misma consulta con otro id es la
  misma fila, y se ordena por tiempo **total**, no por una ejecución. Una
  consulta de 1 ms repetida 40 veces pesa más que una de 8 ms, y es la que hay
  que arreglar.

> El perfilador se engancha en `db_query()`: sólo cuando
> `$GLOBALS['__db_profile']` tiene una función (lo pone este comando). No hay
> que activar nada en la aplicación, no escribe en el registro y no queda nada
> encendido al terminar el proceso.

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

## Detallar un paso

`--step=TEXTO` lista, debajo del informe, las consultas de los pasos cuyo
nombre contenga ese texto. Es lo que convierte «este paso cuesta 38 ms» en
«estas cuatro consultas son la misma, una por entrada»:

```bash
make ticket-perf TICKET=405 PERF_ARGS="--step=hilo"
```

```
Consultas de «Hilo: render() completo» — 38.4 ms, 17 SQL
--------------------------------------------------------------
   1.5 ms ·   4 × · SELECT ... FROM ost_thread_entry__merge WHERE thread_entry_id = ?
```

## La nota de APCu

Si el informe termina con un aviso sobre APCu, es importante:

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
