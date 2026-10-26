# Diagnóstico de colas

Las colas de tickets del panel (`tickets.php?queue=…`) se pintan con dos
números que salen de sitios distintos: la tabla sale de **una consulta** y el
«Showing 1 - 25 of about N» sale de **otro recuento**. Cuando no coinciden, la
pantalla miente de una forma muy difícil de leer: una tabla vacía con «23 de
23» encima, o seis filas de un total de treinta y siete.

`php manage.php queue` ejecuta la misma consulta que la página, la cuenta por
separado y luego cuenta lo que hay de verdad en la base. Con los tres números
en la mano se sabe cuál de los tres está mal.

## Uso

```bash
# Qué colas hay, con su identificador y su recuento
make queues
# o, sin pasar por make:
docker compose exec -T php php manage.php queue --list

# Revisar una cola (la 8 es «Closed» en la instalación por omisión)
make queue-check QUEUE=8

# Con el SQL completo, tal y como lo compiló el ORM
make queue-check QUEUE=8 QUEUE_ARGS=--sql

# Comparar los seis periodos de fecha (Hoy, Ayer, Esta semana…)
make queue-check QUEUE=8 QUEUE_ARGS="--periods --sql"

# Todas las colas de una vez (una sección por cola)
make queue-check

# Por título, sin distinguir mayúsculas
docker compose exec -T php php manage.php queue --name=closed

# Con la visibilidad de otro agente (?ayuda a reproducir «yo no veo esos tickets»)
docker compose exec -T php php manage.php queue --id=8 --as=agente1

# Salida JSON, para engancharla a un informe
docker compose exec -T php php manage.php queue --id=8 --json
```

Códigos de salida: **0** si los números cuadran, **1** si hay alguna
contradicción. Se puede usar en un `Makefile` o en un script de arranque sin
tener que leer la salida.

## Qué mira

| Sección | Qué enseña |
| --- | --- |
| Cola | Identificador, título, banderas (`pública`, `cola`, `hereda criterio`…), cola padre, columnas y el **criterio ya descrito** (con el de los padres si lo hereda) |
| Agente | Con quién se calculó la visibilidad: un administrador por omisión, el que pida `--as=` |
| Consulta de la lista | Forma de la consulta (SELECT, JOIN, LEFT JOIN, WHERE, GROUP BY, ORDER BY, LIMIT), tablas y parámetros. Con `--sql`, el SQL completo |
| Números | Filas que devuelve la consulta, total de **esa misma** consulta sin `LIMIT`, `getCount()` de la cola (con su caché de cinco minutos) y `getTotal()` (sin caché) |
| ¿Dónde se pierden las filas? | Cuántos tickets quedan en cada tramo de la consulta (todos, criterio, visibilidad, filtro de hijos) y el SQL del tramo final |
| ¿Qué columna se come las filas? | Cuántos tickets quedan aplicando **cada columna por separado**, y cuántos sin ninguna: separa «el criterio no encuentra nada» de «una columna se lleva las filas por el camino» |
| Tablas materializadas | Filas de `ost_ticket__cdata`, tickets **sin** fila en ella, y tickets sin formulario (`form_entry`) |
| Datos en la base | Tickets por estado, cuántos tienen fecha de cierre, y las incoherencias: cerrados sin fecha y con fecha pero sin estado cerrado |
| Periodos | Sólo con `--periods` o cuando el criterio usa un periodo: el rango de fechas de cada periodo y cuántos tickets caen dentro por `closed` y por `created` |
| Veredicto | `OK`, `AVISO` o `FALLO`, con la explicación de qué número no cuadra y con cuál |

## «La cola dice 23 y no lista ninguno»

El caso real de la cola *Closed*: la navegación decía **23 tickets**, la
cabecera «Showing 1 - 23 of about 23» y la tabla salía vacía. No es el
criterio, no son los permisos y no se arregla recargando.

La cola cuenta y lista por caminos distintos:

* el **recuento** (`SavedQueue::counts()`) usa una consulta sin columnas y se
  guarda cinco minutos en caché;
* el **listado** (`getQuery()`) añade las columnas, y cada columna mete su
  `JOIN`.

Así que hay dos preguntas, y el diagnóstico las responde en este orden:

### 1. ¿Se pierden las filas antes de las columnas?

```
¿Dónde se pierden las filas?
  Tramo de la consulta                            Tickets
  Todos los tickets (sin criterio, sin columnas)  60
  Sólo el criterio de la cola                     23
  + visibilidad del agente                        23
  + sin tickets hijos (lo que lista la página)    0
  Las filas desaparecen en: + sin tickets hijos (lo que lista la página)
```

Se cuenta la consulta por tramos: todos los tickets, sólo el criterio, con la
visibilidad del agente y con el filtro que deja fuera los tickets hijos (que
es lo que ejecuta la página). **El tramo donde el número cae a cero es el
culpable**, y ahí se imprime el SQL completo con los parámetros ya sustituidos
para poder pegarlo en MariaDB.

Si el tramo que falla es el de los hijos, se detallan sus dos ramas, porque la
condición es un `OR`:

```
Rama del filtro de hijos                             Tickets
Sin ticket padre (`ticket_pid IS NULL`)              0
`ticket_pid = 0` (no es NULL: nunca pasa el filtro)  60
Marcados como enlazados (`flags & 8 <> 0`)           0
```

Es decir: si `ticket_pid` vale `0` en vez de `NULL`, ningún ticket cumple
`ticket_pid IS NULL`; y si además nadie tiene la bandera de enlazado, ninguna
de las dos ramas del `OR` se cumple y la cola no puede listar nada aunque los
cuente. El valor por omisión de la columna es `NULL`, así que un `0` ahí viene
de quien escribió los tickets (una importación, un script de siembra, una
migración antigua).

### La visibilidad del agente: el caso que más veces explica «yo no lo veo»

Las colas no listan «los tickets que cumplen el criterio», sino **los que el
agente puede ver**: `dept_id IN (sus departamentos) OR asignado a él OR
referido`. Y esos departamentos no son todos los de la instalación: salen de
`staff_dept_access`, del departamento principal y de los que gestiona. Un
administrador no ve, por serlo, los departamentos que no tiene asignados.

El informe lo deja en una tabla:

```
Departamentos y visibilidad del agente
  Departamento    Tickets  ¿Lo ve el agente?
  1 — Support     40       sí
  4 — Soporte N2  20       NO
  Departamentos del agente: 1, 2, 3
  Tickets fuera de su alcance: 20 · Soporte N2 (20)
```

Si hay tickets fuera, el veredicto lo dice y **no** culpa al SQL: se arregla en
Admin → Agents → el agente → *Departments*, o cambiando el departamento del
ticket. Con datos de demostración lo arregla `make seed`, que concede al
administrador existente el acceso a los departamentos que crea (si no, los
tickets existen, se cuentan y no se listan).

### 2. Si el criterio sí encuentra los tickets: ¿qué columna se los come?

```
¿Qué columna se come las filas?
  Columna                          Tickets que quedan
  Sólo el criterio (sin columnas)  23
  Ticket # (1)                     23
  Subject (3)                      0
```

Cada columna no sólo añade datos al `SELECT`: mete `JOIN`. La del asunto va
contra `ost_ticket__cdata` y ese `JOIN` es **interno**, así que un ticket sin
fila en esa tabla desaparece del listado aunque el recuento lo siga contando.
La sección **Tablas materializadas** dice cuántos tickets están en ese caso; si
son varios, se reconstruye la tabla (ver abajo). Si son cero, el problema no
está en la vista y hay que mirar el `JOIN` que añade esa columna concreta.

### Reconstruir las tablas materializadas

```bash
make db-views           # sólo mira: filas, filas esperadas y aviso
make db-rebuild-views   # borra y reconstruye desde form_entry_values
```

`ost_ticket__cdata` no la escribe la aplicación fila a fila: se crea con
`CREATE TABLE … AS SELECT` sobre `form_entry_values` y luego sólo se
*actualiza* al guardar respuestas. Si se creó con pocos tickets o un proceso
se cortó, los que faltan no están. Reconstruirla es seguro: es una copia
derivada y la verdad está en `form_entry_values`.

Si el aviso dice que faltan **tickets sin formulario** (`form_entry`), el
problema está antes: esos tickets no tienen datos de formulario guardados y no
hay nada que reconstruir. Los crea mal quien los crea; con los de
demostración, `make seed-check` lo detecta.

> El recuento cacheado tarda hasta cinco minutos en refrescarse. Para verlo al
> instante, `docker compose exec -T php php manage.php queue --list` lo
> recalcula.

## Cómo se lee el veredicto

El comando compara tres cosas:

1. **el dato** — lo que hay en la base;
2. **la consulta** — el SQL que compila el ORM con la visibilidad del agente,
   el filtro rápido, las columnas de la cola, el `distinct` del listado y la
   ventana de paginación, en el mismo orden en que lo hace la página;
3. **el recuento** — el número que la cola dice tener.

| Lo que se ve | Qué significa |
| --- | --- |
| «cuenta 23 tickets pero no devuelve ninguna fila» | El SQL está roto (o los JOIN recortan): el fallo está en la consulta |
| «devuelve 0 y la base tiene 23 que cumplen el criterio» | El criterio de la cola no llega al `WHERE`: el fallo está en la traducción de criterio a SQL |
| «recuento real (0) no coincide con el de la cola (23)» | El recuento sale de otra consulta: la tabla y el número nunca podrán coincidir |
| «recuento cacheado (23) no coincide con el real (2)» | El número de la navegación viene de la caché de cinco minutos de `SavedQueue::counts()`. Se corrige solo a los cinco minutos, o con `make docker-reset` si no |
| «cerrados sin fecha de cierre» | Los tickets están en estado `closed` pero `closed` está vacío: no salen en los filtros por fecha. Es un problema del **dato** (de quien los cerró, o del sembrador) |
| «con fecha de cierre pero estado distinto de closed» | Lo contrario: los filtros por estado y por fecha discrepan |

## Si el comando muere con «Unable to prepare query»

```
Uncaught InconsistentModelException: Unable to prepare query:  SELECT A1.* FROM `ost_staff` …
```

Con dos espacios y el motivo en blanco. No es que falte el motivo: MariaDB sí
contestó, pero el aviso del fallo (`db_query()` lo registra en `ost_syslog` y
manda un correo al administrador) deja `mysqli::$error` limpio, y el ORM lee el
error *después*. Desde esta versión, `db_query()` guarda el error antes de
registrarlo (`include/class.db.failure.php`) y el mensaje ya incluye el motivo
real, con su número:

```
Unable to prepare query: [1054] Unknown column 'A1.admin' in 'where clause' SELECT …
```

Ese caso concreto ya está corregido: la columna de `ost_staff` se llama
`isadmin`, no `admin`. Si aparece otro, el número entre corchetes
(`1054` columna desconocida, `1146` tabla inexistente, `1045` permisos…) dice
por dónde empezar. El error completo también queda en el registro del sistema,
que se lee desde el panel (*Admin → Panel → Registros*) o con:

```bash
docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "
  SELECT created, title, LEFT(log, 400) AS log FROM ost_syslog
   WHERE log_type = \'Error\' ORDER BY id DESC LIMIT 5;"'
```

## Cuando hay que mirar la base a mano

El comando ya hace estas consultas, pero si hace falta el SQL suelto, ojo con
dos detalles del entorno:

- el servicio se llama **`db`** y la imagen es **MariaDB 11.4**, cuyo cliente se
  llama **`mariadb`**, no `mysql`. Por eso
  `docker compose exec -T db mysql …` responde
  `exec: "mysql": executable file not found in $PATH`;
- `$MYSQL_ROOT_PASSWORD` no está definido en el equipo (vive en el `.env` y
  dentro del contenedor se llama `$MARIADB_ROOT_PASSWORD`). Pedirle la
  variable al shell del equipo la deja vacía; lo correcto es dejar que la
  expanda el contenedor, con comillas simples:

```bash
docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "
  SELECT s.state, COUNT(*) AS tickets
    FROM ost_ticket t JOIN ost_ticket_status s ON s.id = t.status_id
   GROUP BY s.state;
  SELECT COUNT(*) AS con_fecha_de_cierre FROM ost_ticket WHERE closed IS NOT NULL;
"'
```

Si el prefijo de las tablas no es `ost_`, se cambia en la consulta (está en
`include/ost-config.php`, constante `TABLE_PREFIX`).

## Cómo se arregló el «23 de 23» con la tabla vacía

El total que se muestra bajo la tabla ya no viene de `SavedQueue::getCount()`
—que cuenta el criterio de la cola y nada más: ni el filtro rápido, ni las
columnas, ni la visibilidad que añade la plantilla, y con cinco minutos de
caché— sino de **la misma consulta** que llena la tabla, tomada antes de
paginar y con el mismo `distinct('ticket_id')` para que los JOIN no la inflen
(`include/staff/templates/queue-tickets.tmpl.php`).

Eso no devuelve los tickets que faltan: hace que el número y las filas no
puedan contradecirse, y deja el síntoma a la vista («0» en vez de «23 de 23»).
Para saber cuál de los tres números es el que falla, está este comando.

## Relacionado

- `docs/SEED.md` — los datos de demostración y `make seed-doctor`, que revisa
  que lo sembrado cuadre con los informes.
- `docs/DOCKER.md` — el entorno, los servicios y los puertos.
