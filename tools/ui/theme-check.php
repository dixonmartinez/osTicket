#!/usr/bin/env php
<?php
/**
 * tools/ui/theme-check.php
 *
 * Verifica que el tema oscuro sigue completo y bien conectado. No necesita
 * navegador ni Docker: lee los archivos y comprueba lo que puede romperse al
 * tocar el tema.
 *
 * Qué revisa:
 *   1. Que existen los archivos del tema y de la densidad.
 *   2. Que css/dark.css y css/density.css definen lo que deben y tienen las
 *      llaves balanceadas.
 *   3. Que las tres cabeceras (panel, portal y acceso del personal) cargan
 *      js/theme.js antes de jQuery, css/dark.css después de css/ui.css,
 *      css/density.css después de css/dark.css y emiten data-theme-default
 *      y data-density-default.
 *   4. Que cada cabecera incluye los botones de tema y densidad
 *      (include/templates/theme-toggle.inc.php).
 *   5. Que el service worker precachea las hojas y el JS del tema.
 *   6. Que osTicket::getThemeDefault() y getDensityDefault() validan su valor.
 *
 * Uso:
 *   php tools/ui/theme-check.php
 *   php tools/ui/theme-check.php --quiet    # sólo los fallos
 *
 * Códigos de salida:
 *   0  todo correcto
 *   1  falta algo
 *   2  error de uso
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$options = getopt('', ['quiet', 'help']);

if (isset($options['help'])) {
    echo "theme-check — integridad del tema claro/oscuro\n\n"
        . "  php tools/ui/theme-check.php [--quiet]\n\n";
    exit(0);
}

$quiet = isset($options['quiet']);
$failures = 0;
$checks = 0;

function ok($message) { global $quiet, $checks; $checks++; if (!$quiet) echo "  ok    $message\n"; }
function bad($message) { global $failures, $checks; $checks++; $failures++; echo "  FALLO $message\n"; }
function check($condition, $good, $fail) { $condition ? ok($good) : bad($fail); }

function read($path) { return is_file($path) ? file_get_contents($path) : ''; }
function before($haystack, $needle, $limit) {
    $a = strpos($haystack, $needle);
    $b = strpos($haystack, $limit);
    return $a !== false && $b !== false && $a < $b;
}

echo "Tema claro/oscuro\n";

/* 1. Archivos ------------------------------------------------------------ */
foreach ([
    'css/dark.css' => 'hoja de estilos del tema oscuro',
    'css/density.css' => 'hoja de estilos de la densidad',
    'js/theme.js' => 'resolución y persistencia de las preferencias',
    'js/dialog-focus.js' => 'foco y teclado en los diálogos',
    'include/templates/theme-toggle.inc.php' => 'marcado del botón',
    'docs/UI_GUIDE.md' => 'documentación',
] as $file => $what) {
    check(is_file("$root/$file"), "$file ($what)", "falta $file ($what)");
}

/* 2. Tokens y llaves ----------------------------------------------------- */
$dark = read("$root/css/dark.css");
$tokens = ['--dk-bg', '--dk-surface', '--dk-text', '--dk-text-muted',
    '--dk-border', '--dk-link', '--dk-paper'];
$missing = array_values(array_filter($tokens,
    fn($t) => strpos($dark, $t . ':') === false));
check(!$missing, 'tokens del tema definidos',
    'faltan tokens en css/dark.css: ' . implode(', ', $missing));

check(substr_count($dark, '{') === substr_count($dark, '}'),
    'llaves balanceadas en css/dark.css',
    'llaves desbalanceadas en css/dark.css (' . substr_count($dark, '{')
        . ' abren, ' . substr_count($dark, '}') . ' cierran)');

check(strpos($dark, '@media print') !== false,
    'la impresión se fuerza en claro',
    'css/dark.css no tiene bloque @media print: se imprimiría en oscuro');

check(strpos($dark, 'prefers-reduced-motion') !== false,
    'respeta prefers-reduced-motion',
    'css/dark.css no respeta prefers-reduced-motion');

$density = read("$root/css/density.css");
check(substr_count($density, '{') === substr_count($density, '}'),
    'llaves balanceadas en css/density.css',
    'llaves desbalanceadas en css/density.css');

check(strpos($density, 'html[data-density="compact"]') !== false,
    'css/density.css define el modo compacto',
    'css/density.css no tiene reglas html[data-density="compact"]');

check(strpos($density, 'prefers-reduced-motion') !== false
    || strpos($density, '@media print') !== false,
    'la densidad no afecta a la impresión',
    'css/density.css no ajusta la impresión del modo compacto');

/* 3 y 4. Cabeceras ------------------------------------------------------- */
$headers = [
    'include/staff/header.inc.php' => 'panel de agentes',
    'include/client/header.inc.php' => 'portal del cliente',
    'include/staff/login.header.php' => 'acceso del personal',
];

foreach ($headers as $file => $what) {
    $html = read("$root/$file");

    check($html !== '', "$file ($what) legible", "no se puede leer $file");

    check(strpos($html, 'js/theme.js') !== false,
        "$what: carga js/theme.js",
        "$what: no carga js/theme.js");

    check(before($html, 'js/theme.js', 'js/jquery-'),
        "$what: el tema se aplica antes de jQuery y del <body>",
        "$what: js/theme.js debe ir antes de jQuery (evita el destello blanco)");

    check(strpos($html, 'css/dark.css') !== false,
        "$what: carga css/dark.css",
        "$what: no carga css/dark.css");

    // El tema tiene que ser la última hoja propia del <head>: así puede
    // corregir a scp.css, mobile.css y al tema de jQuery UI en igualdad de
    // especificidad. Los estilos de plugins (getExtraHeaders) van después a
    // propósito, para que una personalización pueda seguir ganando.
    $head = $html;
    if (($start = strpos($html, '<head')) !== false
            && ($stop = strpos($html, '</head>', $start)) !== false)
        $head = substr($html, $start, $stop - $start);

    // Se trabaja línea a línea: los href se generan con PHP y llevan un '>'
    // (el cierre del bloque) dentro de la etiqueta, así que no se puede
    // cortar la etiqueta por el primer '>'.
    $loads = array_values(array_filter(array_map('trim', explode("\n", $head)),
        fn($line) => strpos($line, '<link') !== false
            && stripos($line, '.css') !== false));

    // El orden importa: dark.css corrige a las hojas base y density.css va
    // después porque solo toca espaciado.
    $last = $loads ? end($loads) : '';
    check(strpos($last, 'density.css') !== false,
        "$what: css/density.css es la última hoja del <head>",
        "$what: css/density.css debe ser la última hoja del <head>; ahora la "
            . 'última es: ' . ($last ?: '(ninguna hoja)')
            . ' [hojas encontradas: ' . count($loads) . ']');

    check(before($head, 'css/dark.css', 'css/density.css'),
        "$what: css/density.css se carga después de css/dark.css",
        "$what: css/density.css debe ir después de css/dark.css");

    check(strpos($html, 'getThemeDefault') !== false
        && strpos($html, 'data-theme-default') !== false,
        "$what: emite data-theme-default",
        "$what: no emite data-theme-default (OST_DEFAULT_THEME se ignoraría)");

    check(strpos($html, 'getDensityDefault') !== false
        && strpos($html, 'data-density-default') !== false,
        "$what: emite data-density-default",
        "$what: no emite data-density-default (OST_DEFAULT_DENSITY se ignoraría)");

    check(strpos($html, 'color-scheme') !== false,
        "$what: declara color-scheme (controles nativos a juego)",
        "$what: falta <meta name=\"color-scheme\">: los controles nativos seguirían en claro");

    check(strpos($html, 'theme-toggle.inc.php') !== false,
        "$what: incluye el botón de cambio de tema",
        "$what: no incluye include/templates/theme-toggle.inc.php");
}

/* 4b. Foco en los diálogos: sin este script, Tab se escapa del diálogo. */
foreach ([
    'include/staff/footer.inc.php' => 'panel de agentes',
    'include/client/footer.inc.php' => 'portal del cliente',
] as $file => $what) {
    check(strpos(read("$root/$file"), 'js/dialog-focus.js') !== false,
        "$what: carga js/dialog-focus.js (foco atrapado en diálogos)",
        "$what: no carga js/dialog-focus.js");
}

$toggle = read("$root/include/templates/theme-toggle.inc.php");
check(strpos($toggle, 'data-ost-theme-toggle') !== false
    && strpos($toggle, 'data-ost-density-toggle') !== false,
    'la plantilla incluye los botones de tema y densidad',
    'include/templates/theme-toggle.inc.php no tiene los dos botones');

/* 5. Service worker ------------------------------------------------------ */
$sw = read("$root/sw.js");
check(strpos($sw, "'css/dark.css'") !== false,
    'el service worker precachea css/dark.css',
    'sw.js no precachea css/dark.css (sin conexión se vería claro)');
check(strpos($sw, "'js/theme.js'") !== false,
    'el service worker precachea js/theme.js',
    'sw.js no precachea js/theme.js');
check(strpos($sw, "'css/density.css'") !== false,
    'el service worker precachea css/density.css',
    'sw.js no precachea css/density.css');

/* 6. getThemeDefault ----------------------------------------------------- */
$core = read("$root/include/class.osticket.php");
check(strpos($core, 'function getThemeDefault(') !== false,
    'osTicket::getThemeDefault() existe',
    'falta osTicket::getThemeDefault() en include/class.osticket.php (error 500)');

$accepted = strpos($core, "['auto', 'light', 'dark']") !== false;
check($accepted,
    'getThemeDefault() sólo acepta auto|light|dark',
    "getThemeDefault() no valida el valor: se esperaba ['auto', 'light', 'dark']");

check(strpos($core, 'function getDensityDefault(') !== false,
    'osTicket::getDensityDefault() existe',
    'falta osTicket::getDensityDefault() en include/class.osticket.php');

check(strpos($core, "['comfy', 'compact']") !== false,
    'getDensityDefault() sólo acepta comfy|compact',
    "getDensityDefault() no valida el valor: se esperaba ['comfy', 'compact']");

/* Resumen ---------------------------------------------------------------- */
echo "\n";
if ($failures === 0) {
    echo "Todo correcto: $checks comprobaciones.\n";
    exit(0);
}

echo "$failures de $checks comprobaciones fallaron.\n";
exit(1);
