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

Hay dos formas, y las dos funcionan sin instalar PHP en el host.

### 3.1 Dev Container (recomendado)

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
contenedor, y `.devcontainer/compose.debug.yaml` monta
`.devcontainer/xdebug-in-container.ini` para que Xdebug conecte a `127.0.0.1`.

### 3.2 Ventana normal de VS Code (sin devcontainer)

1. `make docker-up` (o `docker compose up -d`).
2. `F5` → **“Xdebug: osTicket (Docker)”**.
3. Navega a la aplicación.

Aquí Xdebug sale del contenedor hacia el host por `host.docker.internal:9003`;
en Linux eso funciona gracias a `extra_hosts: host.docker.internal:host-gateway`
que ya está en `compose.yaml`. Si tu firewall bloquea la conexión, revisa que
el puerto 9003 esté permitido en la interfaz de Docker.

### 3.3 Depurar scripts de consola

Abre el archivo (por ejemplo `tools/security/php-cve-check.php`), selecciona
**“Xdebug: script CLI (manage.php / tools)”** y pulsa `F5`. La tarea
*Docker: shell en php* te deja ejecutarlo a mano con
`php tools/security/php-cve-check.php`.

### 3.4 Ajustes de Xdebug

| Variable | Por defecto | Significado |
|---|---|---|
| `XDEBUG_MODE` | `develop,debug` | `off` para máxima velocidad, `coverage` para cobertura |
| `XDEBUG_TRIGGER` | vacío | Con `start_with_request=trigger` activa la sesión sólo con `?XDEBUG_SESSION=1` |
| `PHP_IDE_CONFIG` | `serverName=osticket.local` | Nombre de servidor para configuraciones con *path mapping* |

Los valores están en `.env` y en `docker/php/conf.d/90-xdebug.ini`.
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

`.vscode/launch.json`: cuatro configuraciones (web, web esperando conexión,
script CLI y suite de pruebas).

`.vscode/http/osticket.http`: ejemplos listos para la extensión *REST Client*
(crear ticket por JSON y XML, consultar configuración pública, ver Mailpit).

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
  xdebug-in-container.ini    override de 90-xdebug.ini para ese caso
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
