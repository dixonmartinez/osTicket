# Datos de demostración: `make seed`

Carga un osTicket recién instalado con contenido parecido al de un help desk en
funcionamiento: cuatro departamentos, cinco agentes, dos equipos, dos SLA, seis
temas, cinco respuestas predefinidas, tres categorías de base de conocimiento
con ocho artículos, doce clientes en tres organizaciones y **treinta tickets**
repartidos entre abiertos, cerrados, asignados y sin asignar, con respuestas en
el hilo y antigüedad variada.

Sirve para tres cosas concretas: ver cómo se comporta la interfaz con datos
reales (listas, filtros, paginación, modo oscuro), tener algo que mirar en el
panel de analíticas, y revisar plantillas de correo con tickets de verdad.

```bash
make seed            # crea todo (no duplica si ya existe)
make seed-fresh      # borra lo que creó y lo vuelve a crear
make seed-check      # lista los pasos disponibles
```

Todo se ejecuta **dentro del contenedor** (no hace falta PHP en tu equipo):

```bash
docker compose exec -T php php manage.php seed            # lo mismo que make seed
docker compose exec -T php php manage.php seed --verbose   # ver cada registro
docker compose exec -T php php manage.php seed --only=topics
docker compose exec -T php php manage.php seed --count=60  # más tickets
docker compose exec -T php php manage.php seed --no-tickets
```

| Opción | Qué hace |
|---|---|
| `--fresh` | borra lo creado por una ejecución anterior (según el manifiesto) y vuelve a crear |
| `--only=PASO` | ejecuta un solo paso (`orgs`, `users`, `agents`, `depts`, `teams`, `slas`, `topics`, `canned`, `faq`, `tickets`) |
| `--count=N` | cuántos tickets crear (por omisión 30) |
| `--no-tickets` | deja la configuración pero ningún ticket |
| `--list` | muestra los pasos |
| `--verbose` | muestra cada registro creado |
| `--quiet` | sólo avisa de los errores |

---

## Qué se crea

| Paso | Contenido |
|---|---|
| `orgs` | Acme S.A., Globex Corporation, Initech (con su dominio, para que los usuarios se asignen solos) |
| `users` | 12 clientes repartidos entre las tres organizaciones, con teléfono |
| `agents` | `admin` (administrador), `supervisor`, `agente1`, `agente2`, `agente3` — todos con acceso a los cuatro departamentos |
| `depts` | Soporte · Soporte Técnico (hijo) · Facturación (hijo) · Ventas |
| `teams` | Turno de noche · Escalado (con líder y miembros) |
| `slas` | Respuesta 8 h · Respuesta 24 h (24/7, sin horario asignado) |
| `topics` | Problema técnico · Facturación y pagos · Solicitud de información · Alta de usuario · Reclamación · Incidente interno (este último privado) |
| `canned` | Saludo inicial · Petición de información · Ticket resuelto · Escalado a segundo nivel · Factura duplicada |
| `faq` | Primeros pasos · Cuenta y acceso · Facturación, con 8 artículos publicados y destacados |
| `tickets` | 30 tickets con antigüedad de 0 a 42 días, respuestas de cliente y de agente en el hilo, cerrados con su fecha, asignados o pendientes de asignar |

**Credenciales**: usuario `admin`, contraseña `Demo1234!` (la misma para todos
los agentes y para las cuentas de cliente). Los correos usan el dominio
`demo.invalid`, que no existe: si algo intenta enviarles correo, rebota en el
acto y no molesta a nadie.

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
`tools/dev/seed-manifest.json` (se genera solo, no se versiona). Nunca toca los
tickets que hayas abierto tú ni la configuración de la instalación.

```bash
make seed-fresh          # borra y vuelve a crear
```

Si quieres empezar de cero de verdad (base incluida):

```bash
make docker-reset        # borra el volumen de la base de datos
make docker-up           # vuelve a levantar
# y en el instalador: http://localhost:8080/setup/
```

---

## Cómo comprobar que salió bien

1. **Panel de agentes** → *Tickets*: 30 tickets, varios sin asignar, filtros
   por estado y departamento con contenido.
2. **Dashboard**: actividad de los últimos días y tickets asignados a ti (con
   `admin`).
3. **Analíticas** (`/scp/analytics.php`): primera respuesta, resolución, SLA y
   antigüedad del pendiente, con series de más de un mes.
4. **Base de conocimiento** (`/kb/`): tres categorías con artículos
   destacados.
5. **Portal del cliente** (`/open.php`): el desplegable de temas muestra los
   seis (el privado no aparece).

---

## Si un paso falla

Cada paso está aislado: un error se informa, los demás siguen, y al final el
comando dice cuáles fallaron y con qué mensaje. Lo normal es corregir y repetir
sólo ese paso:

```bash
docker compose exec -T php php manage.php seed --only=agents --verbose
```

Los avisos (`AVISO — …`) no detienen nada: significan que ese registro concreto
no se creó, casi siempre porque ya existía o porque falta algo de lo que
depende (por ejemplo, `topics` necesita los departamentos). El orden de los
pasos ya lo tiene en cuenta.
