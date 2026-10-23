<?php
/**
 * tools/quality/lib/source.php
 *
 * Utilidades para medir código fuente sin contar lo que no es código.
 *
 * El motivo: `make metrics` contaba con expresiones regulares sobre el archivo
 * entero, así que una llamada comentada —`#@db_query('OPTIMIZE ...')`, muy
 * común en `class.cron.php`— sumaba igual que una real. Lo mismo con los
 * accesos a `$_POST` dentro de un comentario. Las cifras salían infladas y,
 * peor, una migración podía "bajar" el número sin cambiar una línea de código.
 *
 * `PhpSource::withoutComments()` devuelve el fuente con los comentarios
 * sustituidos por espacios, conservando **el número de línea** de todo lo
 * demás (los saltos de línea de dentro del comentario se quedan). Así los
 * contadores pueden seguir informando la línea correcta.
 */

class PhpSource {

    /**
     * Fuente sin comentarios, con las líneas en su sitio.
     *
     * Si el tokenizador no puede con el archivo (no debería: `make lint` lo
     * comprueba antes), se devuelve el fuente tal cual. Es una herramienta de
     * medida: mejor contar de más que romper.
     */
    static function withoutComments($source) {
        $source = (string) $source;

        try {
            $tokens = @token_get_all($source);
        } catch (\Throwable $e) {
            return $source;
        }

        if (!$tokens)
            return $source;

        $out = '';
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                $out .= $token;
                continue;
            }

            list($id, $text) = $token;
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                // Mismo número de líneas: se cambia el texto por espacios y
                // se conservan los saltos
                $out .= str_repeat("\n", substr_count($text, "\n"));
                continue;
            }

            $out .= $text;
        }

        return $out;
    }

    /**
     * Lo mismo, pero conservando los espacios del comentario en la misma
     * línea: útil si algún día se quiere informar la columna.
     */
    static function blankComment($text) {
        $blank = preg_replace('/[^\n]/', ' ', $text);

        return str_replace("\r", ' ', $blank);
    }
}
