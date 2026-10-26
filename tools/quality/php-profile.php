#!/usr/bin/env php
<?php
/**
 * tools/quality/php-profile.php
 *
 * Lee un perfil de Xdebug (formato «cachegrind») y dice **qué función se lleva
 * el tiempo propio**. Es el escalón siguiente cuando `manage.php perf` ya ha
 * dicho que el tiempo es de PHP y no de SQL: el perfilador por pasos dice
 * «render() tarda 24 ms», esto dice en qué función están esos milisegundos.
 *
 * Cómo se genera el perfil:
 *
 *   php -d xdebug.mode=profile -d xdebug.output_dir=/tmp \
 *       -d xdebug.start_with_request=yes manage.php perf --ticket=405 --json
 *
 *   (o `make ticket-perf-xdebug`)
 *
 * Uso:
 *   php tools/quality/php-profile.php /tmp/cachegrind.out.1234
 *   php tools/quality/php-profile.php --top=30 /tmp/cachegrind.out.1234
 *   php tools/quality/php-profile.php --filter=render /tmp/cachegrind.out.*
 *
 * «Tiempo propio» es el que gasta la función sin contar las llamadas que hace:
 * es lo que hay que mirar para saber dónde está el trabajo de verdad.
 */

if (!class_exists('PhpProfile', false)) {

class PhpProfile {

    /**
     * Lee el texto de un perfil y devuelve el tiempo propio por función.
     *
     * En el formato, cada bloque empieza por `fl=` (archivo) y `fn=` (función)
     * y las líneas siguientes son `línea coste [memoria]`. Cuando antes de una
     * línea aparece `cfn=`/`calls=`, ese coste es el de la **llamada** —incluye
     * el de la función llamada— y no se suma al tiempo propio.
     *
     * @param string $text
     * @return array  nombre con archivo => ['time' => µs, 'calls' => n]
     */
    static function parse($text) {
        $names = array();
        $file = '';
        $fn = '';
        $pendingCall = false;
        $functions = array();

        $resolve = function($value) use (&$names) {
            $value = trim($value);
            if (preg_match('/^\((\d+)\)(?:\s+(.*))?$/s', $value, $m)) {
                $id = $m[1];
                if (isset($m[2]) && $m[2] !== '')
                    return $names[$id] = $m[2];
                return isset($names[$id]) ? $names[$id] : "#$id";
            }
            return $value;
        };

        foreach (explode("\n", $text) as $line) {
            $line = rtrim($line);
            if ($line === '' || $line[0] === '#')
                continue;

            if (strpos($line, 'fl=') === 0) {
                $file = $resolve(substr($line, 3));
                continue;
            }
            if (strpos($line, 'fn=') === 0) {
                $fn = $resolve(substr($line, 3));
                continue;
            }
            if (strpos($line, 'cfl=') === 0 || strpos($line, 'cfn=') === 0)
                continue;
            if (strpos($line, 'calls=') === 0) {
                $pendingCall = true;
                continue;
            }
            // Cabeceras (version:, creator:, cmd:, part:, positions:, events:)
            if (strpos($line, ':') !== false && !preg_match('/^\d/', $line))
                continue;

            // `línea coste [memoria]`
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 2 || !is_numeric($parts[0]))
                continue;

            $cost = (float) $parts[1];
            if ($pendingCall) {
                // Es el coste de la llamada, ya contado en la otra función
                $pendingCall = false;
                continue;
            }

            $key = $fn . ' — ' . $file;
            if (!isset($functions[$key]))
                $functions[$key] = array('time' => 0.0, 'file' => $file,
                    'function' => $fn);
            $functions[$key]['time'] += $cost;
        }

        return $functions;
    }

    /** Las funciones que más tiempo propio suman. */
    static function top(array $functions, $limit = 20) {
        uasort($functions, function($a, $b) {
            return $b['time'] <=> $a['time'];
        });
        return array_slice($functions, 0, $limit, true);
    }

    /** Filtra por nombre de función o de archivo. */
    static function grep(array $functions, $needle) {
        $found = array();
        foreach ($functions as $key => $info)
            if (stripos($key, $needle) !== false)
                $found[$key] = $info;
        return $found;
    }

    static function total(array $functions) {
        $total = 0.0;
        foreach ($functions as $info)
            $total += $info['time'];
        return $total;
    }

    /** Imprime el informe. `$out` es para poder probarlo sin la consola. */
    static function report(array $functions, $limit = 20, $root = '') {
        $total = self::total($functions);
        $lines = array();
        $lines[] = sprintf('Funciones por tiempo propio: %s en %d funciones',
            self::duration($total), count($functions));
        $lines[] = str_repeat('-', 74);

        foreach (self::top($functions, $limit) as $info) {
            $file = $info['file'];
            if ($root && strpos($file, $root) === 0)
                $file = substr($file, strlen(rtrim($root, '/')) + 1);
            $lines[] = sprintf('%10s  %5.1f%%  %-34s %s',
                self::duration($info['time']),
                $total > 0 ? $info['time'] * 100 / $total : 0,
                $info['function'], $file);
        }

        return implode("\n", $lines) . "\n";
    }

    static function duration($microseconds) {
        if ($microseconds >= 1000000)
            return sprintf('%.2f s', $microseconds / 1000000);
        if ($microseconds >= 1000)
            return sprintf('%.1f ms', $microseconds / 1000);
        return sprintf('%d µs', (int) $microseconds);
    }
}
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    $options = getopt('', array('top::', 'filter::', 'help'));

    if (isset($options['help']) || empty($argv[1])) {
        echo "Uso: php tools/quality/php-profile.php [--top=N] [--filter=TEXTO] perfil\n\n";
        echo "  perfil    archivo cachegrind.out.NNN de Xdebug (admite varios)\n";
        exit(isset($options['help']) ? 0 : 2);
    }

    $files = array_slice($argv, 1);
    $functions = array();
    foreach ($files as $file) {
        foreach (glob($file) ?: array() as $one) {
            $text = @file_get_contents($one);
            if ($text === false) {
                fwrite(STDERR, "No se pudo leer $one\n");
                continue;
            }
            foreach (PhpProfile::parse($text) as $key => $info) {
                if (!isset($functions[$key]))
                    $functions[$key] = $info;
                else
                    $functions[$key]['time'] += $info['time'];
            }
        }
    }

    if (!$functions) {
        fwrite(STDERR, "No hay funciones en el perfil (¿está vacío?)\n");
        exit(1);
    }

    if (!empty($options['filter']))
        $functions = PhpProfile::grep($functions, $options['filter']);

    echo PhpProfile::report($functions,
        isset($options['top']) ? (int) $options['top'] : 20, dirname(__DIR__, 2));
}
