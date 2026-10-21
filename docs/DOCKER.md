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
2. Rellena los datos de la base:
   * Host: `db`
   * Base de datos: `osticket`
   * Usuario: `osticket`
   * Contraseña: `osticket` (o la que hayas puesto en `.env`)
3. Crea el administrador y termina.

**B. Preparar la base por consola**

```bash
make docker-install        # crea la base si el volumen ya existía
# o, para que se haga solo en cada arranque:
OST_INSTALL_DB=1           # en .env
```

El contenedor genera `include/ost-config.php` automáticamente a partir de las
variables de entorno (`OST_DB_*`), así que no hay que editarlo a mano ni
versionarlo.

Cuando la instalación termine, **borra o bloquea `/setup/`**:

```bash
docker compose exec php rm -rf /var/www/html/setup
# o descomenta el bloque "Producción" de docker/nginx/default.conf
```

## 3. Depurar con Xdebug desde VS Code

**El Dev Container es opcional.** Hay dos formas de trabajar y las dos permiten
depurar sin instalar PHP en el host. Elige según dónde viva el proyecto:

| | Ventana normal | Dev Container |
|---|---|---|
| Dónde corre VS Code | En tu sistema (Windows/macOS/Linux) | Dentro del contenedor `php` |
| Dónde corren las extensiones | En tu sistema | En el contenedor |
| Dónde escucha Xdebug (9003) | En tu sistema | En el contenedor |
| Hace falta PHP instalado | No | No |
| Intelephense indexa leyendo | Disco local (rápido) | El bind mount del proyecto |
| Terminal integrada | Tu shell | Shell del contenedor |
| Reproducibilidad del equipo | Depende de tus extensiones | Idéntica para todos |

**Regla práctica:** si el proyecto está en el disco de Windows (`/mnt/c`,
`/mnt/d`) y abres VS Code desde Windows, **usa la ventana normal**: el editor
lee por NTFS directamente y el indexado de Intelephense va mucho más rápido que
a través del bind mount. Si el proyecto vive en el sistema de archivos de Linux
(`~/osTicket`), el Dev Container es la mejor opción porque el servidor de VS
Code queda junto a los archivos.

### 3.1 Dev Container

1. Abre la carpeta del proyecto en VS Code.
2. Acepta el aviso **“Reopen in Container”** (o `F1` →
   *Dev Containers: Reopen in Container*).
3. Espera a que termine: VS Code instala las extensiones recomendadas dentro
   del contenedor (`php-debug`, Intelephense, SQLTools…).
4. Pon un punto de interrupción, pulsa `F5` y elige
   **“Xdebug: osTicket (Docker)”**.
5. Navega a <http://localhost:8080/scp/>. La ejecución se detiene en tu punto
   de interrupción.

En este modo el puerto 9003 no se publica: VS Code y PHP viven en el mismo
contenedor. `.devcontainer/compose.debug.yaml` pone `XDEBUG_CLIENT_HOST` a
`127.0.0.1` y el entrypoint escribe ese valor en el pool de PHP-FPM al
arrancar, así que no hay archivos extra que montar.

### 3.2 Ventana normal de VS Code (sin devcontainer)

1. `make docker-up` (o `docker compose up -d`).
2. Instala solo estas extensiones (VS Code las propone al abrir la carpeta):
   `xdebug.php-debug` e `bmewburn.vscode-intelephense-client`.
3. `F5` → **“Xdebug: osTicket (Docker)”**.
4. Navega a la aplicación.

El *path mapping* funciona igual porque `${workspaceFolder}` se resuelve a la
ruta de Windows (`D:\PHPDeveloper\osTicket`) y Xdebug envía
`/var/www/html/...`.

Aquí Xdebug sale del contenedor hacia el host por `host.docker.internal:9003`;
en Linux eso funciona gracias a `extra_hosts: host.docker.internal:host-gateway`
que ya está en `compose.yaml`. Si tu firewall bloquea la conexión, revisa que
el puerto 9003 esté permitido en la interfaz de Docker.

### 3.3 Depurar scripts de consola

Abre el archivo (por ejemplo `tools/security/php-cve-check.php`) y pulsa `F5`
eligiendo la variante que corresponda a cómo abriste el proyecto:

| Configuración | Cuándo |
|---|---|
| **Xdebug: script CLI (dentro del contenedor)** | Dev Container. Usa el `php` del contenedor |
| **Xdebug: script CLI (desde el host, vía Docker)** | Ventana normal, sin PHP instalado: ejecuta `docker compose exec php php …` |

Para lanzarlo sin depurador, la tarea *Docker: shell en php* o
`docker compose exec php php tools/security/php-cve-check.php`.

### 3.4 Ajustes de Xdebug

| Variable | Por defecto | Significado |
|---|---|---|
| `XDEBUG_MODE` | `develop,debug` | `off` para máxima velocidad, `coverage` para cobertura |
| `XDEBUG_CLIENT_HOST` | `host.docker.internal` | A quién conecta Xdebug. El Dev Container lo pone a `127.0.0.1` |
| `XDEBUG_CLIENT_PORT` | `9003` | Puerto del IDE |
| `XDEBUG_TRIGGER` | vacío | Con `start_with_request=trigger` activa la sesión sólo con `?XDEBUG_SESSION=1` |
| `PHP_IDE_CONFIG` | `serverName=osticket.local` | Nombre de servidor para configuraciones con *path mapping* |

Los valores están en `.env` y en `docker/php/conf.d/90-xdebug.ini`.

> `.vscode/settings.json` desactiva `php.validate` a propósito: no hay PHP en
> el host. La validación la hace Intelephense y la ejecución va por Docker. Con
> Dev Containers, `.devcontainer/devcontainer.json` lo reactiva apuntando a
> `/usr/local/bin/php`.

El *path mapping* ya está resuelto en `.vscode/launch.json`:
`/var/www/html` → `${workspaceFolder}`.

## 4. Tareas y lanzadores incluidos

`.vscode/tasks.json` (`Ctrl+Shift+B`):

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

`.vscode/launch.json`: cinco configuraciones — web, web esperando conexión,
script CLI dentro del contenedor, script CLI desde el host vía `docker compose`
y la suite de pruebas.

`.vscode/http/osticket.http`: ejemplos listos para la extensión *REST Client*
(crear ticket por JSON y XML, consultar configuración pública, ver Mailpit).

## 4.1 Extensiones de VS Code

El repositorio ya las declara en `.vscode/extensions.json`: al abrir la carpeta,
VS Code muestra un aviso con **“Install all”**. Las del Dev Container se
instalan solas al hacer *Reopen in Container*.

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
make docker-up          # arrancar
make docker-down        # parar (los datos se conservan)
make docker-logs        # seguir logs
make docker-shell       # shell en PHP
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

## 6. Estructura de los archivos de Docker

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
.devcontainer/
  devcontainer.json          VS Code dentro del contenedor
  compose.debug.yaml         Xdebug apuntando a 127.0.0.1
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
5. Borrar `/setup/` después de instalar.
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

**`invalid mount config for type "bind": bind source path does not exist`.**
Una ruta relativa de `volumes:` se resuelve contra el **directorio del primer
`-f`** (la raíz del repo), no contra la carpeta del compose que la declara. Si
añades un overlay dentro de una subcarpeta, escribe la ruta desde la raíz:
`./.devcontainer/mi-archivo.ini`. Para comprobarlo antes de arrancar:

```bash
docker compose -f compose.yaml -f compose.override.yaml -f .devcontainer/compose.debug.yaml config -q
```

**El contenedor `php` reinicia en bucle.**
Mira `docker compose logs php`. Las causas habituales son: una `PHP_VERSION`
sin parche (el entrypoint aborta a propósito), la base de datos que aún no
responde, o un puerto ocupado.

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
