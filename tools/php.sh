#!/bin/sh
# ---------------------------------------------------------------------------
# tools/php.sh — usa el PHP del host o, si no lo hay, el del contenedor.
#
#   make theme        (con PHP instalado)  -> php tools/ui/theme-check.php
#   make theme        (sin PHP instalado)  -> docker compose run --rm tools php ...
#
# Existe para que ningún objetivo del Makefile dependa de tener PHP en el
# equipo: quien trabaja sólo con Docker no necesita instalarlo.
#
# El contenedor monta el proyecto en /var/www/html (no en la ruta del host),
# así que las rutas absolutas que llegan del Makefile se traducen antes de
# ejecutar: /opt/Developer/osticket/tools/ui/theme-check.php se convierte en
# /var/www/html/tools/ui/theme-check.php. Sin esto, Docker responde
# «Could not open input file».
#
# Variables:
#   OST_PHP=/ruta/al/php    fuerza un binario concreto
#   OST_CONTAINER_DIR=…     ruta del proyecto dentro del contenedor
#                           (por defecto /var/www/html, como en compose.yaml)
# ---------------------------------------------------------------------------
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

if [ -n "${OST_PHP:-}" ]; then
    exec "$OST_PHP" "$@"
fi

if command -v php >/dev/null 2>&1; then
    exec php "$@"
fi

if command -v docker >/dev/null 2>&1 && [ -f "$root/compose.yaml" ]; then
    echo "· php no está en el host: ejecutando dentro del contenedor de herramientas" >&2

    # Traduce cualquier argumento que apunte dentro del proyecto a la ruta
    # que tiene montada el contenedor. Se hace argumento a argumento para
    # respetar los espacios y las opciones (`--baseline=<ruta>`).
    container_dir=${OST_CONTAINER_DIR:-/var/www/html}
    total=$#
    i=0
    while [ "$i" -lt "$total" ]; do
        arg=$1
        shift
        case "$arg" in
            *"$root"*)
                before=${arg%%"$root"*}
                after=${arg#*"$root"}
                arg="$before$container_dir$after"
                ;;
        esac
        set -- "$@" "$arg"
        i=$((i + 1))
    done

    cd "$root"
    exec docker compose run --rm --no-deps tools php "$@"
fi

cat >&2 <<'MSG'
No hay PHP en el equipo y tampoco Docker.

  Opción 1: instala PHP 8.2+ y vuelve a intentarlo.
  Opción 2: usa los contenedores del proyecto:
              make docker-up && make docker-test
MSG
exit 127
