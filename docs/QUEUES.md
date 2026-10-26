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
| Datos en la base | Tickets por estado, cuántos tienen fecha de cierre, y las incoherencias: cerrados sin fecha y con fecha pero sin estado cerrado |
| Periodos | Sólo con `--periods` o cuando el criterio usa un periodo: el rango de fechas de cada periodo y cuántos tickets caen dentro por `closed` y por `created` |
| Veredicto | `OK`, `AVISO` o `FALLO`, con la explicación de qué número no cuadra y con cuál |

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
