# Datos de demostración: `make seed`

Carga un osTicket recién instalado con contenido parecido al de un help desk en
funcionamiento: cinco departamentos, cinco agentes, dos equipos, dos SLA, once
temas, cinco respuestas predefinidas, seis categorías de base de conocimiento
con treinta y tres artículos, doce clientes en tres organizaciones, **59
tickets** repartidos entre todos los estados, asignados y sin asignar, con
hilos de hasta 14 mensajes, y **16 tareas** abiertas, completadas y vencidas.

La idea es que ningún rincón de la aplicación se quede vacío: colas, filtros,
analíticas, SLAs vencidos, tareas, base de conocimiento y el hilo largo de una
conversación real.

Sirve para tres cosas concretas: ver cómo se comporta la interfaz con datos
reales (listas, filtros, paginación, modo oscuro), tener algo que mirar en el
panel de analíticas, y revisar plantillas de correo con tickets de verdad.

```bash
make seed            # crea todo (no duplica si ya existe)
make seed-fresh      # borra lo que creó y lo vuelve a crear
make seed-verbose    # lo mismo, con cada registro creado o saltado
make seed-check      # lista los pasos disponibles
```

> **`make seed -v` no sirve**: la `-v` la consume `make` (imprime su versión y
> no ejecuta nada). Para pasar opciones al sembrador:
> `make seed SEED_ARGS="-v --only=topics"`, o `make seed-verbose`.

Todo se ejecuta **dentro del contenedor** (no hace falta PHP en tu equipo):

```bash
docker compose exec -T php php manage.php seed             # lo mismo que make seed
docker compose exec -T php php manage.php seed --verbose   # ver cada registro
docker compose exec -T php php manage.php seed --only=topics
docker compose exec -T php php manage.php seed --count=10  # diez de cada uno
docker compose exec -T php php manage.php seed --no-tickets
```

| Opción | Qué hace |
|---|---|
| `--fresh` | borra lo creado por una ejecución anterior (según el manifiesto) y vuelve a crear |
| `--only=PASO` | ejecuta un solo paso (`orgs`, `users`, `slas`, `depts`, `agents`, `teams`, `topics`, `canned`, `faq`, `tickets`, `tasks`) |
| `--count=N` | cuántos registros crear del paso que se ejecuta: `users`, `tickets` o `tasks` (por omisión, los del plan curado: 12, 59 y 16). Con todos los pasos, se aplica a los tres |
| `--with-tasks` | al sembrar tickets, crea **una tarea de seguimiento por ticket**, enlazada a él |
| `--domain=D` | dominio de los correos de demostración (por omisión `example.com`) |
| `--no-tickets` | deja la configuración, pero ni tickets ni tareas |
| `--doctor` | no crea nada: revisa que los datos cuadren con los informes y las colas |
| `--repair` | arregla lo que el doctor detecta (artículos sin categoría, entradas antes que su padre, restos de hilo y vistas de datos) sin borrar ni volver a sembrar |
| `--list` | muestra los pasos |
| `--verbose` | muestra cada registro creado |
| `--quiet` | sólo avisa de los errores |

---

## Qué se crea

| Paso | Contenido |
|---|---|
| `orgs` | Acme S.A., Globex Corporation, Initech (con su dominio, para que los usuarios se asignen solos) |
| `users` | 12 clientes repartidos entre las tres organizaciones, con teléfono (`--count=N` crea N) |
| `slas` | Respuesta 8 h · Respuesta 24 h (24/7, sin horario asignado) |
| `depts` | Soporte · Soporte Técnico (hijo) · Facturación (hijo) · Ventas · Seguridad (privado) |
| `agents` | `supervisor`, `agente1`, `agente2`, `agente3` — y `admin` **sólo si la instalación no tiene ningún administrador**; todos con acceso a los cinco departamentos |
| `teams` | Turno de noche · Escalado (con líder y miembros) |
| `topics` | Problema técnico · Facturación y pagos · Solicitud de información · Alta de usuario · Reclamación · Cuenta y acceso · Soporte técnico · Formación · Incidente interno y Uso del panel (los dos últimos, privados) |
| `canned` | Saludo inicial · Petición de información · Ticket resuelto · Escalado a segundo nivel · Factura duplicada |
| `faq` | 6 categorías (Primeros pasos · Cuenta y acceso · Facturación · Soporte técnico · Seguridad y privacidad · Uso del panel) con 33 artículos publicados y buscables |
| `tickets` | 59 tickets (`--count=N` crea N) con antigüedad de 0 a 42 días, de origen Web/Email/Phone/API, con hilos de hasta 14 mensajes que alternan respuesta del agente, mensaje del cliente y nota interna |
| `tasks` | 16 tareas (`--count=N` crea N, sueltas): abiertas y completadas, varias vencidas, con y sin responsable, ligadas a un ticket o sueltas |

**Orden**: el de la tabla no es decorativo. Sin SLA no hay departamento
(apunta a su SLA), sin departamento no hay agente (cada uno tiene el suyo como
primario) y sin departamento ni tema no hay ticket. Si ejecuta un paso suelto
con `--only` y le faltan los anteriores, lo dirá con un aviso en vez de crear
registros a medias.

**Credenciales**: los agentes de demostración (`supervisor`, `agente1`,
`agente2`, `agente3`) y las cuentas de cliente usan la contraseña `Demo1234!`.

El **administrador no se duplica**. Quien instala osTicket ya tiene el suyo,
con el nombre de usuario y la contraseña que eligió; el sembrador lo busca por
`isadmin = 1` —no por el nombre `admin`, que el instalador deja elegir— y lo
usa tal cual:

- no crea un segundo administrador;
- **no le cambia la contraseña ni el correo**;
- firma con él las tareas y las notas internas, que necesitan un agente en
  sesión;
- al terminar, el resumen dice con qué usuario entrar al panel.

Sólo si la instalación no tiene **ningún** administrador activo se crea uno de
demostración (`admin` / `Demo1234!`).

Los correos de demostración usan **`example.com`**, el dominio reservado para
documentación (RFC 2606). No es un detalle estético: con «Verify email
addresses» activo —el valor por omisión— osTicket comprueba que el dominio
tenga MX o A/AAAA antes de aceptar un agente, y un dominio que no resuelve
(`demo.invalid`, por ejemplo) hace imposible crearlos: todos fallan con
*Valid email is required*. `example.com` resuelve y publica un MX nulo
(RFC 7505), así que nada sale hacia el exterior.

Si su instalación tiene el DNS restringido (una red de empresa, un contenedor
sin salida), use un dominio que resuelva en su entorno:

```bash
docker compose exec -T php php manage.php seed --only=agents --domain=empresa.local
```

O desactive la comprobación en **Admin Panel → Emails → Settings → Verify
email addresses**. El sembrador lo avisa con ese mismo texto si detecta que el
dominio no resuelve, en vez de fallar cinco veces seguidas.

### Estados y tareas: «todos» de verdad

Los estados de osTicket viven en la lista `ost_ticket_status` y son
personalizables, así que el sembrador **no** escribe nombres a mano: reparte
los tickets entre los estados que tenga la instalación, por su `state` interno.

| `state` | Estados habituales | Cómo se ve en la cola |
|---|---|---|
| `open` | Abierto · Nuevo | recién llegado, en cola |
| `in progress` | En progreso | alguien lo está mirando |
| `answered` | Contestado | el agente respondió y espera al cliente |
| `resolved` | Resuelto | con solución, reabrible |
| `closed` | Cerrado | con fecha de cierre |

`seedTickets()` agrupa los estados disponibles en dos bolsas —abiertos
(`open`, `in progress`, `answered`) y terminados (`resolved`, `closed`)— y va
tomando uno de cada bolsa para cada ticket, en orden. Un tercio de los tickets
abiertos queda **vencido** (`duedate` en el pasado e `isoverdue` a 1) para que
la cola de vencidos no esté vacía.

Las tareas no tienen esa variedad: `Task::getStatus()` sólo devuelve **Open**
o **Completed** (`flags` con el bit `ISOPEN` y la columna `closed`). Por eso
hay 16 tareas repartidas entre las dos, algunas con `duedate` vencida, otras
sin responsable, y ocho de ellas enganchadas a un ticket (`object_type` = `T`).

### El hilo de un ticket

Cada ticket recibe entre 2 y 14 mensajes, con fechas repartidas entre el día
siguiente a su apertura y hoy —o el día del cierre, si está cerrado: un ticket
cerrado hace tres semanas no puede tener una respuesta de ayer—:

- **respuesta del agente** (`postReply`), firmada con el agente asignado;
- **mensaje del cliente** (`postMessage`), como si respondiera por el portal;
- **nota interna** (`postNote`) cada cuatro mensajes, que el cliente no ve.

Las frases salen de `SeedManager::conversation()`, que devuelve dos listas (una
de agente y otra de cliente) y las va rotando; así dos tickets no tienen el
mismo texto y el hilo se lee como una conversación. Las fechas se ajustan
**después** de crear cada entrada (`create()` pone `NOW()`), así que el orden
del hilo es el orden real.

El reparto de días lo hace `SeedManager::threadDay()` —una función pura, con
pruebas—: del día `edad - 1` al día del cierre, sin saltos ni fechas
anteriores a la apertura. Importa para los informes: una respuesta fechada
antes de la apertura aparece como **tiempo de respuesta negativo**.

---

### Que los informes cuadren

Los datos de demostración no sólo tienen que existir: tienen que **medirse**
como si fueran reales. Eso obliga a tocar tres relojes que osTicket escribe
aparte, y los tres se ajustan aquí:

| Dónde | Quién lo escribe | Cómo queda |
|---|---|---|
| `ticket.created`, `ticket.closed`, `duedate` | `UPDATE` del sembrador | la fecha histórica |
| `thread_entry.created` | `create()` pone `NOW()` | la fecha de la entrada, **por su id** (no buscando el texto: en un hilo largo las frases se repiten y se movía la equivocada, y eso producía tiempos de respuesta negativos) |
| `thread_event.timestamp` (`created`, `closed`, `overdue`) | `logEvent()` pone `NOW()` | la misma fecha del hito |

Lo último importa porque el *Statistics* nativo (`/scp/reports.php`) se calcula
**sólo** con la tabla de eventos: si todos los eventos quedan en el instante del
sembrado, «Service Time» sale `0.0`, «Closed» sale `0` aunque haya tickets
cerrados y «Overdue» no aparece. El sembrador registra ahora el evento de
cierre (`Ticket::setStatus()` no funciona desde la CLI, el cierre es un
`UPDATE`) y el de vencimiento, y les pone su fecha.

El administrador de demostración recibe **todos** los permisos, como el que crea
el instalador: sin ellos `hasPerm('stats.agents')` dice que no y la pantalla de
analíticas se queda en «sólo ve sus departamentos», con el desglose por agente
vacío.

### Un departamento «Support» que no se creó aquí

El instalador de osTicket crea un departamento **Support** (en inglés) como
predeterminado. El sembrador no lo toca ni lo renombra; si en `Statistics`
aparece con tickets, son tickets cuyo tema no traía departamento y cayeron en el
predeterminado. Para ver cuáles:

```sql
SELECT t.number, t.dept_id, d.name AS dept, t.topic_id, h.topic
  FROM ost_ticket t
  LEFT JOIN ost_department d ON d.id = t.dept_id
  LEFT JOIN ost_help_topic h ON h.topic_id = t.topic_id
 WHERE d.name = 'Support';
```

---

## Volumen: `--count` y `--with-tasks`

El conjunto curado está pensado para **enseñar** la herramienta: 12 usuarios,
59 tickets y 16 tareas cuentan una historia y caben en una pantalla. Para
**medir** hace falta otra cosa: una cola con 40 tickets no dice nada del
rendimiento de una cola con 4.000, y una lista de usuarios de una página no
prueba la paginación.

`--count=N` completa el plan curado con registros generados hasta llegar a N,
en los tres pasos que tienen sentido repetir:

```bash
# 200 usuarios de cliente, sin tocar nada más
docker compose exec -T php php manage.php seed --only=users --count=200

# 100 tickets repartidos entre todos los estados, orígenes y agentes
docker compose exec -T php php manage.php seed --only=tickets --count=100

# 100 tareas sueltas (sin ticket): sirven para la lista de tareas y sus filtros
docker compose exec -T php php manage.php seed --only=tasks --count=100

# 50 tickets, cada uno con su tarea de seguimiento enlazada
docker compose exec -T php php manage.php seed --only=tickets --count=50 --with-tasks

# o todo el conjunto con volumen, en una sola pasada
docker compose exec -T php php manage.php seed --count=200
```

Lo generado es **determinista**: el mismo número produce siempre la misma
fila, con el número en el asunto (`Problema técnico (demostración 87)`), en el
título (`Tarea demostración 87`) o en el correo (`cliente87@example.com`). Por
eso una segunda ejecución reconoce lo que ya creó y no duplica: el sembrador
busca antes de crear, como siempre. `--count=0` es válido y no crea ninguno
del paso (útil para configurar sin llenar).

Las tareas generadas van **sueltas** a propósito: con `--only=tasks` no hay
tickets de los que colgarlas. Las que llevan ticket son las del plan curado
(que se enlazan si el ticket existe) y las de `--with-tasks`.

> Un `--count` con un paso que no se repite (`--only=topics --count=50`) no
> inventa cincuenta temas iguales: lo dice y sigue con el plan curado.

---

## Por qué no es un archivo `.sql`

Porque un `INSERT` a mano deja la base de datos en un estado que la aplicación
no entiende. Los motivos, por orden de importancia:

1. **Los campos del formulario no están en `ost_ticket`.** El asunto y el
   mensaje viven en `ost_form_entry_values`, y `ost_ticket__cdata` es una
   **vista** que el ORM construye a partir de ellos. Un `INSERT` en `ticket`
   sin su entrada de formulario crea un ticket que no se puede abrir.
2. **Los identificadores de formularios y campos dependen del instalador.**
   No son fijos: cambian según cómo se creó la instalación. Un `.sql` con
   `field_id = 3` funciona en una base y en otra escribe el campo equivocado.
3. **Cada ticket necesita su hilo, su primera entrada y su número de
   secuencia.** El número (`%06d`) sale de `ost_sequence`: si no se actualiza,
   el siguiente ticket que abra un cliente choca con el que sembraste.
4. **Los estados y las prioridades son listas internas**, con sus marcas y su
   orden; escribirlos a mano deja las colas sin ver los tickets.

El módulo usa los mismos métodos que el panel (`Dept::create()->update()`,
`Staff::create()->update()`, `Ticket::create()`, `postMessage()`,
`postReply()`), así que todo ese papeleo queda bien hecho. La contrapartida es
que **hay que ejecutarlo dentro de la aplicación**, y eso es lo que hace
`make seed`.

Si lo que quieres es un volcado para llevarte los datos a otro equipo:

```bash
# volcar SOLO los datos (sin el esquema), listo para recargar
docker compose exec -T db sh -c \
  'exec mysqldump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" \
   --no-create-info --complete-insert --skip-extended-insert' > datos.sql

# y para recargarlo en otra instalación del mismo esquema
docker compose exec -T db sh -c \
  'exec mysql -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' < datos.sql
```

Ese `datos.sql` sí es un `.sql` completo… generado desde una base que ya está
bien armada. Es lo que se puede versionar sin miedo; el sembrador es lo que lo
produce.

---

## Qué se puede deshacer, y cómo

`--fresh` borra **sólo** lo que creó el sembrador, y lo sabe por el manifiesto
`tools/dev/seed-manifest.json` (se genera solo, no se versiona). Las entradas de
la base de conocimiento se guardan con su tipo (`c3` categoría, `f3` artículo)
porque son dos tablas con numeración independiente: antes compartían número y
`--fresh` podía borrar el registro equivocado y dejar categorías sueltas. Nunca toca los
tickets que hayas abierto tú ni la configuración de la instalación.

```bash
make seed-fresh          # borra y vuelve a crear
```

La purga tiene **dos pasadas**:

1. **Por el manifiesto** (`tools/dev/seed-manifest.json`): los identificadores
   exactos de lo que creó el sembrador, de lo más dependiente a lo más básico,
   con el administrador como agente en curso (algunos modelos, como
   `Team::delete()`, devuelven `false` en silencio sin él). El manifiesto se
   escribe **después de cada registro**, no al final: si la ejecución se corta
   a mitad —un error de PHP, un `Ctrl-C`, un contenedor que se reinicia—, lo
   que ya se creó sigue apuntado y `--fresh` puede borrarlo.
2. **Por nombre**: sólo para los restos que dejaron versiones anteriores del
   sembrador (las que perdían el manifiesto). Se limita a lo que es
   **inequívocamente** suyo: los artículos y categorías de la base de
   conocimiento (sus preguntas y nombres sólo existen en el módulo) y los dos
   equipos de demostración.

La pasada por nombre **nunca toca al administrador de la casa** (se excluye por
id en la purga del manifiesto) y tampoco borra por nombre departamentos, SLA,
organizaciones, usuarios, tickets ni tareas: si usted tiene un departamento
«Soporte» o un SLA «Respuesta 8 h» propios, que se llamen igual que los de
demostración no los convierte en desechables. Esos se borran sólo por
identificador.

> **Si ya sembró con una versión anterior** (los correos eran
> `@demo.invalid`), ejecute `make seed-fresh` una vez: el dominio ha cambiado
> para que el alta de agentes pase la verificación de correos, y los registros
> viejos ya no se reconocen como duplicados.

Si quieres empezar de cero de verdad (base incluida):

```bash
make docker-reset        # borra el volumen de la base de datos
make docker-up           # vuelve a levantar
# y en el instalador: http://localhost:8080/setup/
```

---

## Cómo comprobar que salió bien

1. **Panel de agentes** → *Tickets*: 59 tickets, varios sin asignar, filtros
   por estado y departamento con contenido, y una cola de vencidos que no está
   vacía.
2. **Panel → Tareas**: tareas abiertas, completadas y dos vencidas; abra una
   ligada a un ticket y verá el enlace al ticket en la ficha.
3. **Dashboard**: actividad de los últimos días y tickets asignados a ti (con
   `admin`).
4. **Analíticas** (`/scp/analytics.php`): primera respuesta, resolución, SLA y
   antigüedad del pendiente, con series de más de un mes.
5. **Base de conocimiento** (`/kb/`): seis categorías, artículos destacados y
   el buscador con resultados (`/kb/faq.php?q=contraseña`).
6. **Portal del cliente** (`/open.php`): el desplegable de temas muestra los
   nueve públicos (los dos privados no aparecen).
7. **Un ticket con hilo largo**: abra «Quiero cancelar el servicio» y verá la
   conversación completa, con las respuestas del cliente, las del agente y las
   notas internas en orden.

---

## Comprobar que los datos cuadran (`make seed-doctor`)

El sembrador puede decir «59 tickets, 0 avisos» y el panel, aun así, mostrar
cosas raras: los informes cruzan tablas que el sembrador escribe por caminos
distintos (`thread_event` para *Statistics*, `ticket__cdata` para las colas,
`faq_category` para la base de conocimiento). Este comando revisa esas
relaciones y **no escribe nada**:

```bash
make seed-doctor
```

```
Revisión de los datos sembrados (no se crea ni se borra nada)

  ✓  tickets con hilo pero sin evento «created»
  ✓  tickets cerrados sin evento «closed»
  ✓  filas de evento de hilo sin hilo detrás
  ✗  entradas anteriores a su mensaje padre: 3
       → las respuestas quedaron antes que su mensaje padre:
         `make seed-repair` las recoloca detrás (o vuelva a sembrar)

Cifras
  tickets:           59
  abiertos:          33
  cerrados:          26
  eventos de hilo:   214
  artículos:         33
  categorías:        6
  agentes:           5

1 comprobación(es) con problemas.
```

Sale con error si algo no cuadra, así que sirve en un `make`. Qué significa cada
fallo:

| Revisión | Síntoma en el panel | Causa |
|---|---|---|
| sin evento «created» / «closed» | *Statistics* con `Service Time 0.0` y `Closed 0` | los eventos se quedaron con la fecha del sembrado o no se registraron |
| entradas antes que su padre | tiempos de respuesta **negativos** | se fechó la entrada equivocada |
| filas de hilo sin hilo | «Deleted N» a un agente que no borró nada | la purga borró tickets y dejó sus eventos |
| sin fila en `ticket__cdata` | la cola dice «about 37» y pinta menos filas, o dice 23 y no pinta ninguna | el ticket no está en la vista de datos: `make db-rebuild-views` |
| artículos sin categoría | artículos que no salen en `/kb/` | categoría borrada, artículo huérfano |
| departamento ≠ tema | tickets en «Support» | el tema manda: se creó sin departamento |

La purga de `--fresh` limpia ahora las filas de hilo sin dueño (sólo huérfanas:
lo que tiene ticket detrás no se toca).

### Reparar sin volver a sembrar (`make seed-repair`)

`make seed-doctor` sólo mira. Lo que tiene una reparación sin pérdida se
arregla con:

```bash
make seed-repair        # docker compose exec -T php php manage.php seed --repair
```

Hace cuatro cosas y **no borra ni crea registros**:

1. **Artículos sin categoría**: se recolocan por la pregunta exacta del
   manifiesto (`SeedManager::faqs()`), en la categoría que les toca. Un
   artículo que no está en el manifiesto no se toca.
2. **Entradas fechadas antes que su mensaje padre**: se recorren los hilos y
   cada respuesta se coloca detrás de su padre, separando un minuto a los
   hermanos y respetando el orden de escritura (por id). Después se recalcula
   `thread.lastmessage`, que es lo que ordena «Last Updated».
3. **Restos de hilo sin dueño**: los mismos que limpia `--fresh`, pero sólo
   los huérfanos.
4. **Vistas de datos de los formularios**: se reconstruyen
   (`DynamicForm::rebuildDynamicDataViews()`), que es lo que hace
   `make db-rebuild-views`.

Cada paso imprime **cuántos elementos ha mirado y cuántos ha tocado**, además
de cuántos artículos hay en la base y a qué categorías apuntan:

```
  · 33 artículo(s) del manifiesto: 0 en la base, 0 ya en su categoría,
    0 recolocados
  · 33 artículo(s) en la base; apuntan a las categorías: 0
  · 131 entrada(s): 0 con mensaje padre, 0 antes de su padre, 0 recolocadas
```

Esas cifras distinguen los tres casos que antes se veían igual («0
recolocados»): que los artículos no se encuentren por su pregunta, que ya
estén en su sitio, o que apunten a una categoría que no existe.

Después de reparar se pasa la misma revisión, así que la salida termina en
«Todo cuadra.» cuando ya no queda nada. Lo que **no** repara —un ticket sin
evento de creación o de cierre, o un departamento que el administrador no
ve— son altas que sólo hace el sembrador: eso es `make seed` (o dar el acceso
desde el panel).

### Que el administrador vea lo sembrado

El sembrador crea departamentos y reparte los tickets entre ellos. Las colas,
en cambio, aplican la **visibilidad del agente**: `dept_id IN (sus
departamentos) OR asignado OR referido`, y esos departamentos salen de
`staff_dept_access`, no de «es administrador». Si al administrador de la
instalación no se le da acceso a los departamentos nuevos, los tickets existen,
`Statistics` los cuenta y las colas no los listan.

Por eso el paso `depts` termina llamando a `grantAdminDepartments()`: añade al
administrador que ya existía (sin tocarlo, sin crear otro) las filas que
faltan en `staff_dept_access`. Es idempotente y no cambia contraseñas ni
permisos. Después de sembrar, la salida lo dice:

```
· acceso del administrador d.martinez a: Soporte N2, Facturación
```

`make seed-doctor` lo revisa con la comprobación *tickets en departamentos que
el administrador no ve*.

### La vista de datos de los tickets

`ost_ticket__cdata` no es una tabla normal: es una **copia derivada** de
`form_entry_values` que se construye con `CREATE TABLE … AS SELECT` y luego
sólo se actualiza al guardar respuestas. Un ticket que se crea cuando la vista
ya existe puede quedarse fuera, y entonces no sale en **ninguna** cola: las
columnas (Subject, entre otras) hacen un JOIN interno contra ella, mientras el
recuento, que no usa columnas, lo sigue contando.

Desde esta versión el sembrador la reconstruye al terminar (`refreshTicketView()`,
vía `DynamicForm::rebuildDynamicDataViews()`), así que un `make seed` completo
deja los 59 tickets dentro. Para comprobarlo o arreglarlo por separado:

```bash
make db-views           # filas, filas esperadas y aviso por tabla
make db-rebuild-views   # reconstruye las cuatro vistas desde form_entry_values
make seed-doctor        # incluye la comprobación «sin fila en ticket__cdata»
```

---

## Si un paso falla

Cada paso está aislado: un error se informa, los demás siguen, y al final el
comando dice cuáles fallaron y **sale con error** (código 1, así que un
`make seed` en un script se detiene). Lo normal es corregir y repetir sólo ese
paso:

```bash
docker compose exec -T php php manage.php seed --only=topics --verbose
```

Cada paso termina con un resumen, para que no haya que adivinar si hizo algo:

```
[topics]
  + Problema técnico (#4)
  + Facturación y pagos (#5)
  6 creado(s), 0 ya existía(n)
```

Un `AVISO — …` significa que **ese** registro no se creó, y siempre trae el
motivo. Cuando el modelo no guarda, `save()` de osTicket devuelve `false` y se
traga el error del motor («Internal error occurred»); el sembrador lo rescata
(`VerySimpleModel::getLastError()`) y lo imprime detrás:

```
  AVISO — no se pudo crear el tema Problema técnico: err · Unable to create
  this help topic. Internal error occurred — Unable to prepare query: Unknown
  column 'isactive' in 'field list' INSERT INTO ost_help_topic SET ...
```

### Dónde mirar cuando algo falla

| Sitio | Qué hay |
|---|---|
| La propia salida del comando | El aviso con el error del motor y el SQL que falló (desde la versión actual) |
| `docker compose exec -T php php manage.php seed --only=PASO -v` | Repite un solo paso y muestra cada registro creado o saltado |
| **Panel → Logs** (`/scp/logs.php`) | Cada error de base de datos que osTicket registra, con la consulta completa |
| `SELECT log_type, title, log, created FROM ost_syslog ORDER BY log_id DESC LIMIT 10;` | Lo mismo desde SQL |
| `docker compose logs php` | Avisos y errores de PHP (incluido «Undefined array key» y los de correo) |

El orden de los pasos ya tiene en cuenta las dependencias; si ejecuta uno solo,
los pasos anteriores tienen que estar hechos.

### «Ya existía» en todos los pasos

Si `make seed` dice `0 creado(s), N ya existía(n)` para casi todo, hay restos de
una ejecución anterior. Con esta versión ya no se acumulan (el manifiesto se
apunta registro a registro), pero si vienen de una versión anterior:

```bash
make seed-fresh          # los borra: conocimiento y equipos, por nombre
make seed-fresh          # y esta vez sí los crea
```

Dos ejecuciones seguidas de `seed-fresh` son inofensivas: la segunda no
encuentra restos.

Si lo que sobra son **tickets, tareas, usuarios o departamentos** de una
ejecución antigua, la purga no los toca a propósito (podrían ser suyos, con el
mismo nombre). Bórrelos desde el panel —*Tickets* → seleccionar → *Eliminar*—
o empiece de cero con `make docker-reset`, que borra el volumen de la base.

Si prefiere verlo antes, `--verbose` marca cada registro con `+` (creado) o
`=` (ya existía) y dice su nombre, así que se puede comprobar cuál sobra.
