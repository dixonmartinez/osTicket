<?php
/**
 * include/class.db.origin.php
 *
 * De dónde salió una consulta SQL (para el perfilador, `php manage.php perf`).
 *
 * Un informe que dice «este paso hace 13 consultas» obliga a leer el código
 * para adivinar cuáles. Con el origen se ve la línea exacta que las dispara,
 * que es lo que permite arreglarlo. Vive en su propio archivo, sin
 * dependencias, para poder probarlo sin cargar `mysqli.php`.
 *
 * La pila se recorre **después** de parar el cronómetro de la consulta: el
 * coste de recorrerla no se cuenta como tiempo de base de datos.
 */

class DbSource {

    /**
     * Marcos que no son «la aplicación»: el propio acceso a datos y el ORM.
     * Lo que interesa es la primera línea de código de osTicket que pidió los
     * datos, no el `execute()` del motor.
     */
    static function isInternal($file) {
        $base = basename($file);
        return $base === 'mysqli.php'
            || strpos($base, 'class.orm') === 0
            || $base === 'class.db.origin.php';
    }

    /**
     * Primer marco que no es del acceso a datos, como `ruta:línea`.
     *
     * @param array $frames  lo que devuelve `debug_backtrace()`
     * @return string|null
     */
    static function fromFrames($frames) {
        foreach ($frames as $frame) {
            if (!isset($frame['file']))
                continue;
            if (self::isInternal($frame['file']))
                continue;
            return $frame['file'] . ':'
                . (isset($frame['line']) ? (int) $frame['line'] : 0);
        }
        return null;
    }

    /** El origen de la consulta que se está ejecutando ahora mismo. */
    static function current() {
        return self::fromFrames(
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16));
    }
}
