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
# Variables:
#   OST_PHP=/ruta/al/php   fuerza un binario concreto
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
