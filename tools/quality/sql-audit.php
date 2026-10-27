#!/usr/bin/env php
<?php
/**
 * tools/quality/sql-audit.php
 *
 * Radiografía del SQL escrito a mano (frente 3 de docs/CODE_QUALITY.md).
 *
 * El frente 3 no es «quitar los 180 `db_query()`»: una parte no se puede
 * migrar y otra no se debe. Esta herramienta separa las tres cosas para que
 * el número signifique algo:
 *
 *   - **driver**: `include/mysqli.php` es la capa que ejecuta el SQL. Ahí
 *     `db_query()` es el trabajo, no la deuda.
 *   - **installer / upgrader**: `setup/` e `include/upgrader/` corren contra
 *     esquemas que ya no son los de hoy (instalación desde cero, migraciones
 *     históricas). El ORM describe el esquema actual, así que usarlo ahí
 *     rompería las actualizaciones. No se tocan.
 *   - **tests**: dobles y mocks.
 *   - **app**: el resto. Esto es lo que el frente 3 mide y lo que puede bajar.
 *
 * Además, cuenta las llamadas que se leen de un tirón (cadena literal) y las
 * que se construyen concatenando variables, y **falla siempre** si alguna
 * concatena datos de la petición sin escapar (eso además lo vigila
 * `make audit` con OST-SQLI; aquí se comprueba en el punto de llamada).
 *
 * Uso:
 *   php tools/quality/sql-audit.php                # informe
 *   php tools/quality/sql-audit.php --check        # falla si sube la deuda
 *   php tools/quality/sql-audit.php --update       # consolida la bajada
 *   php tools/quality/sql-audit.php --json
 *   php tools/quality/sql-audit.php --top=25
 */

require_once __DIR__ . '/lib/source.php';

/**
 * Análisis sin efectos, para poder probarlo desde tests/SqlAuditTest.php.
 */
class SqlAudit {

    /** El objetivo del frente 3, en llamadas de la aplicación. */
    const TARGET = 100;

    const PATTERN = '/\bdb_query\s*\(/';

    /**
     * Marca para el SQL que no puede pasar por el ORM, con su motivo:
     *
     *     // sql-audit:legacy hace SHOW TABLE STATUS, no hay modelo
     *     db_query($sql);
     *
     * Se revisa la línea de la llamada y las dos anteriores. Lo marcado no
     * cuenta para el objetivo, pero sí se informa: una marca sin motivo se
     * rechaza.
     */
    const LEGACY = '/sql-audit:legacy\b\s*(.*)$/m';

    /**
     * Tope de marcas admitidas.
     *
     * La marca es una excepción razonada, no una salida: si se pudiera marcar
     * todo, el contador volvería a no significar nada. Al pasar del tope hay
     * que justificarlo en docs/CODE_QUALITY.md y subirlo a mano.
     *
     * 25 → 27: el sembrador (`include/cli/modules/seed.php`) suma dos marcas
     * al llegar sus módulos: el borrado de filas de hilo sin dueño es un
     * `DELETE` con `LEFT JOIN`, que el ORM no sabe expresar, y `scalar()` es
     * el ayudante de sólo lectura de las comprobaciones del doctor. El motivo
     * largo está en docs/CODE_QUALITY.md (frente 3).
     */
    const LEGACY_MAX = 27;

    /** Categorías, de la que no se toca a la que sí. */
    static function categories() {
        return array('app', 'installer', 'upgrader', 'driver', 'tests');
    }

    /**
     * A qué categoría pertenece un archivo.
     */
    static function categoryOf($rel) {
        $rel = ltrim((string) $rel, '/');

        if ($rel === 'include/mysqli.php')
            return 'driver';
        if (strpos($rel, 'include/upgrader/') === 0)
            return 'upgrader';
        if (strpos($rel, 'setup/') === 0)
            return 'installer';
        if (strpos($rel, 'tests/') === 0)
            return 'tests';

        return 'app';
    }

    /**
     * Cada llamada real del fuente: línea, texto del SQL y cómo está escrita.
     *
     * Los comentarios no cuentan (`class.cron.php` tiene seis `OPTIMIZE TABLE`
     * comentados desde hace años; contarlos daba una deuda que no existe).
     *
     * @return array  lista de array('line', 'sql', 'kind', 'built', 'request')
     */
    static function callsIn($source) {
        $original = (string) $source;
        $source = PhpSource::withoutComments($source);
        $calls = array();

        if (!preg_match_all(self::PATTERN, $source, $matches, PREG_OFFSET_CAPTURE))
            return $calls;

        foreach ($matches[0] as $match) {
            $start = $match[1] + strlen($match[0]);
            $sql = self::argumentAt($source, $start);
            $line = substr_count(substr($source, 0, $match[1]), "\n") + 1;

            // `db_query($sql)`: el SQL está unas líneas más arriba. Se sigue
            // la variable para poder decir qué sentencia es y, sobre todo,
            // si se construyó con datos de la petición.
            $inferred = self::inferSql($source, $match[1], $sql);
            $checked = $sql . ($inferred ? ' ' . $inferred : '');
            list($legacy, $reason) = self::legacyMark($original, $line);

            $calls[] = array(
                'line' => $line,
                'sql' => $sql,
                'kind' => self::kindOf($checked),
                'built' => self::isBuilt($checked),
                'request' => self::hasRequestData($checked),
                'legacy' => $legacy,
                'reason' => $reason,
            );
        }

        return $calls;
    }

    /**
     * Si la llamada recibe una variable (`db_query($sql)`), reconstruye cómo
     * se llenó: sube por las líneas anteriores juntando `$sql .= ...` hasta
     * el `$sql = ...` que la empieza.
     *
     * Es una heurística de texto, no un análisis de flujo: sirve para
     * clasificar y para avisar, no para demostrar nada. Las pruebas la fijan.
     */
    static function inferSql($source, $offset, $argument) {
        if (!preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', trim($argument), $m))
            return '';

        $var = $m[1];
        $before = substr($source, 0, $offset);
        $lines = explode("\n", $before);

        $sql = '';
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = $lines[$i];
            if (!preg_match('/^\s*\$' . preg_quote($var, '/')
                    . '\s*(\.?=)\s*(.*)$/', $line, $assign))
                continue;

            $sql = $assign[2] . ' ' . $sql;
            if ($assign[1] === '=')
                break;
        }

        return trim($sql);
    }

    /**
     * ¿La llamada está marcada como no migrable? Devuelve [bool, motivo].
     *
     * El motivo es obligatorio: una marca vacía no vale, porque la idea es
     * que dentro de un año alguien sepa por qué ese SQL se queda.
     */
    static function legacyMark($source, $line, $window=3) {
        // $source es el original, con comentarios: la marca vive en ellos.
        $lines = explode("\n", $source);
        $from = max(0, $line - $window);

        foreach (array_reverse(range($from, $line - 1)) as $i) {
            // $i es número de línea (1-based); el array es 0-based
            if (isset($lines[$i - 1]) && preg_match(self::LEGACY, $lines[$i - 1], $m))
                return array(true, trim($m[1]));
        }

        return array(false, '');
    }

    /** Cuántas llamadas reales hay en un fuente. */
    static function countCalls($source) {
        return count(self::callsIn($source));
    }

    /** Texto del argumento, equilibrando paréntesis. */
    static function argumentAt($source, $offset) {
        $depth = 1;
        $out = '';
        $length = strlen($source);

        for ($i = $offset; $i < $length && $depth; $i++) {
            $char = $source[$i];
            if ($char === '(')
                $depth++;
            elseif ($char === ')') {
                $depth--;
                if (!$depth)
                    break;
            }
            $out .= $char;
        }

        return trim($out);
    }

    /**
     * ¿Qué tipo de sentencia es?
     *
     * Se busca la primera palabra clave del texto, no la primera del
     * principio: el SQL casi siempre empieza por `$sql='` o por una
     * comparación, y lo que importa es qué sentencia es.
     */
    static function kindOf($sql) {
        $text = strtoupper((string) $sql);
        $groups = array(
            'select' => array('SELECT', 'SHOW'),
            'dml' => array('INSERT', 'REPLACE', 'UPDATE', 'DELETE'),
            'ddl' => array('CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'OPTIMIZE',
                'REPAIR', 'SET ', 'ANALYZE'),
        );

        $best = null;
        $bestAt = PHP_INT_MAX;
        foreach ($groups as $kind => $keywords) {
            foreach ($keywords as $kw) {
                if (preg_match('/\b' . trim($kw) . '\b/', $text, $m, PREG_OFFSET_CAPTURE)
                        && $m[0][1] < $bestAt) {
                    $bestAt = $m[0][1];
                    $best = $kind;
                }
            }
        }

        return $best ?: 'other';
    }

    /** ¿El SQL se construye concatenando, o es una cadena literal? */
    static function isBuilt($sql) {
        // Un `$` suelto puede ser una variable; un punto, una concatenación
        return strpos($sql, '$') !== false || strpos($sql, ' . ') !== false;
    }

    /**
     * ¿Concatena datos de la petición?
     *
     * Es la regla dura del frente 3: cero. `db_input()` alrededor no basta
     * para colar un valor aquí sin revisarlo; si hay que hacerlo a mano, se
     * marca con `// audit:ignore OST-SQLI -- motivo` y se explica.
     */
    static function hasRequestData($sql) {
        if (!preg_match('/\$_(?:GET|POST|REQUEST|COOKIE|FILES)\b/', $sql))
            return false;

        // Escapado explícito en la misma expresión
        return !preg_match('/db_input\s*\(|db_real_escape|db_escape|intval\s*\(|\(\s*int\s*\)/',
            $sql);
    }

    /**
     * Recorre el código y devuelve, por archivo, sus llamadas.
     *
     * @return array  ['app' => [archivo => [llamadas]], 'driver' => ...]
     */
    static function scan($root, array $areas, array $skipDirs) {
        $scan = array_fill_keys(self::categories(), array());

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
                if (self::skipped($rel, $skipDirs))
                    continue;

                $calls = self::callsIn(@file_get_contents($file->getPathname()));
                if (!$calls)
                    continue;

                $category = self::categoryOf($rel);
                $scan[$category][$rel] = $calls;
            }
        }

        foreach ($scan as $category => $files)
            ksort($scan[$category]);

        return $scan;
    }

    /** ¿Está en una carpeta de librerías copiadas o de configuración? */
    static function skipped($rel, array $skipDirs) {
        foreach ($skipDirs as $skip) {
            if ($rel === $skip || strpos($rel, $skip . '/') === 0)
                return true;
        }

        return false;
    }

    /** Cuántas llamadas hay por categoría. */
    static function totals(array $scan) {
        $totals = array();
        foreach ($scan as $category => $files)
            $totals[$category] = array_sum(array_map('count', $files));

        return $totals;
    }

    /** Contadores de una categoría: por tipo y literal/construida. */
    static function breakdown(array $files) {
        $out = array('select' => 0, 'dml' => 0, 'ddl' => 0, 'other' => 0,
            'built' => 0, 'literal' => 0, 'request' => 0, 'legacy' => 0);

        foreach ($files as $calls) {
            foreach ($calls as $call) {
                if ($call['legacy']) {
                    $out['legacy']++;
                    continue;
                }
                $out[$call['kind']]++;
                $out[$call['built'] ? 'built' : 'literal']++;
                if ($call['request'])
                    $out['request']++;
            }
        }

        return $out;
    }

    /** Llamadas que concatenan datos de la petición, para el informe. */
    static function unsafe(array $scan) {
        $found = array();
        foreach ($scan as $category => $files) {
            // Los dobles de prueba contienen SQL mal escrito a propósito: es
            // su trabajo. Se ignoran aquí; en el código real no pasa.
            if ($category === 'tests')
                continue;
            foreach ($files as $file => $calls) {
                foreach ($calls as $call) {
                    if ($call['request'])
                        $found[] = array('file' => $file,
                            'line' => $call['line'], 'sql' => $call['sql']);
                }
            }
        }

        return $found;
    }

    /**
     * ¿Se ha movido la deuda? Igual que en `input-audit`: no puede subir.
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

    /**
     * De un informe por archivo a un recuento por archivo.
     *
     * Por defecto no cuenta lo marcado como `sql-audit:legacy`, que es lo que
     * se compara con la línea base y con el objetivo.
     */
    static function counts(array $files, $includeLegacy=false) {
        $counts = array();
        foreach ($files as $file => $calls) {
            $count = 0;
            foreach ($calls as $call)
                if ($includeLegacy || !$call['legacy'])
                    $count++;
            if ($count)
                $counts[$file] = $count;
        }

        return $counts;
    }

    /** Marcas sin motivo: una marca vacía no explica nada y es un fallo. */
    static function badMarks(array $scan) {
        return array_values(array_filter(self::legacy($scan),
            function ($mark) { return $mark['reason'] === ''; }));
    }

    /** Llamadas marcadas como no migrables, para el informe. */
    static function legacy(array $scan) {
        $found = array();
        foreach ($scan as $files) {
            foreach ($files as $file => $calls) {
                foreach ($calls as $call) {
                    if ($call['legacy'])
                        $found[] = array('file' => $file,
                            'line' => $call['line'], 'reason' => $call['reason']);
                }
            }
        }

        return $found;
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

function sqlaudit_main($root, array $argv) {
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
        echo "sql-audit — SQL escrito a mano\n\n"
            . "  php tools/quality/sql-audit.php [--check] [--update]\n"
            . "      [--json] [--top=N]\n\n"
            . "  --check   código de salida 1 si la deuda de la aplicación sube\n"
            . "  --update  reescribe la línea base con lo medido\n";
        return 0;
    }

    $areas = array('include', 'scp', 'api', 'setup', 'tests', 'tools');
    $skipDirs = array('vendor', 'mpdf', 'pear', 'laminas-mail', 'oauth2',
        'plugins', 'lib', 'test/tests');

    $scan = SqlAudit::scan($root, $areas, $skipDirs);
    $totals = SqlAudit::totals($scan);
    $appAll = SqlAudit::counts($scan['app'], true);
    $app = SqlAudit::counts($scan['app']);
    $appTotal = array_sum($app);
    $legacyMarks = SqlAudit::legacy(array('app' => $scan['app']));
    $badMarks = SqlAudit::badMarks(array('app' => $scan['app']));
    $tooManyMarks = count($legacyMarks) > SqlAudit::LEGACY_MAX;
    $baselinePath = "$root/tools/quality/sql-baseline.json";
    $baseline = SqlAudit::load($baselinePath);
    $result = SqlAudit::compare($app, $baseline);
    $unsafe = SqlAudit::unsafe($scan);

    if (isset($options['update'])) {
        if (!SqlAudit::save($baselinePath, $app)) {
            fwrite(STDERR, "No se pudo escribir $baselinePath\n");
            return 1;
        }
        printf("Línea base actualizada: %d llamadas en %d archivos\n",
            $appTotal, count($app));
        return 0;
    }

    if (isset($options['json'])) {
        echo json_encode(array(
            'totals' => $totals,
            'app' => array(
                'total' => $appTotal,
                'all' => array_sum($appAll),
                'target' => SqlAudit::TARGET,
                'breakdown' => SqlAudit::breakdown($scan['app']),
                'files' => $app,
            ),
            'ok' => $result['ok'] && !$unsafe && !$badMarks && !$tooManyMarks,
            'added' => $result['added'],
            'grown' => $result['grown'],
            'shrunk' => $result['shrunk'],
            'unsafe' => $unsafe,
            'legacy' => $legacyMarks,
            'bad_marks' => $badMarks,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

        return ($result['ok'] && !$unsafe && !$badMarks && !$tooManyMarks)
            ? 0 : 1;
    }

    echo "SQL a mano (llamadas reales, sin contar comentarios)\n\n";
    $labels = array(
        'app' => 'aplicación',
        'installer' => 'instalador',
        'upgrader' => 'actualizador (histórico)',
        'driver' => 'driver de base de datos',
        'tests' => 'dobles de prueba',
    );
    foreach ($labels as $category => $label)
        printf("  %-26s %4d llamadas en %3d archivos\n", $label,
            $totals[$category], count($scan[$category]));

    $breakdown = SqlAudit::breakdown($scan['app']);
    printf("\nAplicación: %d llamadas, %d de ellas marcadas como no migrables"
        . " -> %d por migrar\n", array_sum($appAll), $breakdown['legacy'],
        $appTotal);
    printf("  %d SELECT, %d DML, %d DDL, %d otras\n", $breakdown['select'],
        $breakdown['dml'], $breakdown['ddl'], $breakdown['other']);
    printf("  %d con el SQL escrito de un tirón, %d construidas concatenando\n",
        $breakdown['literal'], $breakdown['built']);
    printf("  objetivo del frente 3: %d  (faltan %d)\n", SqlAudit::TARGET,
        max(0, $appTotal - SqlAudit::TARGET));

    if ($baseline) {
        $before = array_sum($baseline);
        printf("  línea base: %d  (%+d)\n", $before, $appTotal - $before);
    } else {
        echo "  sin línea base todavía"
            . " (php tools/quality/sql-audit.php --update)\n";
    }

    $top = $options['top'] ?? 10;
    echo "\nArchivos de la aplicación con más SQL a mano\n";
    arsort($app);
    $i = 0;
    foreach ($app as $file => $count) {
        if ($i++ >= $top)
            break;
        printf("  %4d  %s\n", $count, $file);
    }

    if ($unsafe) {
        echo "\nSQL construido con datos de la petición sin escapar\n";
        foreach ($unsafe as $call)
            printf("  %s:%d  %s\n", $call['file'], $call['line'],
                substr(preg_replace('/\s+/', ' ', $call['sql']), 0, 80));
    }

    if ($result['added']) {
        echo "\nArchivos nuevos con SQL a mano\n";
        foreach ($result['added'] as $file => $count)
            printf("  %s (%d)\n", $file, $count);
    }

    if ($result['grown']) {
        echo "\nArchivos que han subido\n";
        foreach ($result['grown'] as $file => $counts)
            printf("  %s: %d -> %d\n", $file, $counts[0], $counts[1]);
    }

    if ($legacyMarks) {
        echo "\nMarcado como no migrable (\"sql-audit:legacy\" con motivo)\n";
        foreach ($legacyMarks as $mark)
            printf("  %s:%d  %s\n", $mark['file'], $mark['line'],
                $mark['reason']);
    }

    if ($tooManyMarks) {
        fwrite(STDERR, sprintf(
            "\nFalla: %d marcas sql-audit:legacy, el tope es %d\n",
            count($legacyMarks), SqlAudit::LEGACY_MAX));
        return 1;
    }

    if ($badMarks) {
        echo "\nMarcas sin motivo\n";
        foreach ($badMarks as $mark)
            printf("  %s:%d\n", $mark['file'], $mark['line']);
        fwrite(STDERR, "\nFalla: cada marca sql-audit:legacy necesita un motivo\n");
        return 1;
    }

    if ($unsafe) {
        fwrite(STDERR, "\nFalla: hay SQL con datos de la petición sin escapar\n");
        return 1;
    }

    if (!$result['ok']) {
        fwrite(STDERR, "\nFalla: el SQL a mano de la aplicación no puede subir"
            . " (docs/CODE_QUALITY.md, frente 3)\n");
        return 1;
    }

    if ($baseline)
        echo "\nSin subidas: ningún archivo nuevo con SQL a mano.\n";

    return 0;
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "Sólo se ejecuta desde la línea de comandos\n");
        exit(2);
    }
    exit(sqlaudit_main(dirname(__DIR__, 2), $argv ?? []));
}
