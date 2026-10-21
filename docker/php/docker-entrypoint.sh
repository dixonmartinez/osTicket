#!/bin/sh
#
# Entrypoint del contenedor de osTicket.
#
#   1. Verifica que PHP no esté afectado por php2shell (CVE-2026-109618/19).
#   2. Genera el pool de PHP-FPM con las directivas de seguridad aplicadas
#      al arrancar (nunca por petición).
#   3. Espera a la base de datos.
#   4. Crea include/ost-config.php a partir de las variables de entorno si
#      todavía no existe.
#
# El proceso maestro arranca como root para poder crear el usuario del host y
# publicar el puerto 9000; los workers de PHP-FPM corren como PUID/PGID para
# que los archivos creados en el volumen pertenezcan al usuario local.
#
# Variables de entorno:
#   PUID, PGID                  UID/GID de los workers (por defecto 1000:1000)
#   OST_INSTALL_DB              "1" crea la base de datos si no existe (útil
#                               cuando el volumen de MySQL ya estaba creado)
#   OST_DB_HOST, OST_DB_NAME, OST_DB_USER, OST_DB_PASS, OST_DB_PREFIX
#   OST_SECRET_SALT             sal aleatoria para SECRET_SALT (recomendado)
#   OST_AUTO_CONFIG             "1" (por defecto) crea ost-config.php si falta
#   OST_FAIL_ON_VULNERABLE_PHP  "1" aborta si PHP está afectado (CI/producción)
#   XDEBUG_CLIENT_HOST          host al que Xdebug envía la sesión (tu equipo)
#   PHP_FPM_MAX_REQUESTS        peticiones por worker; 1 mitiga CVE-2026-109619
#   PHP_FPM_MAX_CHILDREN        hijos de PHP-FPM (por defecto 10)
#   PHP2SHELL_STRICT            "1" reduce aún más los límites de subida
set -eu

log()  { printf '\033[1;34m[osticket]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[osticket] AVISO:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[osticket] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# 0. Usuario de los workers
# ---------------------------------------------------------------------------
FPM_USER=www-data
FPM_GROUP=www-data

if [ "$(id -u)" = "0" ] && [ -n "${PUID:-}" ] && [ "${PUID}" != "0" ]; then
    PGID="${PGID:-$PUID}"

    if ! awk -F: -v g="$PGID" '$3==g{found=1} END{exit !found}' /etc/group; then
        addgroup -g "$PGID" osticket 2>/dev/null || true
    fi
    if ! awk -F: -v u="$PUID" '$3==u{found=1} END{exit !found}' /etc/passwd; then
        adduser -u "$PUID" -G "$(awk -F: -v g="$PGID" '$3==g{print $1}' /etc/group)" \
            -S -D -H osticket 2>/dev/null || true
    fi

    FPM_USER="$(awk -F: -v u="$PUID" '$3==u{print $1}' /etc/passwd)"
    FPM_GROUP="$(awk -F: -v g="$PGID" '$3==g{print $1}' /etc/group)"
    FPM_USER="${FPM_USER:-www-data}"
    FPM_GROUP="${FPM_GROUP:-www-data}"
fi

# ---------------------------------------------------------------------------
# 1. Postura de seguridad del intérprete
# ---------------------------------------------------------------------------
PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
log "PHP ${PHP_VERSION}"

if [ -f /var/www/html/tools/security/php-cve-check.php ]; then
    if php /var/www/html/tools/security/php-cve-check.php --quiet; then
        log "PHP no afectado por php2shell (CVE-2026-109618 / CVE-2026-109619)"
    else
        warn "PHP ${PHP_VERSION} está AFECTADO por php2shell (CVSS 9.2)."
        warn "Actualiza la imagen base a 8.2.35+, 8.3.36+, 8.4.27+ o 8.5.12+."
        warn "Detalle: php tools/security/php-cve-check.php"
        if [ "${OST_FAIL_ON_VULNERABLE_PHP:-0}" = "1" ]; then
            die "OST_FAIL_ON_VULNERABLE_PHP=1 y el intérprete es vulnerable."
        fi
    fi
fi

# ---------------------------------------------------------------------------
# 2. Pool de PHP-FPM
# ---------------------------------------------------------------------------
# Las directivas php_admin_* se aplican al arrancar y no pueden modificarse
# desde la aplicación; el aviso de php2shell señala que los ajustes por
# petición (php_value / PHP_VALUE) son una de las vías del fallo.
FPM_POOL=/usr/local/etc/php-fpm.d/www.conf
if [ -w /usr/local/etc/php-fpm.d ]; then
    # Sólo se sustituye el pool de la imagen (www.conf). docker.conf y
    # zz-docker.conf NO se tocan: aportan `daemonize = no`, el error_log a
    # stderr y `listen = 9000`. Si se borran, php-fpm se demoniza, el proceso
    # principal termina con 0 y Docker reinicia el contenedor en bucle.
    rm -f /usr/local/etc/php-fpm.d/www.conf

    {
        echo '[www]'
        if [ "$(id -u)" = "0" ]; then
            echo "user = ${FPM_USER}"
            echo "group = ${FPM_GROUP}"
        fi
        echo 'listen = 9000'
        echo 'clear_env = no'
        echo 'catch_workers_output = yes'
        echo 'decorate_workers_output = no'
        # docker.conf (que ahora se conserva) manda el access log a stderr.
        # nginx ya registra cada petición; aquí sólo sería ruido.
        echo 'access.log = /dev/null'
        echo 'pm = dynamic'
        echo "pm.max_children = ${PHP_FPM_MAX_CHILDREN:-10}"
        echo 'pm.start_servers = 2'
        echo 'pm.min_spare_servers = 1'
        echo 'pm.max_spare_servers = 3'
        echo "pm.max_requests = ${PHP_FPM_MAX_REQUESTS:-500}"
        echo 'php_admin_value[error_log] = /proc/self/fd/2'
        echo 'php_admin_flag[log_errors] = on'
        echo 'php_admin_flag[display_errors] = off'
        echo 'php_admin_flag[session.upload_progress.enabled] = off'
        echo 'php_admin_value[memory_limit] = 256M'
        # Xdebug: a dónde debe conectar. Por defecto a tu equipo, que es
        # donde escucha VS Code con el lanzador "Xdebug: osTicket (web)".
        #
        # Sólo se declara si la extensión está cargada: en la etapa "prod" no
        # existe y php-fpm escupiría un "Unable to set php_admin_value" en
        # cada arranque.
        if [ -n "${XDEBUG_CLIENT_HOST:-}" ] \
                && php -r 'exit(extension_loaded("xdebug") ? 0 : 1);' 2>/dev/null; then
            echo "php_admin_value[xdebug.client_host] = ${XDEBUG_CLIENT_HOST}"
            echo "php_admin_value[xdebug.client_port] = ${XDEBUG_CLIENT_PORT:-9003}"
        fi
        if [ "${PHP2SHELL_STRICT:-0}" = "1" ]; then
            echo 'php_admin_value[upload_max_filesize] = 4M'
            echo 'php_admin_value[post_max_size] = 6M'
            echo 'php_admin_value[max_file_uploads] = 5'
        fi
        # fatal_error_backtraces sólo existe en PHP >= 8.5; declararlo en
        # ramas anteriores impide que php-fpm arranque.
        if php -r 'exit(version_compare(PHP_VERSION, "8.5.0", ">=") ? 0 : 1);'; then
            echo 'php_admin_flag[fatal_error_backtraces] = off'
        fi
    } > "$FPM_POOL"

    log "Pool de PHP-FPM generado (${FPM_USER}:${FPM_GROUP}, max_requests=${PHP_FPM_MAX_REQUESTS:-500})"
else
    warn "No se pudo escribir el pool de PHP-FPM; se usa el de la imagen."
fi

# ---------------------------------------------------------------------------
# 3. Espera a la base de datos
# ---------------------------------------------------------------------------
if [ -n "${OST_DB_HOST:-}" ]; then
    log "Esperando a la base de datos ${OST_DB_HOST}:${OST_DB_PORT:-3306}..."
    tries=0
    until php -r '
        $h = getenv("OST_DB_HOST"); $p = getenv("OST_DB_PORT") ?: 3306;
        $c = @new mysqli($h, getenv("OST_DB_USER") ?: null,
            getenv("OST_DB_PASS") ?: null, null, (int) $p);
        exit($c->connect_errno ? 1 : 0);
    ' 2>/dev/null; do
        tries=$((tries + 1))
        if [ "$tries" -ge "${OST_DB_WAIT_TRIES:-60}" ]; then
            die "La base de datos no respondió tras ${tries} intentos."
        fi
        sleep 2
    done
    log "Base de datos disponible"

    # Crea la base de datos si el volumen de MySQL ya existía (en ese caso
    # /docker-entrypoint-initdb.d no se ejecuta).
    if [ "${OST_INSTALL_DB:-0}" = "1" ]; then
        php /var/www/html/docker/php/setup-db.php \
            || warn "No se pudo preparar la base de datos automáticamente; usa el asistente /setup/."
    fi
fi

# ---------------------------------------------------------------------------
# 4. Configuración de la instalación
# ---------------------------------------------------------------------------
CONFIG=/var/www/html/include/ost-config.php
if [ ! -f "$CONFIG" ] && [ "${OST_AUTO_CONFIG:-1}" = "1" ]; then
    if [ -f /var/www/html/include/ost-sampleconfig.php ]; then
        log "Creando include/ost-config.php desde el ejemplo"
        cp /var/www/html/include/ost-sampleconfig.php "$CONFIG"

        sed -i \
            -e "s|%CONFIG-DBHOST|${OST_DB_HOST:-db}|g" \
            -e "s|%CONFIG-DBNAME|${OST_DB_NAME:-osticket}|g" \
            -e "s|%CONFIG-DBUSER|${OST_DB_USER:-osticket}|g" \
            -e "s|%CONFIG-DBPASS|${OST_DB_PASS:-osticket}|g" \
            -e "s|%CONFIG-TABLEPREFIX|${OST_DB_PREFIX:-ost_}|g" \
            -e "s|%CONFIG-SIRI|${OST_SECRET_SALT:-change-me-please}|g" \
            "$CONFIG"

        # Contiene credenciales: sólo lectura para el propietario.
        chmod 640 "$CONFIG" 2>/dev/null || true

        if [ "${OST_SECRET_SALT:-change-me-please}" = "change-me-please" ]; then
            warn "SECRET_SALT por defecto. Define OST_SECRET_SALT con 32+ caracteres aleatorios."
        fi
    else
        warn "No se encontró include/ost-sampleconfig.php; crea ost-config.php a mano."
    fi
fi

if [ -f "$CONFIG" ] && [ "$(id -u)" = "0" ]; then
    chown "${FPM_USER}:${FPM_GROUP}" "$CONFIG" 2>/dev/null || true
    chown -R "${FPM_USER}:${FPM_GROUP}" /var/www/html/include/i18n 2>/dev/null || true
fi

if [ "${OST_DB_CHECK_INSTALLED:-0}" = "1" ] && [ -f "$CONFIG" ]; then
    if ! php -r '
        $h = getenv("OST_DB_HOST"); $p = getenv("OST_DB_PORT") ?: 3306;
        $c = @new mysqli($h, getenv("OST_DB_USER"), getenv("OST_DB_PASS"),
            getenv("OST_DB_NAME"), (int) $p);
        if ($c->connect_errno) exit(1);
        $r = $c->query("SHOW TABLES LIKE \"%config\"");
        exit(($r && $r->num_rows) ? 0 : 1);
    ' 2>/dev/null; then
        warn "La base de datos aún no está instalada. Abre http://localhost:${HTTP_PORT:-8080}/setup/ y completa el asistente."
    fi
fi

log "Arrancando: $*"
exec "$@"
