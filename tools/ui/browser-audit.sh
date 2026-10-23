#!/bin/sh
# ---------------------------------------------------------------------------
# tools/ui/browser-audit.sh — ejecuta una auditoría que necesita navegador.
#
#   tools/ui/browser-audit.sh dark     [url] [opciones]
#   tools/ui/browser-audit.sh contrast [url] [opciones]
#
# Busca, en este orden:
#   1. Node con Playwright en el equipo (nada que hacer si ya lo tienes).
#   2. Docker con la imagen oficial de Playwright. **No instala nada en el
#      equipo**: los navegadores ya vienen en la imagen y el paquete
#      `playwright-core` se instala en un volumen de Docker la primera vez
#      (queda cacheado; las siguientes ejecuciones no descargan nada).
#   3. Si no hay ninguna de las dos, lo dice y termina con 0: es una
#      comprobación opcional (la parte automática la cubre `make theme`).
#
# Ojo: la imagen oficial *no* trae el paquete de Node. Su Dockerfile instala
# `playwright-core`, hornea los navegadores en /ms-playwright y borra el
# paquete. Por eso aquí se instala aparte; si no, `require('playwright')`
# falla aunque la imagen esté descargada.
#
# Variables:
#   OST_PLAYWRIGHT_IMAGE   imagen a usar (por defecto
#                          mcr.microsoft.com/playwright:v1.47.2-jammy)
#   OST_PLAYWRIGHT_VERSION versión del paquete (por defecto 1.47.2; debe
#                          coincidir con la de la imagen)
#   OST_PLAYWRIGHT_CACHE   volumen donde queda instalado el paquete
#                          (por defecto osticket-playwright)
#   OST_NO_SANDBOX=1       fuerza --no-sandbox (ya se activa como root)
#   OST_FORCE_DOCKER=1     usa el contenedor aunque haya Playwright fuera
# ---------------------------------------------------------------------------
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
kind=${1:-}
[ -n "$kind" ] && shift

case "$kind" in
    dark)     script="tools/ui/dark-audit.js";     label="dark-audit" ;;
    contrast) script="tools/ui/contrast-audit.js"; label="contrast-audit" ;;
    *) echo "uso: $0 dark|contrast [url] [opciones]" >&2; exit 2 ;;
esac

# ¿Hay Node con Playwright a mano? (playwright-core o playwright)
have_node() {
    command -v node >/dev/null 2>&1 || return 1
    node -e "try{require.resolve('playwright-core')}catch(e){try{require.resolve('playwright')}catch(e2){process.exit(1)}}" \
        >/dev/null 2>&1
}

if [ "${OST_FORCE_DOCKER:-0}" != "1" ] && have_node; then
    exec node "$root/$script" "$@"
fi

if command -v docker >/dev/null 2>&1; then
    image=${OST_PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v1.47.2-jammy}
    pw_version=${OST_PLAYWRIGHT_VERSION:-1.47.2}
    cache=${OST_PLAYWRIGHT_CACHE:-osticket-playwright}

    # Las rutas del equipo se traducen a /work y localhost a la puerta del
    # equipo: dentro del contenedor, «localhost» es el propio contenedor.
    total=$#
    i=0
    while [ "$i" -lt "$total" ]; do
        arg=$1
        shift
        case "$arg" in
            *"$root"*)
                before=${arg%%"$root"*}
                after=${arg#*"$root"}
                arg="$before/work$after"
                ;;
        esac
        case "$arg" in
            *localhost*) arg=$(printf '%s' "$arg" | sed 's|localhost|host.docker.internal|g') ;;
        esac
        set -- "$@" "$arg"
        i=$((i + 1))
    done

    [ $# -gt 0 ] || set -- "file:///work/docs/theme-preview.html"

    echo "· sin Playwright en el equipo: usando la imagen de Docker ($image)" >&2

    # $1 = versión del paquete, $2… = node y sus argumentos.
    set +e
    docker run --rm -i \
        -v "$root:/work" \
        -v "$cache:/pwnode" \
        -w /work \
        --add-host host.docker.internal:host-gateway \
        -e PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
        "$image" sh -c '
            if [ ! -d /pwnode/node_modules/playwright-core ]; then
                echo "· instalando playwright-core $1 en el volumen de caché (sólo la primera vez)" >&2
                mkdir -p /pwnode
                cd /pwnode
                [ -f package.json ] || npm init -y >/dev/null 2>&1
                if ! npm install --no-save --no-fund --no-audit "playwright-core@$1" >&2; then
                    echo "· no se pudo instalar playwright-core (¿sin red?). Se omite la auditoría." >&2
                    exit 3
                fi
            fi
            export NODE_PATH="/pwnode/node_modules:/usr/lib/node_modules:/usr/local/lib/node_modules"
            shift
            exec node "$@"
        ' _ "$pw_version" "/work/$script" "$@"
    status=$?
    set -e

    case "$status" in
        0) exit 0 ;;
        3)
            echo "" >&2
            echo "La auditoría no se ejecutó (sin red para el paquete). El resto de" >&2
            echo "comprobaciones sigue en pie: make theme." >&2
            exit 0
            ;;
        2)
            echo "" >&2
            echo "No se pudo abrir Chromium (ver el mensaje de arriba). Es opcional:" >&2
            echo "el resto de comprobaciones sigue en pie con make theme." >&2
            exit 0
            ;;
        *) exit "$status" ;;
    esac
fi

cat >&2 <<MSG
· $label omitido: no hay Node con Playwright ni Docker.
  Es una comprobación opcional; la parte automática la cubre \`make theme\`.
  Para hacerla con Docker:  make docker-$label
MSG
exit 0
