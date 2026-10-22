# osTicket con Docker

Todo el proyecto corre en contenedores: **PHP, MySQL/MariaDB, nginx, herramientas
de calidad y el depurador**. No hace falta instalar PHP, Composer, MySQL ni
Xdebug en tu PC. Lo único que necesitas es **Docker Desktop** (Windows/macOS) o
**Docker Engine + Compose v2** (Linux) y **VS Code**.

> **Antes de nada, sobre la vulnerabilidad del 21 de octubre:** la imagen usa
> `PHP_VERSION=8.4.27`, una de las versiones con el parche de *php2shell*
> (CVE-2026-109618 / CVE-2026-109619). No bajes la versión. Los detalles están
> en [`SECURITY_PHP2SHELL.md`](SECURITY_PHP2SHELL.md).

---

## 1. Arranque en dos comandos

```bash
make docker-up
```

La primera vez tarda unos minutos (compila las extensiones de PHP e instala
Xdebug). Después:

| Servicio | URL | Para qué |
|---|---|---|
| Portal del cliente | <http://localhost:8080> | Abrir tickets, consultar estado |
| Panel de agentes | <http://localhost:8080/scp/> | Trabajar los tickets |
| Instalador | <http://localhost:8080/setup/> | Sólo la primera vez |
| Mailpit | <http://localhost:8025> | Ver los correos que envía osTicket |
| MariaDB | `127.0.0.1:3307` | Cliente SQL desde el host (opcional) |

Las URLs salen de `HTTP_PORT` en `.env` (8080 por defecto). Si **el 8080 está
ocupado**, cambia el valor allí, recrea el contenedor de nginx y consulta las
URLs reales:

```bash
# edita HTTP_PORT en .env y luego:
docker compose up -d nginx
make docker-urls
```

`make docker-up` imprime al terminar el puerto que está usando de verdad, así
que ese mensaje es la referencia fiable.

Si `make` no está disponible (Windows sin WSL), los comandos equivalentes son:

```bash
cp .env.example .env
docker compose up -d --build
```

El archivo `.env` guarda credenciales y está en `.gitignore`. **Cámbialo** antes
de usar el entorno para algo más que pruebas locales:

```bash
# Linux/macOS
sed -i "s|^OST_SECRET_SALT=.*|OST_SECRET_SALT=$(openssl rand -hex 32)|" .env
```

En Windows (PowerShell):

```powershell
(Get-Content .env) -replace '^OST_SECRET_SALT=.*',
  "OST_SECRET_SALT=$(-join ((48..57)+(97..102) | Get-Random -Count 64 | % {[char]$_}))" |
  Set-Content .env
```

## 2. Instalación de osTicket

La primera vez hay que crear las tablas. Dos caminos:

**A. Asistente web (recomendado la primera vez)**

1. Abre <http://localhost:8080/setup/>.
2. Rellena los datos de la base. **El formulario ya llega relleno** con los
   valores de `.env`, así que normalmente basta con pulsar *Install Now*:
   * Host: `db` — **nunca `localhost`**
   * Base de datos: `osticket`
   * Usuario: `osticket` (no `root`)
   * Contraseña: `osticket` (o la que hayas puesto en `.env`)
3. Crea el administrador y termina.

El asistente pedirá quitar el permiso de escritura de `include/ost-config.php`.
El contenedor ya lo crea en `0640`: compruébalo (y corrígelo si hace falta) con
`make perms`.

> `localhost` dentro del contenedor `php` es el propio contenedor: no hay
> ningún MySQL escuchando ahí y la conexión falla con «No such file or
> directory». La base vive en el servicio `db` de la red de compose.

**B. Preparar la base por consola**

```bash
make docker-install        # crea la base si el volumen ya existía
# o, para que se haga solo en cada arranque:
OST_INSTALL_DB=1           # en .env
```

El contenedor genera `include/ost-config.php` automáticamente a partir de las
variables de entorno (`OST_DB_*`), así que no hay que editarlo a mano ni
versionarlo.

Cuando la instalación termine, **bloquea `/setup/`** descomentando el bloque
*Producción* de `docker/nginx/default.conf` y reiniciando nginx:

```bash
docker compose restart nginx
```

Al entrar en el panel, osTicket avisa de que `setup/` sigue ahí. El aviso ya no
se basa en que la carpeta exista, sino en que **responda por HTTP**: se pide
`/setup/install.php` al servicio `nginx` y, si contesta 404 o 403, el aviso
desaparece. Así no hay que borrar código versionado (borrarlo llenaría
`git status` de archivos eliminados).

| Variable | Por defecto | Para qué |
|---|---|---|
| `OST_INTERNAL_URL` | `http://nginx` | A quién se pregunta si `/setup/` responde |
| `OST_SETUP_LOCKED` | `0` | `1` = darlo por bloqueado sin comprobar (WAF, proxy, basic auth) |

Comprobación manual: `docker compose exec php php tools/security/setup-probe.php`
(o `make docker-security`, que además revisa php2shell).

No borres `setup/` con `rm -rf`: es código versionado y ensuciaría el
repositorio. El instalador ya se niega a reinstalar mientras exista
`include/ost-config.php`.

## 3. Depurar con Xdebug desde VS Code

VS Code se queda en tu equipo; **no hay PHP que instalar**. El contenedor
`php` lleva Xdebug y abre la sesión hacia tu equipo en el puerto `9003`, así
que se depura sin Dev Container ni «Reopen in Container».

```bash
make docker-up
# F5 → "Xdebug: osTicket (web)"  y navega a http://localhost:8080
```

El flujo completo —qué lanzador usar en cada caso, depurar scripts de consola,
pruebas, extensiones y problemas frecuentes— está en
**[`VSCODE.md`](VSCODE.md)**.

### 3.1 Variables de Xdebug

| Variable | Por defecto | Significado |
|---|---|---|
| `XDEBUG_MODE` | `develop,debug` | `off` para máxima velocidad, `coverage` para cobertura |
| `XDEBUG_CLIENT_HOST` | `host.docker.internal` | A quién conecta Xdebug: tu equipo, donde escucha VS Code |
| `XDEBUG_CLIENT_PORT` | `9003` | Puerto del IDE |
| `XDEBUG_TRIGGER` | vacío | Con `start_with_request=trigger` activa la sesión sólo con `?XDEBUG_SESSION=1` |
| `PHP_IDE_CONFIG` | `serverName=osticket.local` | Nombre de servidor para configuraciones con *path mapping* |

Los valores están en `.env` y en `docker/php/conf.d/90-xdebug.ini`.

> `.vscode/settings.json` desactiva `php.validate` a propósito: no hay PHP en
> el equipo. La validación la hace Intelephense y la ejecución va por Docker.

El *path mapping* ya está resuelto en `.vscode/launch.json`:
`/var/www/html` → `${workspaceFolder}`.

## 4. Tareas y lanzadores incluidos

`.vscode/tasks.json` (*Terminal → Run Task*, o `Ctrl+Shift+P` → *Tasks: Run
Task*; `Ctrl+Shift+B` ejecuta directamente *Docker: up (dev)*):

| Tarea | Qué hace |
|---|---|
| Docker: up (dev) | Levanta todo y muestra el estado |
| Docker: down | Detiene sin borrar datos |
| Docker: reset (borra datos) | Elimina también el volumen de MySQL |
| Docker: logs (nginx + php) | Sigue los logs en una terminal dedicada |
| Docker: shell en php | `sh` dentro del contenedor |
| Docker: shell en db | Cliente `mariadb` dentro del contenedor |
| Seguridad: comprobar php2shell | Ejecuta `tools/security/php-cve-check.php` |
| Calidad: make ci | Lint + auditoría + comprobación de PHP |
| Calidad: pruebas del proyecto | `setup/test/run-tests.php` |
| Datos: crear base de datos si falta | `docker/php/setup-db.php` |
| Datos: URLs del entorno | `make docker-urls`: direcciones con el puerto de `.env` |

`.vscode/launch.json`: cuatro configuraciones — web, web con traza de
diagnóstico, script CLI y la suite de pruebas, todas lanzadas dentro del
contenedor mediante `docker compose`. Cuál usar en cada caso:
[`VSCODE.md`](VSCODE.md) § 5.

`.vscode/http/osticket.http`: ejemplos listos para la extensión *REST Client*
(crear ticket por JSON y XML, consultar configuración pública, ver Mailpit).

## 4.1 Extensiones de VS Code

El repositorio ya las declara en `.vscode/extensions.json`: al abrir la carpeta,
VS Code muestra un aviso con **“Install all”**.

### Imprescindibles

| Extensión | ID | Para qué |
|---|---|---|
| PHP Debug | `xdebug.php-debug` | **Necesaria** para los lanzadores de `launch.json`. Sin ella, `F5` no existe |
| Intelephense | `bmewburn.vscode-intelephense-client` | Autocompletado, ir a la definición, errores de tipo. Los directorios de terceros ya están excluidos en `.vscode/settings.json` |
| EditorConfig | `EditorConfig.EditorConfig` | Respeta `.editorconfig` (tabs, fin de línea, charset) |

### Muy recomendables

| Extensión | ID | Para qué |
|---|---|---|
| Docker | `ms-azuretools.vscode-docker` | Ver contenedores, logs y abrir shell desde la barra lateral. **Solo en el host**, no dentro del contenedor |
| SQLTools + driver MySQL | `mtxr.sqltools` + `mtxr.sqltools-driver-mysql` | Consultar la base. La conexión `osTicket (dev)` ya está configurada en `.vscode/settings.json` |
| YAML | `redhat.vscode-yaml` | Valida `compose.yaml` mientras lo editas |
| REST Client | `humao.rest-client` | Ejecuta `.vscode/http/osticket.http` (crear tickets por API) |
| Prettier | `esbenp.prettier-vscode` | Formatea YAML, JSON y Markdown. **No PHP**: no se incluye `@prettier/plugin-php` |

### Opcionales según lo que hagas

| Extensión | ID | Cuándo |
|---|---|---|
| Composer | `neilbreatnham.composer` | Si editas `composer.json`. Innecesaria dentro del contenedor |
| Markdown All in One | `yzhang.markdown-all-in-one` | Si mantienes la documentación de `docs/` |
| Code Spell Checker | `streetsidesoftware.code-spell-checker` | Erratas en comentarios y guías (añade el diccionario español) |
| GitLens | `eamodio.gitlens` | Ver quién cambió cada línea del núcleo |
| SonarLint | `sonarsource.sonarlint-vscode` | Reglas estáticas adicionales para PHP |

### Lo que **no** conviene instalar

| Extensión | Motivo |
|---|---|
| `felixfbecker.php-debug` | Deprecada; su sustituta es `xdebug.php-debug`. Está en `unwantedRecommendations` |
| `felixfbecker.php-intellisense` | Deprecada y en conflicto con Intelephense |
| DEVSENSE *PHP Tools* junto a Intelephense | Dos servidores de lenguaje PHP sobre el mismo proyecto: autocompletado duplicado y errores fantasma. Elige uno |
| Cualquier formateador de PHP | El proyecto no fija estilo automático en PHP; `phpcs.xml.dist` es la referencia y se ejecuta en el contenedor (`make docker-qa`) |

## 5. Comandos del día a día

```bash
make docker-up          # arrancar (imprime las URLs con el puerto real)
make docker-urls        # ver las URLs según .env
make docker-down        # parar (los datos se conservan)
make docker-logs        # seguir logs
make docker-shell       # shell en PHP
make docker-build       # reconstruye la imagen (Dockerfile o conf.d/*.ini)
make docker-test        # lint + auditoría dentro del contenedor
make docker-security    # comprobar la versión de PHP
make docker-reset       # borrar la base y empezar de cero
make docker-prod        # construir la imagen de producción
```

Sin `make`:

```bash
docker compose up -d --build
docker compose logs -f nginx php
docker compose exec php sh
docker compose down          # conserva datos
docker compose down -v       # borra datos
```

## 6. Estructura de los archivos de Docker y del entorno de VS Code

```
compose.yaml                 entorno de desarrollo (nginx + php + db + mailpit)
compose.override.yaml        se carga solo en desarrollo (Mailpit, nombre de servidor Xdebug)
compose.prod.yaml            overlay de producción
.env.example                 plantilla de variables (copiar a .env)
.dockerignore                contexto mínimo de construcción
docker/
  php/
    Dockerfile               etapas base / dev (Xdebug) / prod (OPcache)
    docker-entrypoint.sh     PUID/PGID, comprobación de php2shell, espera de BD, ost-config.php
    setup-db.php             crea la base y el usuario si el volumen ya existía
    conf.d/
      00-osticket.ini        endurecimiento común
      10-dev.ini             OPcache con revalidación
      10-prod.ini            OPcache + JIT inmutable
      90-xdebug.ini          Xdebug 3
  nginx/default.conf         server block, FastCGI, bloqueo de rutas internas
  mysql/init/01-charset.sql  ajustes iniciales de la base
.vscode/
  launch.json                lanzadores de Xdebug (web, CLI, pruebas)
  tasks.json                 tareas de Docker, calidad y datos
  settings.json              Intelephense, exclusiones, php.validate apagado
  extensions.json            extensiones recomendadas
  http/osticket.http         peticiones de ejemplo (REST Client)
```

## 7. Producción

```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
```

Qué cambia:

* Se usa la etapa `prod`: sin Xdebug, OPcache con JIT y sin revalidar
  timestamps, y **el código va copiado dentro de la imagen** (nada de montar el
  repositorio).
* No se publica el puerto de MySQL ni el de Mailpit.
* Reinicio automático y rotación de logs (10 MB × 3).
* Se elimina `compose.override.yaml` de la ecuación: no hay correo de pruebas.

Antes de exponerlo a Internet:

1. `OST_SECRET_SALT` con 32+ caracteres aleatorios.
2. Contraseñas de base de datos fuertes (`MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`).
3. `OST_FAIL_ON_VULNERABLE_PHP=1` (por defecto) y una `PHP_VERSION` con parche.
4. Terminar el TLS en un proxy delante de nginx (Caddy, Traefik o el balanceador
   del proveedor) y activar HSTS. osTicket detecta HTTPS por las cabeceras
   reenviadas.
5. Bloquear `/setup/` después de instalar (bloque *Producción* de
   `docker/nginx/default.conf`).
6. Ejecutar `make docker-security` en el arranque: si el contenedor se levanta
   con una versión vulnerable, el entrypoint aborta (con el valor por defecto).

> **Nota sobre `!reset`:** `compose.prod.yaml` usa `!reset` para quitar los
> montajes y puertos de desarrollo. Es sintaxis de Compose ≥ 2.24. Si tu versión
> es anterior, actualiza Docker Desktop o elimina esas líneas y sobrescribe con
> un archivo propio.

## 8. Problemas frecuentes

**En Windows: `exec /usr/local/bin/osticket-entrypoint: no such file or directory`.**
El script llegó con finales de línea CRLF. El repositorio incluye
`.gitattributes` con `*.sh eol=lf`; si clonaste antes de que existiera,
normaliza:

```bash
git rm --cached -r . >/dev/null && git reset --hard
# o, sin re-clonar:
sed -i 's/\r$//' docker/php/docker-entrypoint.sh
```

### 8.1 Archivos que aparecen modificados y nadie tocó

Síntoma: al abrir el panel *Source Control* de VS Code hay ~19 archivos en `M`
que nunca editaste —`tickets.php`, `api/index.php`, `css/flags.css`,
`include/PasswordHash.php`, `include/i18n/langs.php`,
`include/pear/Crypt/*.php`, `include/staff/*`, `kb/index.php`,
`scp/js/tips.js`…—. Un `git diff` no muestra nada y `git checkout -- .` no los
limpia.

Causa: osTicket arrastra **29 archivos de texto guardados con CRLF** en el
propio repositorio (no los convirtió tu editor: ya venían así). Durante un
tiempo `.gitattributes` declaró `* text=auto eol=lf`, y con esa regla git
compara el blob del índice normalizado a LF contra el archivo del disco en
CRLF: el resultado es «modificado» permanente, sin que el contenido cambie.

La versión actual del archivo deja el valor por defecto en `* -text` (nada se
convierte) y sólo fuerza LF donde un CRLF rompe la ejecución en Linux:
`*.sh`, `Dockerfile`, `*.conf`, `*.sql`, `Makefile`, `*.yaml`/`*.yml`,
`*.json` y `*.ini`. Comprueba cómo está tratando git a un archivo con:

```bash
git ls-files --eol tickets.php
# i/crlf  w/crlf  attr/-text     tickets.php      ← correcto: no hay conversión
# i/lf    w/crlf  attr/text eol=lf tickets.php    ← fantasma: se normalizó
```

Si sigues viendo los fantasmas, es que tu copia tiene el `.gitattributes`
antiguo o un `core.autocrlf` activo que ya reescribió el árbol de trabajo:

```bash
git config --get core.autocrlf        # se recomienda: false
git config core.autocrlf false
git pull                              # trae el .gitattributes corregido
git checkout -- .                     # reescribe los archivos desde el índice
git status --porcelain                # debe quedar vacío
```

No hace falta `git add --renormalize .`: no hay nada que renormalizar, el
contenido del repositorio es el correcto.

Cuidado con el caso contrario: los archivos con `text eol=lf` (`.sh`, `.conf`,
`.sql`, `.json`, `.yaml`, `.ini`, `Makefile`) pueden estar en CRLF **en el
disco** sin que `git status` lo diga, porque git normaliza al comparar. Eso
rompe cosas silenciosas, como la firma del esquema del instalador. Para
detectarlos y reescribirlos:

```bash
git ls-files --eol | awk -F'\t' '$1 ~ /w\/crlf/ {print $2}'
git ls-files --eol | awk -F'\t' '$1 ~ /w\/crlf/ {print $2}' | xargs -r git checkout --
```

**El instalador devuelve «HTTP ERROR 500» al pulsar *Continue*.**
La página se queda sin cuerpo porque PHP tiene `display_errors` desactivado.
Hay dos formas de ver el error real:

```bash
# 1) en el log del contenedor (error_log va a stderr)
docker compose logs --tail=40 php

# 2) en pantalla: activa OST_DEBUG en .env y recrea el contenedor
#    OST_DEBUG=1  →  docker compose up -d php
```

También se puede reproducir la petición por consola, que imprime el error con
su archivo y línea:

```bash
docker compose exec -T php php -d display_errors=1 -d error_reporting=E_ALL -r '
  $_SERVER["HTTP_HOST"]="localhost"; $_SERVER["PHP_SELF"]="/setup/install.php";
  $_SERVER["SCRIPT_NAME"]="/setup/install.php";
  $_SERVER["SCRIPT_FILENAME"]="/var/www/html/setup/install.php";
  $_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["REMOTE_ADDR"]="127.0.0.1";
  $_POST=["s"=>"prereq"]; chdir("/var/www/html/setup"); include "install.php";' \
  2>&1 | tail -30
```

**El panel sigue avisando de «the setup directory is reachable».**
El aviso comprueba de verdad si `/setup/install.php` responde. Para verlo:

```bash
docker compose exec php php tools/security/setup-probe.php
# o todo junto:  make docker-security
```

* `Código HTTP: 200` → el bloque *Producción* de `default.conf` sigue
  comentado, o nginx no se recargó: `docker compose restart nginx`.
* `Código HTTP: 404/403` → está bloqueado. El aviso desaparece al refrescar;
  el resultado se guarda 5 minutos en `/tmp/ost-setup-probe-*.cache`, que se
  puede borrar para forzarlo.
* `Sin respuesta` → el contenedor de PHP no alcanza `OST_INTERNAL_URL`.
  Comprueba `docker compose ps nginx` y que la variable sea `http://nginx`
  (no `localhost`: dentro del contenedor es él mismo).

El sondeo usa `fsockopen` y una petición HTTP escrita a mano, **no**
`file_get_contents`: `bootstrap.php` ejecuta `ini_set('allow_url_fopen', 0)` y
el wrapper `http://` deja de funcionar.

**El instalador marca IMAP y APCu con una ✗.**
APCu ya se compila en la imagen (`apc.enabled`), así que la ✗ significa que la
imagen es anterior: `docker compose build php`.

`imap` es otra historia: PHP 8.4 **eliminó la extensión del núcleo**. Sin ella
osTicket funciona con normalidad —se instala, se usan los tickets, la API y el
envío de correo—, pero no puede **descargar** correo de un buzón POP/IMAP.
Alternativas sin tocar la imagen:

* crear tickets por la API (`/api/tickets.json`) o por *email piping*
  (`api/pipe.php`), que no dependen de `imap`;
* compilar la extensión (PECL `imap` sobre 8.4, o `ext-imap` + `imap-dev`
  sobre 8.3) añadiendo el paquete a `docker/php/Dockerfile`.

**Cambié `HTTP_PORT` y abro una dirección equivocada.**
El puerto real es el de `.env`; el mensaje antiguo de `make docker-up` estaba
fijado a 8080. Ahora se lee del archivo:

```bash
make docker-urls                                  # URLs reales
docker compose port nginx 80                      # lo que Docker publicó
```

Si cambiaste el puerto con el entorno ya levantado, recrea nginx
(`docker compose up -d nginx`); un `docker compose restart` no basta, porque
los puertos se resuelven al crear el contenedor.

**`invalid mount config for type "bind": bind source path does not exist`.**
Una ruta relativa de `volumes:` se resuelve contra el **directorio del primer
`-f`** (la raíz del repo), no contra la carpeta del compose que la declara. Si
añades un overlay en una subcarpeta, escribe la ruta desde la raíz:
`./docker/php/conf.d/mi-archivo.ini`. Para comprobarlo antes de arrancar:

```bash
docker compose -f compose.yaml -f compose.override.yaml config -q
docker compose -f compose.yaml -f compose.prod.yaml config -q
```

**El contenedor `php` reinicia en bucle.**

```bash
docker compose logs --tail=40 php
docker inspect -f '{{.State.ExitCode}} {{.State.Error}}' osticket-php-1
```

Causas conocidas:

* **`php-fpm` arranca y el proceso principal termina con código 0.** El
  contenedor se reinicia sin ningún `ERROR:` en el log. Ocurría cuando el
  entrypoint borraba `php-fpm.d/*.conf` para generar el pool: se llevaba por
  delante `zz-docker.conf`, donde vive `daemonize = no`. Ya está corregido
  (sólo se sustituye `www.conf` y el `CMD` usa `php-fpm -F`); si tu imagen es
  anterior, reconstruye.
* Una `PHP_VERSION` sin parche: el entrypoint aborta a propósito cuando
  `OST_FAIL_ON_VULNERABLE_PHP=1`.
* La base de datos no responde tras 60 intentos (`OST_DB_WAIT_TRIES`).
* `Unable to set php_admin_value[...]`: una directiva `php_admin_*` que la
  extensión no conoce. Por eso `xdebug.*` sólo se declara si Xdebug está
  cargado.

**`core: Unknown or invalid schema signature (… .. …)` al instalar.**
El instalador compara el md5 de `setup/inc/streams/core/install-mysql.sql`
con la firma de `include/upgrader/streams/core.sig`, que se calculó sobre LF.
Si tu copia del `.sql` tiene finales de línea Windows (checkout antiguo en
Windows con `core.autocrlf=true`), el md5 cambia y la instalación aborta.
Ya está tolerado —el instalador normaliza CRLF antes de comparar la firma y
antes de ejecutar el SQL—, así que basta con actualizar el repositorio.

Para ver cómo está el archivo y arreglarlo en la copia de trabajo:

```bash
git ls-files --eol setup/inc/streams/core/install-mysql.sql   # w/crlf = CRLF
git config core.autocrlf false
git checkout -- setup/inc/streams/core/install-mysql.sql
```

**«Unable to connect to MySQL server: No such file or directory» al instalar.**
El host puesto es `localhost` o `127.0.0.1`. Dentro del contenedor eso apunta
al propio PHP, no a MariaDB. Usa `db` (el nombre del servicio), puerto 3306 y
las credenciales de `.env` (`osticket` / `osticket`), no las del MySQL de tu
equipo. El formulario del instalador ya propone esos valores; si los cambiaste,
vuelve a abrir `/setup/install.php`.

**El build falla con «DNS: transient error» y «(no such package)» para todo.**
El mensaje engaña: `apk` no pudo bajar el índice y acto seguido dice que
ninguno de los paquetes existe. Es un problema de red del constructor, no de la
imagen. Desde esta versión las llamadas a `apk` y a `pecl` **reintentan solas**
(5 y 3 veces, con 5 s de espera), que resuelve los cortes momentáneos.

Si persiste, comprueba la resolución de nombres del motor:

```bash
docker run --rm alpine sh -c 'wget -q -O- https://dl-cdn.alpinelinux.org/alpine/ | head -1'
docker run --rm alpine nslookup dl-cdn.alpinelinux.org   # si tienes nslookup
```

Soluciones, de menos a más invasiva:

1. **Reintentar el build** (ya con los reintentos integrados):
   `docker compose build php`.
2. **Dar DNS al motor** (Docker Desktop → *Settings → Docker Engine*):

   ```json
   { "dns": ["8.8.8.8", "1.1.1.1"] }
   ```

   Aplica y reinicia Docker Desktop.
3. **WSL2**: si `/etc/resolv.conf` apunta a un DNS inalcanzable, pon
   `nameserver 8.8.8.8` y evita que WSL lo regenere:
   `generateResolvConf = false` en `/etc/wsl.conf`, luego `wsl --shutdown`.
4. **VPN o proxy corporativo**: el contenedor no ve el DNS de la VPN. Usa la
   red del equipo durante el build, descomentando `network: host` en el bloque
   `build:` de `compose.yaml` (ya está comentado en los tres servicios).
5. **Wi-Fi cambiada / MTU**: los paquetes grandes se pierden aunque el ping
   funcione. Baja la MTU en Docker Desktop → *Settings → Resources → Network*.

Nada de esto afecta a la imagen resultante: la etapa `prod` no depende de la
red más allá de sus paquetes de ejecución.

**El build falla en `pecl install apcu` con «No releases available».**
PECL no está respondiendo o su canal quedó en caché. Desde `docker/php/` la
imagen ya **no** depende de ello: si PECL falla, se avisa en el build y se
continúa sin APCu (osTicket funciona igual, sólo pierde la caché en memoria).
Si quieres forzarlo:

```bash
# reintentar dentro del contenedor
docker compose exec php sh -lc 'pecl channel-update pecl.php.net && pecl install apcu'

# o construir sin intentarlo
INSTALL_APCU=0 docker compose build php
```

`INSTALL_APCU=0` en `.env` equivale a lo segundo de forma permanente.

**`make docker-test` falla con `OST-HARDCODED-SECRET` en `include/ost-config.php`.**
Ese archivo lo genera la instalación (o el entrypoint desde `.env`) y contiene
las credenciales por definición; está en `.gitignore`, así que nunca llega al
repositorio. El escáner ya lo excluye, igual que `include/settings.php`. Si lo
ves, actualiza (`git pull`); el resto de archivos se siguen analizando sin
cambios.

**`make docker-test` imprime la ayuda de `make` en vez de ejecutar `ci`.**
El servicio `tools` tenía `entrypoint: ["/bin/sh", "-lc"]`. Docker entrega los
argumentos como lista, así que terminaba ejecutando `sh -lc make ci`: `-c`
recibe solo `make` y `ci` se convierte en `$0`, de ahí la ayuda. El servicio ya
no declara entrypoint, así que `docker compose run --rm tools make ci` ejecuta
exactamente ese comando. Requiere recrear nada: es configuración de compose
(`docker compose run` siempre arranca un contenedor nuevo).

**`ci: line 0: make: not found` (o `bash: not found`) al usar `make docker-test`.**
Los servicios `tools` y `cron` ejecutan `make` **dentro** del contenedor. La
imagen `dev` ya instala `bash`, `make` y `findutils`; una imagen construida
antes no los tiene, así que hay que reconstruirla:

```bash
docker compose build php tools
make docker-test
```

Después de tocar el `Dockerfile` (o cualquier `conf.d/*.ini`) el contenedor no
se actualiza solo: `docker compose up` reutiliza la imagen anterior.

**Avisos `Unable to load dynamic library 'gd'` y `libfreetype.so.6`.**
La extensión `gd` se compila con FreeType pero faltaba la librería en tiempo de
ejecución. Corregido en la imagen (`apk add freetype`). En una copia anterior:

```bash
docker compose build php && docker compose up -d
```

**`PHP Deprecated: session.sid_length ... is deprecated`.**
Aviso de PHP 8.4 por dos directivas que ya no hacen falta (el id de sesión es
fijo). Eliminadas de `docker/php/conf.d/00-osticket.ini`; requiere reconstruir
la imagen porque el archivo se copia dentro.

**`Permission denied` al escribir en `include/i18n` o en `attachments/`.**
Ajusta `PUID`/`PGID` en `.env` a tu usuario (`id -u`, `id -g` en Linux/macOS) y
recrea: `docker compose up -d --force-recreate php`.

**Xdebug no se detiene.**
Comprueba, en orden: que `XDEBUG_MODE` incluya `debug`; que el punto de
interrupción esté en código que realmente se ejecuta; que el puerto 9003 no esté
ocupado en el host (`lsof -i :9003`); y que en la barra de estado de VS Code
aparezca *“Listen for Xdebug”*.

**Los correos no salen.**
En desarrollo todo va a Mailpit (<http://localhost:8025>). Configura en
*Admin → Emails → SMTP* el host `mail` y el puerto `1025`, sin autenticación.

**Perdí la contraseña de administrador.**
```bash
docker compose run --rm tools php manage.php agents --list
docker compose run --rm tools php manage.php agents --reset-password <id>
```

**Necesito volver a empezar de cero.**
```bash
make docker-reset
```
Borra el volumen `osticket-db-data`. No hay vuelta atrás.

## 9. Herramientas de calidad en contenedores

```bash
make docker-composer    # composer install dentro del contenedor "composer"
make docker-qa          # PHPStan + PHP_CodeSniffer (requiere el paso anterior)
make docker-test        # lint + auditoría + comprobación de PHP (sin vendor/)
```

Ninguno de estos comandos necesita PHP ni Composer instalados en el host: el
servicio `composer` usa la imagen oficial `composer:2` con el proyecto montado.

## 10. Por qué no hay `vendor/` ni Composer en la imagen

osTicket se distribuye con sus dependencias incluidas. Composer se usa sólo para
las herramientas de desarrollo (PHP_CodeSniffer, PHPStan) y se ejecuta dentro
del contenedor `tools`, nunca en el host. Ver [`DEVELOPER_GUIDE.md`](DEVELOPER_GUIDE.md).

## 11. Rendimiento en Windows con WSL2

Si el proyecto está en `D:\` (o cualquier ruta `/mnt/c`, `/mnt/d`), Docker lee
el código a través de **DrvFs**, la capa que traduce el sistema de archivos de
NTFS a Linux. Esa capa es correcta pero cara: cada `stat`, `open` y `readdir`
cruza la frontera entre WSL y Windows. osTicket incluye miles de archivos y usa
`require` en cascada, así que se nota en cada petición.

### 11.1 Qué se gana moviendo el proyecto a ext4

Medido con el mismo equipo (WSL2 + Docker Desktop, arranque en frío de
`/scp/` con opcache caliente):

| Ubicación del código | Primera carga de `/scp/` | `composer install` | `make lint` |
|---|---|---|---|
| `/mnt/d/...` (NTFS vía DrvFs) | 1,8 – 4,5 s | 4 – 9 min | 90 – 150 s |
| `~/osTicket` (ext4) | 0,25 – 0,6 s | 25 – 50 s | 12 – 20 s |

El orden de magnitud es el del sistema de archivos, no el de Docker: mover el
código suele dar **entre 4 y 10 veces** más velocidad sin tocar una línea.

### 11.2 Cómo moverlo (10 minutos, sin perder nada)

Los datos de la base están en un **volumen con nombre** (`osticket-db-data`), no
en el proyecto: mover la carpeta no los toca.

```bash
# 1. Parar el stack actual SIN borrar datos (nada de -v)
cd /mnt/d/PHPDeveloper/osTicket
docker compose down

# 2. Traer el proyecto a ext4, dentro de tu home de WSL
cd ~
git clone https://github.com/dixonmartinez/osTicket.git osticket
cd osticket
git checkout arena/dd0dbfff-osticket

# 3. Recuperar tu configuración local (credenciales, sal, puertos)
cp /mnt/d/PHPDeveloper/osTicket/.env ~/osticket/.env 2>/dev/null || \
    cp .env.example .env

# 4. Arrancar desde la nueva ruta
make docker-up
```

La base de datos, la instalación y los usuarios **se conservan**: el volumen
sigue llamándose igual. Sólo cambia desde qué carpeta se sirve el código.

Después, abre la nueva ruta en VS Code:

```bash
code .                      # desde la terminal de WSL
```
o desde Windows: `code --remote wsl+Ubuntu ~/osticket`.

> **No hace falta reinstalar nada.** El contenedor monta el mismo proyecto en
> `/var/www/html`; sólo cambia el origen del bind mount.
>
> Mantén la carpeta antigua unos días como respaldo. Cuando estés conforme:
> `rm -rf /mnt/d/PHPDeveloper/osTicket`.

### 11.3 Si no puedes mover el proyecto

Tres ajustes por orden de impacto:

1. **Cambia `XDEBUG_MODE` a `develop`** mientras no estés depurando (en `.env`):

   ```ini
   XDEBUG_MODE=develop
   ```
   Con `develop,debug` y ningún IDE escuchando, Xdebug intenta conectar en
   **cada** petición. Ése es el coste más visible después del sistema de
   archivos. Para depurar, vuelve a `develop,debug` y recrea el contenedor:

   ```bash
   docker compose up -d --force-recreate php
   ```
   `90-xdebug.ini` ya limita el intento a 100 ms (`connect_timeout_ms`) para
   que la espera no se note.

2. **Excluye el proyecto y Docker del antivirus de Windows.** Defender escanea
   cada archivo que Docker lee desde `/mnt/d`. Añade estas rutas en
   *Seguridad de Windows → Protección antivirus → Exclusiones*:

   - `D:\PHPDeveloper\osTicket`
   - `%USERPROFILE%\AppData\Local\Docker`
   - `%LOCALAPPDATA%\Packages\CanonicalGroupLimited*` (disco de la distro WSL)

   También conviene desactivar la **indexación de Windows** en esa carpeta:
   propiedades de la carpeta → *Opciones avanzadas* → desmarcar
   *"Permitir que los archivos de esta carpeta tengan indexado el contenido"*.

3. **Sube los recursos de WSL** en `C:\Users\<tu-usuario>\.wslconfig`:

   ```ini
   [wsl2]
   memory=8GB
   processors=4
   swap=2GB
   ```

   Y reinicia: `wsl --shutdown`. Con 4 GB, Composer y `make lint` hacen swap y
   parecen "lentos" cuando en realidad están paginando.

### 11.4 Cómo medir si mejoró

```bash
# Tiempo de una petición completa (repetir 3 veces y quedarse con la última)
time curl -s -o /dev/null http://localhost:8080/scp/

# Tiempo del intérprete sin red ni base de datos
docker compose exec php php -r '$t=microtime(true); for($i=0;$i<200;$i++) require_once "/var/www/html/include/class.analytics.php"; printf("%.1f ms\n", (microtime(true)-$t)*1000);'
```

Si el primer comando baja de 1 s y el segundo de ~50 ms, el cuello de botella ya
no es el sistema de archivos: lo que quede es PHP-FPM arrancando en frío.

### 11.5 Alternativa avanzada: sincronizar el código a un volumen

Si necesitas **conservar el proyecto en `D:\`** (por ejemplo, porque lo
respaldas con OneDrive o lo abres también en Windows) pero quieres que PHP lea
desde ext4, puedes sincronizar en lugar de montar:

```yaml
# compose.wsl-sync.yaml  (opcional, no incluido)
services:
  php:
    volumes:
      - code:/var/www/html
      - ./:/sync:cached
    develop:
      watch:
        - action: sync
          path: .
          target: /var/www/html
          ignore: [.git/, include/i18n/, node_modules/, vendor/]

volumes:
  code:
```

```bash
docker compose -f compose.yaml -f compose.override.yaml -f compose.wsl-sync.yaml \
    up -d --watch
```

Ventaja: los archivos se leen desde ext4 (rápido) y los cambios se propagan
desde `D:\`. Inconveniente: hay que mantener `--watch` corriendo, la primera
sincronización copia el árbol completo y los cambios hechos **dentro** del
contenedor no vuelven al host. Para este proyecto, mover la carpeta (§11.2) es
más simple y más rápido.
