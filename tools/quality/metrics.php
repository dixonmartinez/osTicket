#!/usr/bin/env php
<?php
/**
 * tools/quality/metrics.php
 *
 * Radiografía del código propio del proyecto (sin vendor/ ni librerías
 * empaquetadas). Sirve para medir antes y después de un refactor, y para
 * detectar si una zona concreta empeora.
 *
 * Mide:
 *   - tamaño de los archivos y de las funciones,
 *   - uso de la capa ORM frente a SQL a mano (db_query),
 *   - accesos directos a $_POST/$_REQUEST/$_GET (superficie de entrada),
 *   - variables globales, eval() y extract(),
 *   - JavaScript embebido en plantillas (onclick=, <script>),
 *   - reparto de líneas por área.
 *
 * Uso:
 *   php tools/quality/metrics.php
 *   php tools/quality/metrics.php --json
 *   php tools/quality/metrics.php --top=25
 *
 * No falla nunca por sí solo: es una herramienta de medida, no un test.
 * Para el listón mínimo, ver docs/CODE_QUALITY.md.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line\n");
    exit(2);
}

$options = getopt('', ['json', 'top::', 'help']);

if (isset($options['help'])) {
    echo "metrics — tamaño y deuda técnica del código propio\n\n"
        . "  php tools/quality/metrics.php [--json] [--top=N]\n\n";
    exit(0);
}

$root = dirname(__DIR__, 2);
$top = isset($options['top']) ? max(1, (int) $options['top']) : 15;

// Directorios del proyecto, excluyendo dependencias empaquetadas
$areas = ['include', 'scp', 'api', 'setup', 'tools', 'css', 'js'];
$skipDirs = ['vendor', 'mpdf', 'pear', 'laminas-mail', 'oauth2', 'plugins'];

function collect_php($root, array $areas, array $skipDirs) {
    $files = [];
    foreach ($areas as $area) {
        $base = "$root/$area";
        if (!is_dir($base))
            continue;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php')
                continue;
            $path = $file->getPathname();
            $rel = substr($path, strlen($root) + 1);
            foreach ($skipDirs as $skip)
                if (strpos($rel, $skip . '/') !== false || strpos($rel, '/' . $skip . '/') !== false)
                    continue 2;
            $files[] = $rel;
        }
    }
    return $files;
}

$files = collect_php($root, $areas, $skipDirs);

$totalLines = 0;
$sizes = [];
$functions = [];
$counters = [
    'db_query' => 0,
    'db_input' => 0,
    'request_superglobal' => 0,
    'global_var' => 0,
    'eval' => 0,
    'extract' => 0,
    'inline_handler' => 0,
    'script_tag' => 0,
];
$byArea = [];

foreach ($files as $rel) {
    $src = @file_get_contents("$root/$rel");
    if ($src === false)
        continue;

    $lines = substr_count($src, "\n") + 1;
    $totalLines += $lines;
    $sizes[$rel] = $lines;
    $byArea[strtok($rel, '/')] = ($byArea[strtok($rel, '/')] ?? 0) + $lines;

    // Señales de deuda
    $counters['db_query'] += preg_match_all('/\bdb_query\s*\(/', $src);
    $counters['db_input'] += preg_match_all('/\bdb_input\s*\(/', $src);
    $counters['request_superglobal'] += preg_match_all('/\$_(?:POST|REQUEST|GET)\s*\[/', $src);
    $counters['global_var'] += preg_match_all('/^\s*global\s+\$/m', $src);
    $counters['eval'] += preg_match_all('/\beval\s*\(/', $src);
    $counters['extract'] += preg_match_all('/\bextract\s*\(/', $src);
    $counters['inline_handler'] += preg_match_all('/\bon(?:click|change|submit|load)\s*=/', $src);
    $counters['script_tag'] += preg_match_all('/<script\b/i', $src);

    // Funciones: de "function nombre(" hasta su llave de cierre
    if (preg_match_all('/\bfunction\s+(\w+)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $i => $capture) {
            $name = $capture[0];
            $start = $m[0][$i][1];
            $pos = strpos($src, '{', $start);
            if ($pos === false)
                continue;
            $depth = 0;
            $len = strlen($src);
            while ($pos < $len) {
                if ($src[$pos] === '{') $depth++;
                elseif ($src[$pos] === '}') {
                    $depth--;
                    if ($depth === 0) break;
                }
                $pos++;
            }
            $body = substr($src, $start, $pos - $start + 1);
            $functions[] = ['name' => $name, 'file' => $rel, 'lines' => substr_count($body, "\n") + 1];
        }
    }
}

arsort($sizes);
arsort($byArea);
usort($functions, fn($a, $b) => $b['lines'] <=> $a['lines']);

$result = [
    'files' => count($files),
    'lines' => $totalLines,
    'lines_by_area' => $byArea,
    'largest_files' => array_slice($sizes, 0, $top, true),
    'longest_functions' => array_slice($functions, 0, $top),
    'function_count' => count($functions),
    'function_average' => count($functions)
        ? round(array_sum(array_column($functions, 'lines')) / count($functions), 1) : 0,
    'signals' => $counters,
];

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

printf("Código propio: %d archivos PHP, %s líneas\n", $result['files'],
    number_format($totalLines, 0, ',', '.'));

echo "\nReparto por área\n";
foreach ($byArea as $area => $lines)
    printf("  %-10s %8s líneas\n", $area . '/', number_format($lines, 0, ',', '.'));

echo "\nArchivos más grandes\n";
foreach ($result['largest_files'] as $file => $lines)
    printf("  %6d  %s\n", $lines, $file);

echo "\nFunciones más largas (de $result[function_count], media "
    . "$result[function_average] líneas)\n";
foreach ($result['longest_functions'] as $fn)
    printf("  %6d  %-34s %s\n", $fn['lines'], $fn['name'], $fn['file']);

echo "\nSeñales de deuda\n";
$labels = [
    'db_query' => 'SQL a mano (db_query)',
    'db_input' => 'db_input (escape manual)',
    'request_superglobal' => 'accesos directos a $_POST/$_REQUEST/$_GET',
    'global_var' => 'global $...',
    'eval' => 'eval()',
    'extract' => 'extract()',
    'inline_handler' => 'onclick=/onchange= en HTML',
    'script_tag' => '<script> embebido en plantillas',
];
foreach ($counters as $key => $value)
    printf("  %-42s %6d\n", $labels[$key] ?? $key, $value);

echo "\nPlan y criterio de mejora: docs/CODE_QUALITY.md\n";
