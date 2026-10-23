#!/usr/bin/env php
<?php
/**
 * tools/quality/class-map.php
 *
 * Mapa de clases del código propio: qué clase vive en qué archivo.
 *
 * Sirve para dos cosas del frente 2 de docs/CODE_QUALITY.md:
 *   1. Repartir archivos con muchas clases (class.queue.php tenía 38) sin
 *      perder ninguna ni declarar dos veces la misma.
 *   2. Detectar duplicados: dos archivos que declaran la misma clase hacen
 *      que la segunda declaración sea un error fatal según el orden de carga,
 *      y eso no lo ve `make lint` (cada archivo es válido por separado).
 *
 * Lee el código con token_get_all, así que no se confunde con `Foo::class`,
 * con clases anónimas (`new class`) ni con nombres dentro de cadenas.
 *
 * Uso:
 *   php tools/quality/class-map.php                 # resumen y mayores archivos
 *   php tools/quality/class-map.php --check         # falla si hay duplicados
 *   php tools/quality/class-map.php --file=include/class.queue.php
 *   php tools/quality/class-map.php --json
 *   php tools/quality/class-map.php --top=10
 *
 * `--check` es lo que ejecuta `make classes`; el resto es informativo.
 */

/**
 * Extracción y análisis. Está en una clase sin efectos para poder probarla
 * desde tests/ClassMapTest.php sin ejecutar la herramienta.
 */
class ClassMap {

    /**
     * Clases, interfaces, rasgos y enumeraciones declaradas en un fuente PHP.
     *
     * Devuelve, por nombre, la línea y si la declaración está protegida con
     * `class_exists()` (polyfills y dobles de prueba: se declaran sólo si no
     * existen, así que no chocan nunca).
     *
     * @return array  nombre => ['line' => int, 'conditional' => bool]
     */
    static function classesIn($source) {
        $found = [];
        $tokens = token_get_all($source);
        $count = count($tokens);
        $namespace = '';

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token))
                continue;

            // El nombre real incluye el namespace: osTicket\Session\Exception
            // y Exception global son clases distintas.
            if ($token[0] === T_NAMESPACE) {
                $namespace = self::namespaceAt($tokens, $i);
                continue;
            }

            if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)
                    && !(defined('T_ENUM') && $token[0] === T_ENUM))
                continue;

            // `Foo::class` no declara nada
            $prev = self::previousSignificant($tokens, $i);
            if ($prev === T_DOUBLE_COLON)
                continue;

            // `new class { ... }` tampoco
            if ($prev === T_NEW)
                continue;

            $name = self::nextSignificantString($tokens, $i);
            if ($name === null)
                continue;

            if ($namespace !== '')
                $name = $namespace . '\\' . $name;

            $found[$name] = [
                'line' => $token[2],
                'conditional' => self::isConditional($tokens, $i),
            ];
        }

        return $found;
    }

    /**
     * ¿La declaración va dentro de un `if (!class_exists('X'))`? Se mira
     * hacia atrás un trecho corto: es una heurística para polyfills, no un
     * análisis de bloques.
     */
    /** Nombre del namespace que empieza en el token T_NAMESPACE indicado. */
    private static function namespaceAt(array $tokens, $i) {
        $count = count($tokens);
        $name = '';
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if ($t === '{' || $t === ';')
                return rtrim($name, '\\');

            $isName = is_array($t) && (
                in_array($t[0], [T_STRING, T_NS_SEPARATOR], true)
                || (defined('T_NAME_QUALIFIED') && $t[0] === T_NAME_QUALIFIED));

            if ($isName)
                $name .= $t[1];
            elseif (!(is_array($t) && $t[0] === T_WHITESPACE))
                return rtrim($name, '\\');
        }
        return rtrim($name, '\\');
    }

    private static function isConditional(array $tokens, $i) {
        $seen = 0;
        for ($j = $i - 1; $j >= 0 && $seen < 60; $j--, $seen++) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_STRING
                    && in_array(strtolower($t[1]), ['class_exists',
                        'interface_exists', 'trait_exists', 'function_exists'],
                        true))
                return true;
            // Una llave de cierre indica que ya estamos en otro bloque
            if ($t === '}' || $t === ';')
                return false;
        }
        return false;
    }

    private static function previousSignificant(array $tokens, $i) {
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT,
                    T_DOC_COMMENT], true))
                continue;
            return is_array($t) ? $t[0] : $t;
        }
        return null;
    }

    private static function nextSignificantString(array $tokens, $i) {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT,
                    T_DOC_COMMENT], true))
                continue;
            return is_array($t) && $t[0] === T_STRING ? $t[1] : null;
        }
        return null;
    }

    /**
     * Recorre directorios y devuelve nombre de clase => lista de sitios.
     *
     * @return array  ['clase' => [['file' => ..., 'line' => ...], ...]]
     */
    static function scan($root, array $areas, array $skipDirs) {
        $map = [];
        foreach (self::phpFiles($root, $areas, $skipDirs) as $rel) {
            $source = @file_get_contents("$root/$rel");
            if ($source === false)
                continue;
            foreach (self::classesIn($source) as $name => $info) {
                $map[$name][] = ['file' => $rel, 'line' => $info['line'],
                    'conditional' => $info['conditional']];
            }
        }
        ksort($map);
        return $map;
    }

    /** Archivos PHP de las áreas, en orden estable. */
    static function phpFiles($root, array $areas, array $skipDirs) {
        $files = [];
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
                $skip = false;
                foreach ($skipDirs as $dir) {
                    if (strpos($rel, "$area/$dir/") === 0) {
                        $skip = true;
                        break;
                    }
                }
                if (!$skip)
                    $files[] = $rel;
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Clases declaradas más de una vez **sin** protección: ésas son las que
     * pueden dar un error fatal según el orden de carga. Los polyfills
     * (`if (!class_exists())`) se devuelven aparte.
     */
    /**
     * Includes con ruta literal: 'class.x.php' o INCLUDE_DIR . 'class.x.php'.
     * Las plantillas y las rutas construidas con variables se ignoran.
     *
     * @return array  lista de ['line' => int, 'path' => string]
     */
    static function includeTargets($source) {
        $targets = [];
        $tokens = token_get_all($source);
        $count = count($tokens);
        $tipos = [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE];

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], $tipos, true))
                continue;

            $literales = [];
            for ($j = $i + 1; $j < $count && $tokens[$j] !== ';'; $j++) {
                $t = $tokens[$j];
                if ($t === '(' || $t === ')' || $t === '.')
                    continue;
                if (is_array($t) && $t[0] === T_WHITESPACE)
                    continue;
                if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING)
                    $literales[] = trim($t[1], "'\"");
                elseif (is_array($t) && $t[0] === T_STRING
                        && strtoupper($t[1]) === 'INCLUDE_DIR')
                    continue;
                else
                    $literales[] = null; // expresión que no sabemos resolver
            }

            if (count($literales) !== 1 || $literales[0] === null)
                continue;
            $path = $literales[0];
            if (substr($path, -4) !== '.php')
                continue;

            $targets[] = ['line' => $tokens[$i][2], 'path' => $path];
        }

        return $targets;
    }

    /**
     * Includes literales que no llevan a ningún archivo existente.
     *
     * Se comprueban el directorio del archivo y sus superiores: osTicket
     * incluye `staff.inc.php` desde `scp/apps/dispatcher.php` contando con
     * que el script de entrada vive en `scp/` (PHP busca las rutas
     * relativas en el directorio del script que se ejecuta, no en el del
     * archivo que hace el include). Comprobar sólo el directorio del
     * archivo daría un falso positivo.
     */
    static function missingIncludes($root, array $files) {
        $missing = [];
        $root = rtrim($root, '/');
        foreach ($files as $rel) {
            $source = @file_get_contents("$root/$rel");
            if ($source === false)
                continue;

            // Directorio del archivo y todos sus superiores hasta la raíz
            $dir = dirname("$root/$rel");
            $candidatos = array();
            for ($d = $dir; strlen($d) >= strlen($root); $d = dirname($d)) {
                $candidatos[] = $d . '/';
                if ($d === $root)
                    break;
            }
            $candidatos[] = "$root/include/";

            foreach (self::includeTargets($source) as $target) {
                $existe = false;
                foreach ($candidatos as $base) {
                    if (file_exists($base . $target['path'])) {
                        $existe = true;
                        break;
                    }
                }
                if (!$existe)
                    $missing[] = ['file' => $rel, 'line' => $target['line'],
                        'path' => $target['path']];
            }
        }
        return $missing;
    }

    static function duplicates(array $map) {
        $found = [];
        foreach ($map as $name => $sites) {
            if (count($sites) < 2)
                continue;
            $sueltas = array_filter($sites, function ($site) {
                return empty($site['conditional']);
            });
            if (count($sueltas) > 1)
                $found[$name] = $sueltas;
        }
        return $found;
    }

    /** Clases declaradas varias veces pero protegidas con class_exists(). */
    static function guarded(array $map) {
        $found = [];
        foreach ($map as $name => $sites) {
            if (count($sites) > 1 && !isset(self::duplicates([$name => $sites])[$name]))
                $found[$name] = $sites;
        }
        return $found;
    }

    /** Archivos con más clases, de mayor a menor. */
    static function byFile(array $map) {
        $count = [];
        foreach ($map as $name => $sites)
            foreach ($sites as $site)
                $count[$site['file']][] = $name;

        uasort($count, function ($a, $b) {
            return count($b) - count($a);
        });

        return $count;
    }
}

/* ------------------------------------------------------------------ */
/* Línea de comandos                                                  */
/* ------------------------------------------------------------------ */

function classmap_main($root, array $argv) {
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--check')
            $options['check'] = true;
        elseif ($arg === '--json')
            $options['json'] = true;
        elseif ($arg === '--help' || $arg === '-h')
            $options['help'] = true;
        elseif (strpos($arg, '--top=') === 0)
            $options['top'] = max(1, (int) substr($arg, 6));
        elseif (strpos($arg, '--file=') === 0)
            $options['file'] = substr($arg, 7);
    }

    if (isset($options['help'])) {
        echo "class-map — qué clase vive en qué archivo\n\n"
            . "  php tools/quality/class-map.php [--check] [--json]\n"
            . "      [--top=N] [--file=RUTA]\n\n"
            . "  --check   código de salida 1 si una clase se declara dos veces\n";
        return 0;
    }

    $areas = ['include', 'scp', 'api', 'setup', 'tools', 'tests'];
    // setup/test/tests son los stubs del instalador: declaran a propósito
    // copias de clases reales para cargarlas en lugar de ellas.
    $skipDirs = ['vendor', 'mpdf', 'pear', 'laminas-mail', 'oauth2',
        'plugins', 'lib', 'test/tests'];
    $map = ClassMap::scan($root, $areas, $skipDirs);
    $duplicates = ClassMap::duplicates($map);
    $missing = ClassMap::missingIncludes($root,
        ClassMap::phpFiles($root, $areas, $skipDirs));
    $byFile = ClassMap::byFile($map);
    $top = $options['top'] ?? 10;

    if (isset($options['file'])) {
        $wanted = ltrim($options['file'], '/');
        if (!isset($byFile[$wanted])) {
            fwrite(STDERR, "No se declara ninguna clase en $wanted\n");
            return 1;
        }
        foreach ($byFile[$wanted] as $name)
            printf("%-40s línea %d\n", $name, $map[$name][0]['line']);
        return 0;
    }

    if (isset($options['json'])) {
        echo json_encode([
            'classes' => count($map),
            'files' => count($byFile),
            'duplicates' => array_keys($duplicates),
            'guarded' => array_keys(ClassMap::guarded($map)),
            'missing_includes' => $missing,
            'map' => $map,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        return $duplicates ? 1 : 0;
    }

    printf("Clases declaradas: %d en %d archivos\n\n", count($map),
        count($byFile));

    echo "Archivos con más clases\n";
    $i = 0;
    foreach ($byFile as $file => $classes) {
        if ($i++ >= $top)
            break;
        printf("  %3d  %-45s %s\n", count($classes), $file,
            count($classes) > 1 ? '' : '');
    }

    $guarded = ClassMap::guarded($map);

    if ($missing) {
        echo "\nIncludes con ruta literal que no llevan a ningún archivo\n";
        foreach ($missing as $m)
            printf("  %s:%d  %s\n", $m['file'], $m['line'], $m['path']);
    }

    if ($duplicates) {
        echo "\nClases declaradas más de una vez (error fatal según el orden de carga)\n";
        foreach ($duplicates as $name => $sites) {
            printf("  %s\n", $name);
            foreach ($sites as $site)
                printf("      %s:%d\n", $site['file'], $site['line']);
        }
    }

    if (isset($options['check']) && ($duplicates || $missing))
        return 1;

    if (!$duplicates && !$missing)
        echo "\nSin clases duplicadas ni includes rotos.\n";

    if ($guarded && !isset($options['json'])) {
        printf("\n%d clase(s) declaradas más de una vez pero protegidas con class_exists() (polyfills y dobles):\n",
            count($guarded));
        foreach ($guarded as $name => $sites)
            printf("  %-30s %s\n", $name,
                implode(', ', array_map(function ($s) {
                    return $s['file'].':'.$s['line'];
                }, $sites)));
    }

    return 0;
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "Sólo se ejecuta desde la línea de comandos\n");
        exit(2);
    }
    exit(classmap_main(dirname(__DIR__, 2), $argv ?? []));
}
