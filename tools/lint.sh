#!/usr/bin/env bash
#
# tools/lint.sh — comprobación de sintaxis de todos los archivos PHP
#
# Uso:
#   tools/lint.sh              # todo el proyecto
#   tools/lint.sh include scp  # solo esos directorios
#   PHP_BIN=php8.3 tools/lint.sh
#
# Salida: una línea por error y un resumen. Código de salida 1 si hay errores.
set -uo pipefail

PHP_BIN="${PHP_BIN:-php}"
JOBS="${JOBS:-$( (command -v nproc >/dev/null && nproc) || echo 4 )}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "ERROR: no se encontró el binario PHP ('$PHP_BIN')." >&2
    echo "       Instala PHP CLI o exporta PHP_BIN=/ruta/php" >&2
    exit 2
fi

# Código de terceros que no mantenemos: se excluye del lint.
EXCLUDES=(
    "*/include/pear/*"
    "*/include/fpdf/*"
    "*/include/mpdf/*"
    "*/include/laminas-mail/*"
    "*/.git/*"
    "*/vendor/*"
    "*/node_modules/*"
)

if [ "$#" -gt 0 ]; then
    TARGETS=("$@")
else
    TARGETS=(".")
fi

FIND_ARGS=()
for target in "${TARGETS[@]}"; do
    FIND_ARGS+=("$ROOT/$target")
done

TMP_FILES="$(mktemp)"
TMP_ERRORS="$(mktemp)"
trap 'rm -f "$TMP_FILES" "$TMP_ERRORS"' EXIT

FIND_CMD=(find "${FIND_ARGS[@]}" -type f -name '*.php')
for pattern in "${EXCLUDES[@]}"; do
    FIND_CMD+=(-not -path "$pattern")
done

"${FIND_CMD[@]}" -print0 > "$TMP_FILES"

TOTAL=$(tr -cd '\0' < "$TMP_FILES" | wc -c)
if [ "$TOTAL" -eq 0 ]; then
    echo "No se encontraron archivos PHP en: ${TARGETS[*]}"
    exit 0
fi

echo "Comprobando sintaxis de $TOTAL archivos PHP con $PHP_BIN ($JOBS en paralelo)..."

lint_one() {
    local file="$1"
    local out
    out="$("$PHP_BIN" -l "$file" 2>&1)" || {
        printf '%s\n' "$out" | sed "s#^$ROOT/##"
        return 1
    }
    return 0
}
export -f lint_one
export PHP_BIN ROOT

# xargs devuelve 123 si algún proceso hijo falla
tr '\0' '\n' < "$TMP_FILES" \
    | xargs -r -P "$JOBS" -I{} bash -c 'lint_one "$@"' _ {} > "$TMP_ERRORS" 2>&1
status=$?

if [ -s "$TMP_ERRORS" ]; then
    cat "$TMP_ERRORS"
fi

if [ "$status" -ne 0 ]; then
    echo "✗ Errores de sintaxis encontrados."
    exit 1
fi

echo "✓ Sintaxis correcta en $TOTAL archivos."
exit 0
