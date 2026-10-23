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

/**
 * Tamaño real de cada función, en líneas.
 *
 * Se recorre la lista de tokens: las llaves que importan son las del código,
 * no las que aparecen dentro de una cadena («Hola {nombre}»), un heredoc o
 * una expresión regular. Contando el texto a pelo, una función de 20 líneas
 * con un mensaje con llaves podía salir de 700.
 *
 * @return array<array{name:string,lines:int}>
 */
function measure_functions(string $src): array {
    if (!function_exists('token_get_all'))
        return [];

    $tokens = @token_get_all($src);
    if (!is_array($tokens))
        return [];

    $out = array();
    $pending = null;
    $depth = 0;
    $line = 1;
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (is_array($token)) {
            $line = $token[2];

            if ($token[0] === T_FUNCTION) {
                $name = 'closure';

                for ($j = $i + 1; $j < $count; $j++) {
                    $next = $tokens[$j];

                    if (is_array($next) && in_array($next[0], array(T_WHITESPACE,
                            T_COMMENT, T_DOC_COMMENT)))
                        continue;

                    if (is_array($next) && $next[0] === T_STRING)
                        $name = $next[1];

                    break;
                }

                $pending = array('name' => $name, 'line' => $token[2], 'depth' => null);
            }

            $line += substr_count($token[1], "\n");
            continue;
        }

        if ($token === '{') {
            $depth++;

            if ($pending && $pending['depth'] === null)
                $pending['depth'] = $depth;
        } else if ($token === '}') {
            if ($pending && $pending['depth'] === $depth) {
                $out[] = array('name' => $pending['name'],
                    'lines' => $line - $pending['line'] + 1);
                $pending = null;
            }

            $depth--;
        }

        $line += substr_count($token, "\n");
    }

    return $out;
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

    // JavaScript en línea: sólo tiene sentido en las plantillas que generan
    // HTML (include/ y scp/). Antes se contaba también en tools/, donde las
    // propias herramientas mencionan «onclick=» en sus comentarios, y se daba
    // por bueno un `onsubmit =` de JavaScript, que no es un atributo HTML.
    if (strpos($rel, 'include/') === 0 || strpos($rel, 'scp/') === 0) {
        $counters['inline_handler'] += preg_match_all(
            '/\bon(?:click|change|submit|load|error|focus|blur|keyup|keydown)\s*=\s*[\"\']/i', $src);
        $counters['script_tag'] += preg_match_all('/<script\b/i', $src);
    }

    // Funciones: se miden con el tokenizador de PHP, no contando llaves en
    // el texto (las llaves dentro de cadenas, heredocs o expresiones
    // regulares falseaban la medida y hacían parecer gigantes funciones
    // pequeñas).
    foreach (measure_functions($src) as $fn)
        $functions[] = $fn + ['file' => $rel];
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
