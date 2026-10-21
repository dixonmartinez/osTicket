# Trabajar con el proyecto en VS Code

Guía del día a día. La idea es simple: **VS Code se queda en tu equipo y el
proyecto se ejecuta en contenedores**. No instalas PHP, Composer, MySQL ni
Xdebug; tampoco hay que «reabrir el proyecto en un contenedor»: el
`.devcontainer` ya no forma parte del repositorio.

* Docker y puertos → [`DOCKER.md`](DOCKER.md)
* Arquitectura y convenciones → [`DEVELOPER_GUIDE.md`](DEVELOPER_GUIDE.md)
* Versión de PHP y php2shell → [`SECURITY_PHP2SHELL.md`](SECURITY_PHP2SHELL.md)

---

## 1. Lo que necesitas

| Pieza | Versión | Nota |
|---|---|---|
| Docker Desktop (Windows/macOS) o Docker Engine + Compose v2 (Linux/WSL2) | reciente | Es lo único imprescindible |
| VS Code | 1.85 o superior | Con las extensiones del apartado 9 |
| Git | cualquiera | Ya lo tienes si clonaste el repo |

No necesitas: PHP, Composer, MySQL, Xdebug, Apache/nginx ni Node en el equipo.

---

## 2. Abrir el proyecto

| Dónde está la carpeta | Cómo abrirla | Qué esperar |
|---|---|---|
| Disco de Windows (`D:\...`, `C:\...`) | Doble clic en VS Code o *Archivo → Abrir carpeta* | Funciona; Intelephense indexa rápido por NTFS |
| Sistema de archivos de Linux/WSL (`~/osticket`) | Desde la terminal de WSL: `cd ~/osticket && code .` | **Recomendado**: entre 4 y 10 veces más rápido que `/mnt/c` o `/mnt/d` |
| WSL pero en `/mnt/d/...` | Muévelo a `~/osticket` (ver `DOCKER.md` § 11) | Lento: el bind mount cruza el sistema de archivos |

Abrir la carpeta desde WSL con `code .` instala el *WSL Server* de VS Code y
deja el editor «pegado» a Linux; las tareas y la terminal integrada ya hablan
con Docker sin cambiar de contexto.

Al abrir la carpeta, VS Code propone instalar las extensiones recomendadas
(`.vscode/extensions.json`). Acepta **Install all** una vez.

---

## 3. Primera puesta en marcha

```bash
make docker-up
```

Ese objetivo hace todo: crea `.env` a partir de `.env.example` con una
`OST_SECRET_SALT` aleatoria si aún no existe, construye la imagen de PHP
(la primera vez tarda unos minutos porque compila Xdebug) y arranca nginx,
PHP-FPM, MariaDB y Mailpit.

| Servicio | Dirección |
|---|---|
| Portal del cliente | <http://localhost:8080> |
| Panel de agentes | <http://localhost:8080/scp/> |
| Instalador (sólo la primera vez) | <http://localhost:8080/setup/> |
| Correo capturado (Mailpit) | <http://localhost:8025> |
| Base de datos desde el equipo | `127.0.0.1:3307` |

Los puertos salen de `.env` (`HTTP_PORT`, `DB_PORT`, `MAILPIT_UI_PORT`). Si el
8080 está ocupado, cámbialos ahí, recrea nginx (`docker compose up -d nginx`)
y consulta las direcciones reales con `make docker-urls`; `make docker-up`
también las imprime al terminar.

### 3.1 Instalar osTicket

1. Abre <http://localhost:8080/setup/>.
2. Datos de la base: *Host* `db` (no `localhost`), *Database* `osticket`,
   *Username* `osticket`, *Password* `osticket` (los de tu `.env`), prefijo
   `ost_`. El formulario llega ya relleno con esos valores: basta con
   *Install Now*.
3. Crea el administrador y termina.

El contenedor genera `include/ost-config.php` solo, a partir de `OST_DB_*`.

### 3.2 Cerrar el instalador

Una vez instalado, bloquea `/setup/` en lugar de borrarlo: **`setup/` es código
versionado** y borrarlo ensucia `git status`.

```bash
# descomenta el bloque "Producción" de docker/nginx/default.conf y luego:
docker compose restart nginx
```

El propio instalador ya se niega a reinstalar cuando existe
`include/ost-config.php`, así que dejarlo abierto tampoco permite reinstalar;
bloquearlo es higiene.

### 3.3 Comprobar la postura de seguridad

```bash
make docker-security
```

Verifica que la imagen no esté afectada por *php2shell*
(CVE-2026-109618 / CVE-2026-109619). Debe decir que la versión está parcheada.

---

## 4. El ciclo diario

1. `make docker-up` (o la tarea **Docker: up (dev)**, `Ctrl+Shift+B`).
2. Pon un punto de interrupción en el archivo PHP que quieras inspeccionar.
3. `F5` → **“Xdebug: osTicket (web)”**.
4. Navega a <http://localhost:8080>. La primera petición que toque tu código
   detiene la ejecución en el editor.
5. Al terminar: `Shift+F5` para cortar la sesión y `make docker-down` si vas a
   apagar el equipo (los datos quedan en el volumen).

Los cambios de código no requieren reiniciar nada: la carpeta está montada en
`/var/www/html` y nginx/PHP-FPM ven el archivo nuevo al instante. Sólo hay que
reconstruir (`make docker-build`) si cambias el `Dockerfile`, un `.ini` de
`docker/php/conf.d` o `compose.yaml`.

---

## 5. Qué lanzador uso

`.vscode/launch.json` trae cuatro. **La de uso normal es la primera.**

| Configuración | Para qué | Cómo se usa |
|---|---|---|
| **Xdebug: osTicket (web)** | Peticiones HTTP: portal, `/scp/`, API | `F5` y navega a la aplicación |
| **Xdebug: osTicket (web, diagnóstico)** | Cuando «F5 no se detiene»: escribe el protocolo de Xdebug en la consola | `F5`, reproduce el problema y lee la consola de depuración |
| **Xdebug: script CLI (vía Docker)** | `manage.php`, `tools/*.php`, cualquier script de consola | Abre el archivo, guárdalo, `F5` |
| **Xdebug: pruebas del proyecto (vía Docker)** | Suite histórica `setup/test/run-tests.php` | `F5` con lo que quieras inspeccionar |

Todas escuchan en el puerto **9003** de tu equipo; el contenedor abre la sesión
hacia `host.docker.internal:9003`. El *path mapping* ya está resuelto:
`/var/www/html` → `${workspaceFolder}`.

> Si `F5` no muestra el selector, es que ya hay una configuración elegida: usa
> la lista desplegable de la barra lateral *Run and Debug* para cambiarla.

---

## 6. Depurar un script de consola, paso a paso

1. Abre `tools/security/php-cve-check.php` (o el script que quieras).
2. Guarda (`Ctrl+S`): el lanzador ejecuta lo que hay en disco, no lo que hay en
   el búfer del editor.
3. `F5` → **“Xdebug: script CLI (vía Docker)”**.
4. La salida del script aparece en la consola de depuración, y la ejecución se
   detiene en tus puntos de interrupción.

Por dentro ejecuta:

```bash
docker compose exec -T php php -dxdebug.start_with_request=yes \
    /var/www/html/ruta/al/script.php
```

Si no quieres depurar, lo mismo desde la terminal del proyecto:

```bash
make docker-shell
# ya dentro del contenedor:
php tools/security/php-cve-check.php
```

---

## 7. Pruebas y calidad

| Qué | Comando | Dónde corre |
|---|---|---|
| Sintaxis de los 700+ archivos PHP | `make docker-test` | Contenedor (lint + auditoría + php2shell) |
| Auditoría de seguridad | `docker compose run --rm tools make audit` | Contenedor |
| php2shell | `make docker-security` | Contenedor |
| Suite histórica | `docker compose run --rm tools php setup/test/run-tests.php` | Contenedor |
| PHPStan + PHP_CodeSniffer | `make docker-composer` y luego `make docker-qa` | Contenedor (requiere `vendor/`) |
| Rendimiento del stack | `make docker-bench` | Host + contenedor |

Las mismas tareas están en el menú: `Ctrl+Shift+P` → *Tasks: Run Task* →
*Calidad: …*. `Ctrl+Shift+B` ejecuta directamente *Docker: up (dev)*.

---

## 8. Comandos sin salir de VS Code

No hay PHP en el equipo, así que todo pasa por Docker:

```bash
make docker-shell                 # sh dentro del contenedor php
make docker-logs                  # logs de nginx + php (Ctrl+C para salir)
docker compose exec db mariadb -uosticket -posticket osticket   # consola SQL
make docker-composer              # composer install en contenedor
make docker-prod                  # variante de producción (sin Xdebug)
```

Tareas equivalentes en `Ctrl+Shift+P` → *Tasks: Run Task*: *Docker: shell en php*, *Docker: logs*,
*Docker: shell en db*, *Datos: crear base de datos si falta*.

`.vscode/http/osticket.http` tiene peticiones listas para la extensión
*REST Client* (crear ticket por JSON/XML, configuración pública, Mailpit).

---

## 9. Extensiones recomendadas

| Extensión | ID | Para qué |
|---|---|---|
| PHP Debug | `xdebug.php-debug` | **Obligatoria**: sin ella no existe `F5` |
| Intelephense | `bmewburn.vscode-intelephense-client` | Autocompletado, ir a definición, diagnósticos |
| EditorConfig | `EditorConfig.EditorConfig` | Respeta `.editorconfig` |
| Docker | `ms-azuretools.vscode-docker` | Ver contenedores, logs e imágenes |
| SQLTools + driver MySQL | `mtxr.sqltools`, `mtxr.sqltools-driver-mysql` | Consultar MariaDB desde el editor |
| YAML | `redhat.vscode-yaml` | Validar `compose*.yaml` |
| REST Client | `humao.rest-client` | Probar la API |
| Prettier | `esbenp.prettier-vscode` | Formato de JS/CSS/Markdown |

Evita `felixfbecker.php-debug` (antiguo) y no mezcles dos servidores de
lenguaje PHP (DEVSENSE junto a Intelephense): duplican diagnósticos y memoria.

`php.validate` está desactivado en `.vscode/settings.json` a propósito: el
validador de VS Code necesita un binario de PHP en el equipo que aquí no
existe. Los errores de sintaxis los reporta Intelephense, y la verdad definitiva
es `make docker-test`.

---

## 10. Problemas frecuentes

**`F5` falla con «Cannot find runtime 'php'» o similar.**
Estás lanzando una configuración que espera PHP local. Usa **Xdebug: osTicket
(web)** o la variante **vía Docker**.

**`F5` se queda en «Listening for Xdebug» y no pasa nada.**
Es lo normal: está esperando a que llegue una petición. Navega a
<http://localhost:8080>. Si ya navegaste y nada ocurre, comprueba en orden:

```bash
docker compose ps                                   # php está "running"?
docker compose exec php php -i | grep xdebug.mode   # ¿incluye debug?
docker compose exec php printenv XDEBUG_MODE
```

**El punto de interrupción aparece gris (no se enlaza).**
La ruta no coincide: el archivo debe estar dentro de la carpeta abierta en VS
Code. Si abriste `\\wsl$\...` desde Windows y el *path mapping* apunta a otra
raíz, abre la carpeta con `code .` desde la terminal de WSL.

**Xdebug no conecta en Windows.**
Permite a Docker Desktop en el firewall de Windows (redes privadas) y confirma
que el 9003 está libre:

```powershell
netstat -ano | findstr 9003
```

Otro depurador (otra ventana de VS Code, otro IDE) escuchando en ese puerto
también bloquea la conexión.

**«No hay ningún proceso escuchando en 9003» dentro del contenedor.**
El lanzador debe estar activo *antes* de la petición: pulsa `F5` primero y
navega después.

**La terminal integrada no reconoce `php`, `composer` o `mysql`.**
Es correcto: no están en el equipo. Usa `make docker-shell` o las tareas
*Docker: …*. Para ejecutar un comando suelto:
`docker compose exec php php -v`.

**Cambié el puerto porque el 8080 estaba ocupado y no sé dónde entrar.**
`make docker-urls` imprime las URLs con los valores de `.env`. Si ya estaba
levantado cuando cambiaste el puerto, recrea nginx
(`docker compose up -d nginx`): los puertos se fijan al crear el contenedor y
`restart` no los actualiza.

**`make docker-up` termina pero <http://localhost:8080> no responde.**
Mira `make docker-logs`. Si el `php` reinicia en bucle suele ser la versión de
PHP sin parche (el entrypoint aborta a propósito) o MariaDB que aún arranca.

**Permisos: no puedo guardar archivos que genera la aplicación.**
Ajusta `PUID`/`PGID` en `.env` a tu usuario (`id -u`, `id -g`) y recrea:
`docker compose up -d --force-recreate php`.

**Todo va lento (sobre todo en Windows).**
Proyecto en `/mnt/d` → muévelo a `~/osticket` y abre con `code .`. Detalle y
medición: `DOCKER.md` § 11 y `make docker-bench`.

---

## 11. Qué cambia respecto a un Dev Container

El repositorio ya **no incluye** `.devcontainer/`. Perdías poco y ganabas
simplicidad:

| Antes (Dev Container) | Ahora | Cómo se cubre |
|---|---|---|
| Terminal integrada dentro del contenedor | Tu shell del equipo | Tareas *Docker: shell en php/db* y `make docker-shell` |
| Extensiones instaladas automáticamente | Se instalan en tu VS Code | Aviso **Install all** al abrir la carpeta |
| `php` de la terminal | No existe en el equipo | `docker compose exec php …` (igual de rápido) |
| Indexado leyendo el bind mount | Intelephense lee el disco directamente | Más rápido todavía |
| Xdebug escuchando en `127.0.0.1` | Escucha en tu equipo, `host.docker.internal:9003` | Ya configurado en `launch.json` y `90-xdebug.ini` |

Lo único que se pierde de verdad es la reproducibilidad total del entorno del
editor (mismas extensiones y versiones para todo el equipo). Si algún día hace
falta, se puede volver a añadir un `.devcontainer/` sin tocar nada de esto: los
lanzadores funcionarían igual cambiando `XDEBUG_CLIENT_HOST` a `127.0.0.1`.
