#!/usr/bin/env php
<?php
/**
 * tools/quality/input-audit.php
 *
 * Vigila los accesos directos a `$_GET`, `$_POST` y `$_REQUEST` (frente 4 de
 * docs/CODE_QUALITY.md).
 *
 * El frente 4 no propone migrar los 1.260 accesos uno a uno: propone una
 * fachada (`Http::get()`, `Http::post()`, `Http::postInt()`…) para el código
 * nuevo, y una regla —el contador **nunca sube**— para que el código viejo se
 * vaya migrando sólo cuando se toca por otro motivo.
 *
 * Esta herramienta es la que hace cumplir esa regla:
 *
 *   - `--check` compara con la línea base (`tools/quality/input-baseline.json`)
 *     y falla si aparece un archivo nuevo con accesos directos o si alguno
 *     tiene más que antes.
 *   - `--update` reescribe la línea base. Se hace cuando un archivo baja (el
 *     refactor se consolida), nunca para tapar una subida.
 *
 * La única excepción es la propia fachada: `include/class.http.php` es el
 * sitio donde leer esas variables es el trabajo, así que no se cuenta.
 *
 * Uso:
 *   php tools/quality/input-audit.php                 # informe
 *   php tools/quality/input-audit.php --check         # falla si sube
 *   php tools/quality/input-audit.php --update        # consolida la bajada
 *   php tools/quality/input-audit.php --json
 *   php tools/quality/input-audit.php --top=25
 */

/**
 * Análisis y comparación, sin efectos, para poder probarlo desde
 * tests/InputAuditTest.php.
 */
class InputAudit {

    /** Lo mismo que cuenta `make metrics`, para que los números cuadren. */
    const PATTERN = '/\$_(?:POST|REQUEST|GET)\s*\[/';

    /** El único archivo donde leer estas variables es su trabajo. */
    const FACADE = 'include/class.http.php';

    /** Accesos directos en un fuente. */
    static function countIn($source) {
        return preg_match_all(self::PATTERN, (string) $source);
    }

    /**
     * Qué archivos tienen accesos y cuántos.
     *
     * @return array  ruta relativa => número de accesos directos
     */
    static function scan($root, array $areas, array $skipDirs) {
        $counts = array();

        foreach ($areas as $area) {
            $base = "$root/$area";
            if (!is_dir($base))
                continue;

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base,
                    FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php')
                    continue;

                $rel = substr($file->getPathname(), strlen($root) + 1);
                if (self::skipped($rel, $skipDirs)
                        || $rel === self::FACADE)
                    continue;

                $count = self::countIn(@file_get_contents($file->getPathname()));
                if ($count)
                    $counts[$rel] = $count;
            }
        }

        ksort($counts);

        return $counts;
    }

    /** ¿Está en una carpeta que no es código propio? */
    static function skipped($rel, array $skipDirs) {
        foreach ($skipDirs as $skip) {
            if ($rel === $skip || strpos($rel, $skip . '/') === 0
                    || strpos($rel, '/' . $skip . '/') !== false)
                return true;
        }

        return false;
    }

    /**
     * Compara lo medido con la línea base.
     *
     * @return array  ['ok' => bool, 'added' => [], 'grown' => [], 'shrunk' => []]
     */
    static function compare(array $current, array $baseline) {
        $added = $grown = $shrunk = array();

        foreach ($current as $file => $count) {
            if (!isset($baseline[$file]))
                $added[$file] = $count;
            elseif ($count > $baseline[$file])
                $grown[$file] = array($baseline[$file], $count);
            elseif ($count < $baseline[$file])
                $shrunk[$file] = array($baseline[$file], $count);
        }

        foreach ($baseline as $file => $count) {
            if (!isset($current[$file]))
                $shrunk[$file] = array($count, 0);
        }

        return array(
            'ok' => !$added && !$grown,
            'added' => $added,
            'grown' => $grown,
            'shrunk' => $shrunk,
        );
    }

    static function load($path) {
        if (!is_file($path))
            return array();

        $data = json_decode(file_get_contents($path), true);

        return is_array($data) ? $data : array();
    }

    static function save($path, array $counts) {
        ksort($counts);

        return file_put_contents($path,
            json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                . "\n") !== false;
    }
}

/* ------------------------------------------------------------------ */
/* Línea de comandos                                                  */
/* ------------------------------------------------------------------ */

function inputaudit_main($root, array $argv) {
    $options = array();
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--check')
            $options['check'] = true;
        elseif ($arg === '--update')
            $options['update'] = true;
        elseif ($arg === '--json')
            $options['json'] = true;
        elseif ($arg === '--help' || $arg === '-h')
            $options['help'] = true;
        elseif (strpos($arg, '--top=') === 0)
            $options['top'] = max(1, (int) substr($arg, 6));
    }

    if (isset($options['help'])) {
        echo "input-audit — accesos directos a \$_GET, \$_POST y \$_REQUEST\n\n"
            . "  php tools/quality/input-audit.php [--check] [--update]\n"
            . "      [--json] [--top=N]\n\n"
            . "  --check   código de salida 1 si el contador sube\n"
            . "  --update  reescribe la línea base con lo medido\n";
        return 0;
    }

    $areas = array('include', 'scp', 'api', 'setup', 'tools');
    // Librerías copiadas y dobles del instalador: no son código propio.
    $skipDirs = array('vendor', 'mpdf', 'pear', 'laminas-mail', 'oauth2',
        'plugins', 'lib', 'test/tests');

    $current = InputAudit::scan($root, $areas, $skipDirs);
    $total = array_sum($current);
    $baselinePath = "$root/tools/quality/input-baseline.json";
    $baseline = InputAudit::load($baselinePath);
    $result = InputAudit::compare($current, $baseline);

    if (isset($options['update'])) {
        if (!InputAudit::save($baselinePath, $current)) {
            fwrite(STDERR, "No se pudo escribir $baselinePath\n");
            return 1;
        }
        printf("Línea base actualizada: %d accesos en %d archivos\n",
            $total, count($current));
        return 0;
    }

    if (isset($options['json'])) {
        echo json_encode(array(
            'total' => $total,
            'files' => count($current),
            'ok' => $result['ok'],
            'added' => $result['added'],
            'grown' => $result['grown'],
            'shrunk' => $result['shrunk'],
            'counts' => $current,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

        return $result['ok'] ? 0 : 1;
    }

    printf("Accesos directos a \$_GET, \$_POST y \$_REQUEST: %d en %d archivos\n",
        $total, count($current));

    if ($baseline) {
        $before = array_sum($baseline);
        printf("Línea base: %d  (%+d)\n", $before, $total - $before);
    } else {
        echo "No hay línea base todavía (php tools/quality/input-audit.php"
            . " --update)\n";
    }

    $top = $options['top'] ?? 10;
    echo "\nArchivos con más accesos\n";
    arsort($current);
    $i = 0;
    foreach ($current as $file => $count) {
        if ($i++ >= $top)
            break;
        printf("  %4d  %s\n", $count, $file);
    }

    if ($result['added']) {
        echo "\nArchivos nuevos con acceso directo (usa Http::get/post)\n";
        foreach ($result['added'] as $file => $count)
            printf("  %s (%d)\n", $file, $count);
    }

    if ($result['grown']) {
        echo "\nArchivos que han subido\n";
        foreach ($result['grown'] as $file => $counts)
            printf("  %s: %d -> %d\n", $file, $counts[0], $counts[1]);
    }

    if ($result['shrunk'] && isset($options['check']))
        echo "\nHay archivos por debajo de la línea base: consolida con"
            . " --update\n";

    if (!$result['ok']) {
        fwrite(STDERR, "\nEl contador no puede subir: migra a la fachada"
            . " Http (docs/CODE_QUALITY.md, frente 4)\n");
        return 1;
    }

    if ($baseline)
        echo "\nSin subidas: ningún archivo nuevo con acceso directo.\n";

    return 0;
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "Sólo se ejecuta desde la línea de comandos\n");
        exit(2);
    }
    exit(inputaudit_main(dirname(__DIR__, 2), $argv ?? []));
}
