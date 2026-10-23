#!/bin/sh
# ---------------------------------------------------------------------------
# tools/ui/browser-audit.sh — ejecuta una auditoría que necesita navegador.
#
#   tools/ui/browser-audit.sh dark   [url] [opciones]
#   tools/ui/browser-audit.sh contrast [url] [opciones]
#
# Busca, en este orden:
#   1. Node con Playwright en el equipo (nada que instalar si ya lo tienes).
#   2. Docker, con la imagen oficial de Playwright. **No instala nada en el
#      equipo**: la imagen trae Chromium y sus dependencias.
#   3. Si no hay ninguna de las dos, lo dice y termina con 0: es una
#      comprobación opcional (la parte automática la cubre `make theme`).
#
# Variables:
#   OST_PLAYWRIGHT_IMAGE   imagen a usar (por defecto
#                          mcr.microsoft.com/playwright:v1.47.2-jammy)
#   OST_NO_SANDBOX=1       fuerza --no-sandbox (ya se activa como root)
#   OST_FORCE_DOCKER=1     usa el contenedor aunque haya Playwright en el equipo
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

    # La imagen monta el proyecto en /work: las rutas del equipo se traducen.
    # Si la URL apunta a la máquina (localhost), dentro del contenedor se llama
    # host.docker.internal.
    set -- "$@"
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

    set +e
    docker run --rm -i \
        -v "$root:/work" -w /work \
        --add-host host.docker.internal:host-gateway \
        -e PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
        -e NODE_PATH=/usr/lib/node_modules:/usr/local/lib/node_modules \
        "$image" node "/work/$script" "$@"
    status=$?
    set -e

    # 2 = el script no encontró Playwright dentro del contenedor: se avisa y
    # se da por omitida (no es un fallo del proyecto).
    if [ "$status" -eq 2 ]; then
        echo "" >&2
        echo "La imagen no trae el paquete de Playwright en la ruta esperada." >&2
        echo "Prueba otra versión con OST_PLAYWRIGHT_IMAGE=… o ejecútalo en el equipo." >&2
        exit 0
    fi

    exit "$status"
fi

cat >&2 <<MSG
· $label omitido: no hay Node con Playwright ni Docker.
  Es una comprobación opcional; la parte automática la cubre \`make theme\`.
  Para hacerla con Docker:  make docker-$label
MSG
exit 0
