# Correo en desarrollo: Mailpit

`Mailpit` es un buzón falso: **captura** todo lo que osTicket envía (avisos de
ticket, respuestas automáticas, restablecer contraseña) y lo muestra en una
interfaz web, sin mandar nada fuera de tu equipo y sin configurar una cuenta de
correo real. Lo levanta `docker compose` con el resto del entorno.

- Interfaz web: **http://localhost:8025** (`MAILPIT_UI_PORT` en `.env`)
- SMTP para osTicket: **host `mailpit`, puerto `1025`**, sin TLS y sin usuario

```bash
make mail        # recuerda estas URLs y los datos SMTP
make docker-up   # si aún no está levantado
```

> `localhost:1025` **no** es lo que hay que poner en osTicket: el panel corre
> dentro del contenedor `php`, y desde ahí el buzón se llama `mailpit` (el
> nombre del servicio en `compose.override.yaml`). Usa `localhost` sólo si
> configuras un cliente de correo en tu equipo.

---

## 1. Comprobar que el buzón recibe correo

Antes de tocar osTicket, separa los dos problemas posibles:

```bash
make test-mail
```

Eso envía un mensaje al buzón hablando SMTP directamente (script
`tools/dev/mail-test.php`), sin pasar por la aplicación. Abre
http://localhost:8025 y busca el asunto con el marcador `ost-xxxx`.

- **Aparece** → el buzón está bien; si osTicket no manda correo, el problema
  está en la cuenta de correo del panel (paso 2).
- **No aparece o da error de conexión** → revisa `docker compose ps` y
  `docker compose logs mailpit`. El script explica qué comprobar.

---

## 2. Configurar la cuenta de correo en osTicket

Panel de agentes → **Admin** → **Emails** → **Emails** → **Add New Email**.

### Pestaña *Email*

| Campo | Valor |
|---|---|
| Email Address | `soporte@example.com` (reservado para documentación; con la verificación de correos activa hay que usar un dominio que resuelva) |
| Email Name | `Soporte` |
| Department | el que quieras (p. ej. *Soporte*) |

El resto de plantillas se puede dejar por omisión.

### Pestaña *Mailbox* (correo entrante)

Mailpit **no tiene IMAP ni POP**: sólo acepta SMTP. Por eso esta pestaña se
deja **desactivada**:

| Campo | Valor |
|---|---|
| Status | **Disable** |

Con eso osTicket no intenta recoger correo cada 5 minutos (y no llena el log
con «no se pudo conectar al buzón»). Para probar el correo entrante hay un
camino que sí funciona: el paso 4.

### Pestaña *SMTP* (correo saliente)

| Campo | Valor |
|---|---|
| Status | **Enable** |
| Hostname | `mailpit` |
| Port | `1025` |
| Authentication | **None — No Authentication Required** |
| TLS | desmarcado (`MP_SMTP_AUTH_ALLOW_INSECURE=1` ya permite el envío en claro) |

Pulsa **Save**. En la lista de cuentas debe quedar marcada como *Active* y ser
la del departamento que atiende el ticket.

> Si el botón de guardar devuelve un error de conexión, casi siempre es el
> **hostname**: `localhost` dentro del contenedor no es tu equipo. Es
> `mailpit`.

### Comprobación rápida desde la propia aplicación

- Abre un ticket desde el portal (`/open.php`) con un correo cualquiera: la
  respuesta automática debe llegar a Mailpit.
- O usa la **prueba de correo** del panel: Admin → Emails → la cuenta →
  pestaña de diagnóstico / `scp/emailtest.php` (`/scp/emailtest.php`).

---

## 3. Qué mirar en Mailpit

| Zona | Para qué sirve |
|---|---|
| Bandeja | todos los correos capturados, con remitente y asunto |
| *HTML* / *Text* | cómo verá el cliente el mensaje (útil para revisar plantillas) |
| Cabeceras | `Message-ID`, `In-Reply-To` y `References`: es lo que enlaza una respuesta con su ticket |
| *Download .eml* | guarda el mensaje original: sirve para el paso 4 |
| *Send to* / *Forward* | reenvía el mensaje a otra dirección SMTP |

No hay límite de correos, y **no se envían a nadie**: el buzón es local.

---

## 4. Probar el correo **entrante** (respuestas a un ticket)

osTicket también crea tickets y añade respuestas **desde** un buzón. Como
Mailpit no ofrece IMAP, se usa la vía de *piping*, que es la misma que usa un
servidor de correo real con `pipe`:

1. En Mailpit, abre un mensaje y pulsa **Download .eml**.
2. Déjalo en el contenedor y pásalo por el *pipe* de osTicket:

```bash
docker compose cp mensaje.eml php:/tmp/mensaje.eml
docker compose exec -T php php api/pipe.php < /tmp/mensaje.eml
```

El ticket aparece (o se actualiza el hilo, si la cabecera `In-Reply-To`
apunta a una respuesta anterior). Es exactamente lo que haría el servidor de
correo al entregar el mensaje.

> Alternativa: `POST /api/tickets.email` (`api/http.php`) acepta un correo en
> crudo por HTTP. Es la vía para un servidor de correo externo, no hace falta
> en desarrollo.

### Cómo se llaman los adjuntos que llegan

El nombre del archivo se toma de la cabecera que lo traiga, en este orden:

1. `Content-Disposition: … filename="factura.pdf"`
2. `filename*` —la forma de la RFC 5987, que admite acentos y espacios
   codificados: `filename*=utf-8''informe%20raro.pdf` llega como
   `informe raro.pdf`—, y también la forma partida `filename*0=`, `filename*1=`…
3. `Content-Type: … name="logo.png"` (y `name*`), que usan los clientes que no
   ponen disposición.

Si el adjunto es una **imagen incrustada** y no trae ningún nombre —Lotus Notes
y Domino lo hacían—, se guarda como `image-XXXX.gif`, con cuatro caracteres al
azar y la extensión de su tipo. Un `message/rfc822` adjunto se guarda como
`.eml` y toma el nombre del asunto del mensaje que lleva dentro.

Esa decisión vive en `include/class.mail.attachment.php` (`MailAttachment`) y
tiene pruebas con correos MIME de verdad en `tests/MailAttachmentTest.php`, así
que un cliente raro se puede reproducir sin esperar a que escriba.

---

## 5. Tareas en segundo plano (avisos de vencimiento, cierre automático)

Los avisos de SLA y el cierre por inactividad no los manda el navegador: los
dispara el cron de osTicket. En desarrollo:

```bash
docker compose exec -T php php api/cron.php
```

Los correos que genere aparecen igual en Mailpit.

El servicio `cron` existe en `compose.yaml`, pero está en el perfil `tools`: no
arranca con `docker compose up -d`. Para tenerlo corriendo cada 5 minutos:

```bash
docker compose --profile tools up -d cron
docker compose logs -f cron        # ver cada pasada
```

En el equipo de cada uno es lo normal dejarlo apagado y lanzar el comando a
mano cuando se prueban avisos de vencimiento.

---

## 6. Problemas típicos

| Síntoma | Causa | Solución |
|---|---|---|
| `No se pudo conectar a mailpit:1025` | el servicio no está arriba | `docker compose up -d mail` (el servicio se llama `mail`; `mailpit` es un alias de red) |
| `getaddrinfo for mailpit failed` al ejecutar `make test-mail` **en el equipo** | `mailpit` sólo existe dentro de la red de Docker | `make test-mail` ya ejecuta el script **dentro** del contenedor; si lo lanzas a mano, usa `docker compose exec -T php php tools/dev/mail-test.php` |
| El puerto 8025 no responde | otro proceso lo ocupa o cambiaste `MAILPIT_UI_PORT` | mira `.env` y `make docker-urls` |
| osTicket dice «Unable to connect to SMTP» | hostname `localhost` en vez de `mailpit` | cámbialo en la cuenta de correo |
| `Fallo en «EHLO»` / `esperado 250, recibido 220` | el script no leía el saludo `220` del servidor (corregido) | actualice el repositorio; si lo ve en otro cliente, lea primero el saludo |
| `sendmail: can't connect to remote host (127.0.0.1)` en la cola de correo | la cuenta apunta a `127.0.0.1`, que **dentro del contenedor** es el propio contenedor | Admin → Emails → la cuenta → *Hostname*: `mail`, puerto `1025` (sin TLS) |
| No llega la respuesta automática | la cuenta no está activa o el departamento usa otra | Admin → Emails: debe estar *Active*; revisa el departamento del tema |
| Llega todo a la papelera de Mailpit | reglas de Mailpit (`MP_*`) | interfaz → *Settings* |
| Los correos salen sin formato | el mensaje viaja como texto | es normal en avisos internos; revisa la pestaña *HTML* |

---

## 7. Cómo se relaciona con los datos de demostración

`make seed` (ver `docs/SEED.md`) crea la cuenta de correo **no** la crea: a
propósito. La cuenta SMTP describe *tu* entorno y cambia entre equipos, así
que se configura a mano una vez (paso 2). Lo que sí crea son departamentos con
sus plantillas, temas, agentes y treinta tickets, para que al enviar el primer
aviso haya algo real que notificar.

Si sólo quieres ver correos sin configurar nada, `make test-mail` es el atajo.
Envía un mensaje con asunto y marcador propios y lo deja en la bandeja, así que
sirve para separar dos fallos que se parecen mucho:

* si **el correo de prueba llega** y el de osTicket no, el buzón está bien y lo
  que falta es la cuenta de correo (paso 2) o la dirección del departamento;
* si **no llega ni el de prueba**, el problema es del contenedor `mail` o de la
  red, no de osTicket.

El script habla SMTP a pelo, sin depender de osTicket. Se ejecuta dentro del
contenedor de PHP porque ahí sí resuelve el nombre `mailpit` (alias del servicio
`mail`); en Windows/WSL, lanzado desde el equipo, ese nombre no existe.

---

## 8. Cuando ya no lo necesites

```bash
docker compose stop mail        # deja de capturar, sin borrar nada
docker compose up -d mail       # vuelve a arrancarlo
```

Mailpit guarda los mensajes en memoria: al reiniciar el contenedor, la bandeja
empieza vacía. No hay nada que limpiar en el repositorio.
