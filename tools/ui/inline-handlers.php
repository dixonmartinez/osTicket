#!/usr/bin/env php
<?php
/**
 * tools/ui/inline-handlers.php
 *
 * Inventario de manejadores de eventos en línea (`onclick=`, `onchange=`…)
 * en las plantillas PHP. Es el trabajo pendiente para poder quitar
 * `'unsafe-inline'` de la CSP (ver docs/SECURITY_AUDIT.md, OST-102): cada
 * atributo de esos tiene que pasar a `addEventListener` o a un listener
 * delegado en JavaScript.
 *
 * No modifica nada: sirve para medir el avance y para que el número no
 * vuelva a subir sin darse cuenta.
 *
 * Uso:
 *   php tools/ui/inline-handlers.php                 # resumen por archivo
 *   php tools/ui/inline-handlers.php --list          # detalle, uno por línea
 *   php tools/ui/inline-handlers.php --json          # para comparar versiones
 *   php tools/ui/inline-handlers.php --max=120       # falla si hay más
 *   php tools/ui/inline-handlers.php --client        # solo include/client/
 *
 * Códigos de salida:
 *   0  dentro del límite (o sin --max)
 *   1  se superó --max
 *   2  error de uso
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$options = getopt('', ['list', 'json', 'max::', 'client', 'staff', 'help']);

if (isset($options['help'])) {
    echo "inline-handlers — inventario de onclick/onchange en plantillas\n\n"
        . "  php tools/ui/inline-handlers.php [--list] [--json] [--max=N]\n"
        . "                                   [--client | --staff]\n\n";
    exit(0);
}

/* Solo eventos que un lector de CSP trata como script en línea. */
$events = ['click', 'dblclick', 'change', 'submit', 'reset', 'keyup', 'keydown',
    'keypress', 'blur', 'focus', 'load', 'error', 'mouseover', 'mouseout',
    'mouseenter', 'mouseleave', 'mousedown', 'mouseup', 'input', 'select',
    'contextmenu', 'paste', 'copy', 'cut', 'dragstart', 'drop'];

$pattern = '/\bon(' . implode('|', $events) . ')\s*=\s*(["\'])/i';

$dirs = ['include', 'scp'];
if (isset($options['client'])) {
    $dirs = ['include/client'];
} elseif (isset($options['staff'])) {
    $dirs = ['include/staff', 'scp'];
}

$files = [];
foreach ($dirs as $dir) {
    $base = "$root/$dir";
    if (!is_dir($base))
        continue;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (substr($file->getFilename(), -4) !== '.php')
            continue;
        $files[] = $file->getPathname();
    }
}
sort($files);

$total = 0;
$byFile = [];
$details = [];

foreach ($files as $path) {
    $rel = substr($path, strlen($root) + 1);
    $source = file_get_contents($path);
    if ($source === false || stripos($source, 'on') === false)
        continue;

    $count = 0;
    $lines = explode("\n", $source);
    foreach ($lines as $i => $line) {
        if (!preg_match_all($pattern, $line, $m, PREG_SET_ORDER))
            continue;
        foreach ($m as $hit) {
            $count++;
            $details[] = sprintf('%s:%d  on%s', $rel, $i + 1, strtolower($hit[1]));
        }
    }

    if ($count) {
        $byFile[$rel] = $count;
        $total += $count;
    }
}

arsort($byFile);

if (isset($options['json'])) {
    echo json_encode([
        'total' => $total,
        'files' => $byFile,
        'details' => $details,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    printf("Manejadores en línea en %s: %d en %d archivos\n",
        implode(', ', $dirs), $total, count($byFile));
    if ($byFile) {
        echo "\nPor archivo:\n";
        foreach ($byFile as $file => $count)
            printf("  %4d  %s\n", $count, $file);
    }
    if (isset($options['list'])) {
        echo "\nDetalle:\n";
        foreach ($details as $line)
            echo "  $line\n";
    }
}

if (isset($options['max']) && $options['max'] !== false) {
    $max = (int)$options['max'];
    if ($total > $max) {
        fwrite(STDERR, "\nHay $total manejadores en línea y el límite es $max.\n");
        exit(1);
    }
}

exit(0);
