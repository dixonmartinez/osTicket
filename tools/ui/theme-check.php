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
 *   7. Que el 400 del enrutador es diagnosticable y que docs/UI_GUIDE.md
 *      mantiene separado lo entregado de lo pendiente.
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
    'js/icons.js' => 'iconos decorativos para lectores de pantalla',
    'js/password-strength.js' => 'indicador de fuerza de la contraseña',
    'include/templates/password-strength.inc.php' => 'carga y textos del indicador',
    'include/templates/theme-toggle.inc.php' => 'marcado del botón',
    'docs/UI_GUIDE.md' => 'documentación',
    'docs/theme-preview.html' => 'vista previa del panel',
    'docs/theme-preview-client.html' => 'vista previa del portal',
    'css/modern.css' => 'capa moderna (cromo, tablas y formularios)',
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

/* 2b. Capa moderna ------------------------------------------------------- */
$modern = read("$root/css/modern.css");

check(substr_count($modern, '{') === substr_count($modern, '}'),
    'llaves balanceadas en css/modern.css',
    'llaves desbalanceadas en css/modern.css (' . substr_count($modern, '{')
        . ' abren, ' . substr_count($modern, '}') . ' cierran)');

// Ningun color literal: si esta hoja fijara colores propios, el tema oscuro
// (que solo redefine los tokens --ui-*) se romperia en silencio. Se permite
// rgba(0, 0, 0, ...) para las sombras, que funcionan en los dos temas.
$sinComentarios = preg_replace('#/\*.*?\*/#s', '', $modern);
$colores = array();
if (preg_match_all('/:\s*#[0-9a-fA-F]{3,8}\b/', $sinComentarios, $m))
    $colores = array_merge($colores, $m[0]);
if (preg_match_all('/(?<![a-z-])(?:rgb|hsl)a?\([^)]*\)/i', $sinComentarios, $m)) {
    foreach ($m[0] as $color)
        if (stripos($color, 'rgba(0, 0, 0') !== 0
                && stripos($color, 'rgba(0,0,0') !== 0)
            $colores[] = $color;
}
check(!$colores,
    'css/modern.css no fija colores: todo sale de los tokens --ui-*',
    'css/modern.css fija colores literales (el tema oscuro no los heredaria): '
        . implode(', ', array_slice(array_unique($colores), 0, 5)));

check(strpos($modern, 'var(--mod-ring)') !== false
    && strpos($modern, 'var(--ui-surface)') !== false,
    'css/modern.css consume los tokens de color de css/ui.css',
    'css/modern.css no usa los tokens --ui-* / --mod-ring');

/* Escala tipografica ------------------------------------------------------ */
// El texto se leia pequeno: el cuerpo estaba en 14 px y los campos, ademas,
// en los 13.3 px que pone el navegador si nadie los toca (los controles de
// formulario no heredan la tipografia). Ahora la escala vive en tres tokens
// y los campos la usan.
foreach (array('--mod-text', '--mod-text-sm', '--mod-text-xs') as $token)
    check(strpos($modern, $token . ':') !== false,
        "css/modern.css define la escala $token",
        "falta $token: la escala tipografica se queda repartida en numeros sueltos");

check(preg_match('/body\s*\{[^}]*font-size:\s*var\(--mod-text\)/s', $modern) === 1,
    'el cuerpo usa la escala (var(--mod-text))',
    'body no usa var(--mod-text): la escala y el texto irian por separado');

// Los <input>, <select>, <textarea> y <button> no heredan la fuente del body.
// Sin font-size propio se quedan en el tamano por omision del navegador.
$campos = preg_match('/input\[type="text"\][^{]*\{[^}]*font-size:\s*var\(--mod-text\)/s',
    $modern) === 1;
check($campos,
    'los campos de formulario fijan su tamano (no heredan y saldrian mas pequenos)',
    'input/select/textarea no fijan font-size: el navegador les pone ~13.3 px de Arial');

// La prioridad llega con el color en linea en el <td> y en el <div>.
check(strpos($modern, 'table.list tbody td[style*="background"] {') !== false
    && strpos($modern, 'background: transparent !important;') !== false,
    'el color de prioridad no pinta la celda entera (solo la etiqueta)',
    'la celda con color en linea se pinta de punta a punta: parece una columna rota');

check(strpos($modern, 'border-radius: var(--mod-radius-pill);') !== false
    && strpos($modern, '> div {') !== false,
    'la prioridad se dibuja como etiqueta redondeada',
    'la prioridad no tiene forma de etiqueta: se queda en un rectangulo');

// Ningun tamano suelto por debajo de 12 px: se lee mal y no sigue la escala.
$chicos = array();
if (preg_match_all('/font-size:\s*(\d+)px/', $sinComentarios, $m, PREG_SET_ORDER))
    foreach ($m as $hit)
        if ((int) $hit[1] < 12)
            $chicos[] = $hit[0];
check(!$chicos,
    'ningun texto de css/modern.css baja de 12 px',
    'hay tamanos por debajo de 12 px: ' . implode(', ', array_slice($chicos, 0, 5)));

/* 2c. Version en la URL del recurso -------------------------------------- */
// nginx sirve CSS y JS con `expires 7d`: sin una version en la URL, tras un
// `git pull` el navegador pinta el HTML nuevo con la hoja vieja y parece que
// los cambios no se aplicaron.
$http = read("$root/include/class.http.php");

check(strpos($http, 'static function asset(') !== false,
    'Http::asset() existe',
    'falta Http::asset(): las hojas se sirven sin version y el navegador las cachea 7 dias');

check(strpos($http, 'filemtime($file)') !== false,
    'Http::asset() versiona con filemtime (GIT_VERSION es literalmente $git)',
    'Http::asset() no usa filemtime(): la version no cambiaria al actualizar');

check(strpos(read("$root/sw.js"), 'cache.match(url.pathname)') !== false,
    'el service worker encuentra la copia sin ?v= (sin red sigue habiendo estilos)',
    'sw.js no busca la copia por ruta: sin red, las hojas versionadas no aparecen');

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

    check(strpos($html, 'css/modern.css') !== false,
        "$what: carga css/modern.css",
        "$what: no carga css/modern.css (la interfaz se queda como estaba)");

    check(before($head, 'css/ui.css', 'css/modern.css'),
        "$what: css/modern.css se carga después de css/ui.css",
        "$what: css/modern.css debe ir después de css/ui.css (de él salen los tokens)");

    check(before($head, 'css/modern.css', 'css/dark.css'),
        "$what: css/dark.css se carga después de css/modern.css",
        "$what: css/modern.css debe ir antes de css/dark.css, que es quien manda en color");

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

    // Todos, no sólo alguno: una sola hoja sin `?v=` se queda 7 días en el
    // navegador y es justo la que se acaba de tocar.
    $versionados = strpos($html, 'Http::asset(') !== false;
    $sinVersion = preg_match_all(
        '/<\?php echo ROOT_PATH;? \?>\s*[^"\s]+\.(?:css|js)/', $html);

    check($versionados && $sinVersion === 0,
        "$what: todos sus recursos llevan version",
        "$what: $sinVersion recurso(s) sin version: el navegador los guardará"
            . ' 7 días y los cambios no se verán');

    check(strpos($html, 'theme-toggle.inc.php') !== false,
        "$what: incluye el botón de cambio de tema",
        "$what: no incluye include/templates/theme-toggle.inc.php");
}

/* 4b. Foco en los diálogos: sin este script, Tab se escapa del diálogo. */
foreach ([
    'include/staff/footer.inc.php' => 'panel de agentes',
    'include/client/footer.inc.php' => 'portal del cliente',
] as $file => $what) {
    $footer = read("$root/$file");

    check(strpos($footer, 'js/dialog-focus.js') !== false,
        "$what: carga js/dialog-focus.js (foco atrapado en diálogos)",
        "$what: no carga js/dialog-focus.js");

    check(strpos($footer, 'js/icons.js') !== false,
        "$what: carga js/icons.js (iconos fuera del lector de pantalla)",
        "$what: no carga js/icons.js");
}

/* 4c. Iconos en SVG: la capa se apoya en js/icons.js y en css/ui.css. */
$icons = read("$root/js/icons.js");
$uicss = read("$root/css/ui.css");

check(strpos($icons, 'var SVG_ICONS = {') !== false,
    'js/icons.js define el mapa SVG_ICONS',
    'js/icons.js no define SVG_ICONS (los iconos se quedan en la fuente)');

check(strpos($icons, "createElementNS(SVG_NS, 'svg')") !== false,
    'js/icons.js cambia los iconos de Font Awesome por SVG',
    'js/icons.js no construye los SVG');

check(strpos($icons, "data-svg-icons") !== false,
    'los iconos SVG se pueden desactivar con data-svg-icons="off"',
    'falta la salida de emergencia data-svg-icons="off"');

check(strpos($uicss, 'svg.ost-icon') !== false
    && strpos($uicss, 'stroke: currentColor') !== false,
    'css/ui.css da tamaño y color heredado a los iconos SVG',
    'css/ui.css no estiliza svg.ost-icon (los iconos saldrían sin tamaño)');

$checked = preg_match_all("/^\s*'icon-[a-z0-9-]+':/m", $icons);

check($checked >= 150,
    "el mapa cubre los iconos más usados ($checked dibujados)",
    "el mapa SVG solo tiene $checked iconos; se esperaban 150 o más");

/* 4d. Indicador de fuerza de la contraseña: script, estilos y enganche. */
$pw = read("$root/js/password-strength.js");
$clientFooter = read("$root/include/client/footer.inc.php");
$register = read("$root/include/client/register.inc.php");
$profile = read("$root/include/client/profile.inc.php");

$pwTemplate = read("$root/include/templates/password-strength.inc.php");
$staffFooter = read("$root/include/staff/footer.inc.php");

check(strpos($pwTemplate, 'OST_PW_STRENGTH') !== false
    && strpos($pwTemplate, 'js/password-strength.js') !== false,
    'la plantilla del indicador carga el script y traduce sus textos',
    'include/templates/password-strength.inc.php no carga el script o no traduce');

foreach ([
    'include/client/footer.inc.php' => 'el portal',
    'include/staff/footer.inc.php' => 'el panel',
] as $file => $what) {
    $footer = read("$root/$file");

    check(strpos($footer, 'templates/password-strength.inc.php') !== false,
        "$what carga el indicador de fuerza",
        "$file no incluye templates/password-strength.inc.php");
}

check(strpos($pw, "name=\"passwd1\"") !== false,
    'el indicador reconoce los campos passwd1/passwd2 de osTicket',
    'js/password-strength.js no reconoce passwd1 (los diálogos del panel se quedarían sin medidor)');

check(strpos($pw, 'MutationObserver') !== false,
    'el indicador se engancha también en formularios cargados por AJAX',
    'js/password-strength.js no observa el DOM: el diálogo de cambio de contraseña se queda sin medidor');

check(strpos($register, 'data-password-strength') !== false
    && strpos($profile, 'data-password-strength') !== false,
    'alta y perfil marcan el campo de contraseña',
    'falta data-password-strength en el alta o en el perfil');

check(strpos($register, 'data-password-confirm') !== false,
    'el alta comprueba que las dos contraseñas coinciden',
    'falta data-password-confirm en el alta');

check(strpos($pw, 'addEventListener') !== false
    && strpos($pw, 'role="status"') !== false,
    'el indicador es accesible (aria-live) y no usa oninput en línea',
    'js/password-strength.js no anuncia el nivel o no usa addEventListener');

check(strpos($uicss, '.pw-strength-bar') !== false,
    'css/ui.css dibuja el medidor',
    'css/ui.css no define .pw-strength-bar');

/* 4e. Enrutado: sin el respaldo de PATH_INFO, los ajax del panel dan 400. */
$osticket = read("$root/include/class.osticket.php");

check(strpos($osticket, "static function get_path_info()") !== false
    && strpos($osticket, "REQUEST_URI") !== false,
    'get_path_info() deduce la ruta si el servidor no rellena PATH_INFO',
    'get_path_info() no tiene respaldo: los ajax.php devolverían 400 «URL not supported» sin PATH_INFO');

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

check(strpos($sw, "'css/redactor.css'") !== false
    && strpos($sw, "'css/filedrop.css'") !== false,
    'el service worker precachea las hojas del editor y de adjuntos',
    'sw.js no precachea css/redactor.css o css/filedrop.css (sin conexión el editor saldría sin estilos)');

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

/* 7. Diagnóstico de rutas y documentación al día -------------------------- */
$dispatcher = read("$root/include/class.dispatcher.php");
check(strpos($dispatcher, 'OST_DEBUG') !== false,
    'el 400 del enrutador dice la ruta recibida en OST_DEBUG',
    'include/class.dispatcher.php no informa de la ruta recibida: depurar un 400 en ajax.php vuelve a ser adivinar');

$guide = read("$root/docs/UI_GUIDE.md");
check(strpos($guide, '### 6.1 Entregado') !== false
    && strpos($guide, '### 6.2 Pendiente') !== false,
    'UI_GUIDE separa lo entregado de lo pendiente',
    'docs/UI_GUIDE.md no tiene las secciones 6.1 (Entregado) y 6.2 (Pendiente): el estado real se pierde');

check(strpos($guide, 'Pendiente (requiere tocar marcado') === false
    && strpos($guide, '~~Sustituir los iconos de Font Awesome') === false,
    'UI_GUIDE no conserva listas de pendientes ya cerradas',
    'docs/UI_GUIDE.md sigue anunciando como pendiente algo que ya está hecho');

check(strpos($guide, 'Valid CSRF Token Required') !== false
    && strpos($guide, 'URL not supported') !== false,
    'UI_GUIDE explica cómo distinguir los 400 del panel',
    'docs/UI_GUIDE.md no distingue «URL not supported» de «Valid CSRF Token Required»');

/* 8. Iconos de las barras y de los avisos -------------------------------- */
check(substr_count($uicss, '--ost-nav-icon:') >= 60
    && strpos($uicss, '--ost-i-home') !== false
    && strpos($uicss, 'mask-image: var(--ost-nav-icon)') !== false,
    'los iconos de #nav y #sub_nav se dibujan con máscaras SVG',
    'css/ui.css no tiene las máscaras de #nav/#sub_nav: los iconos vuelven a ser PNG con fondo blanco');

$preview = read("$root/docs/theme-preview.html");
check(strpos($preview, 'id="error_bar"') !== false
    && strpos($preview, 'class="error-banner"') !== false,
    'la vista previa incluye los avisos del sistema',
    'docs/theme-preview.html no muestra error_bar/warning_bar/notice_bar: no se revisan sus iconos');

check(strpos($uicss, '--ost-i-exclamation-sign') !== false
    && strpos($uicss, 'mask-image: var(--ost-bar-icon)') !== false,
    'los avisos usan máscara SVG en vez de error.png/alert.png/ok.png',
    'css/ui.css no sustituye los PNG de los avisos: en oscuro se ve el cuadro blanco de fondo');

check(substr_count($dark, '--ost-bar-color') >= 6,
    'los iconos de aviso cambian de color en oscuro',
    'css/dark.css no ajusta --ost-bar-color de los avisos: el icono queda con el color del tema claro');

/* 9. Acciones declarativas ----------------------------------------------- */
$actions = read("$root/js/actions.js");
check(strpos($actions, 'data-remove-closest') !== false
    && strpos($actions, 'data-inherit-toggle') !== false
    && strpos($actions, 'data-save-draft') !== false
    && strpos($actions, 'data-toggle-target') !== false,
    'js/actions.js cubre las acciones que sustituyen a los onclick',
    'js/actions.js no implementa data-remove-closest/data-inherit-toggle/data-save-draft/data-toggle-target');

check(strpos($actions, "document.addEventListener('click'") !== false
    && strpos($actions, "document.addEventListener('change'") !== false,
    'las acciones se resuelven por delegación (funcionan con PJAX y diálogos)',
    'js/actions.js no delega en document: el contenido cargado por AJAX se quedaría sin acciones');

$makefile = read("$root/Makefile");
check(strpos($makefile, 'inline-budget') !== false
    && strpos($guide, 'inline-budget') !== false,
    'hay tope para los manejadores en línea (make inline-budget)',
    'falta el objetivo inline-budget: el número de onclick puede volver a subir sin que nadie lo note');

/* 10. Iconos con comportamiento (ayuda, tips) ---------------------------- */
check(strpos($guide, 'help-tip') !== false
    && strpos($guide, 'desaparec') !== false,
    'UI_GUIDE explica por qué los iconos de ayuda conservan sus atributos',
    'docs/UI_GUIDE.md no documenta la conservación de href/data-* en los help-tip');

check(strpos($icons, 'icon.attributes') !== false,
    'js/icons.js copia los atributos del <i> al <svg> (href, data-*, id)',
    'js/icons.js no copia los atributos: los help-tip pierden el href y la ayuda no abre');

check(strpos(read("$root/scp/js/tips.js"), 'elem.remove()') === false,
    'tips.js no borra el icono cuando no encuentra la ayuda',
    'scp/js/tips.js sigue haciendo elem.remove(): el icono de ayuda desaparece al pulsarlo');

check(strpos(read("$root/scp/js/jb.overflow.menu.js"), 'svg.icon-sort-down') !== false,
    'el menú de desbordamiento quita también los iconos ya convertidos a SVG',
    'scp/js/jb.overflow.menu.js sólo busca i.icon-sort-down: los caret de la barra de colas se duplican');

check(strpos($actions, 'data-click-target') !== false
    && strpos($actions, 'data-pjax-submit') !== false,
    'js/actions.js cubre los últimos onclick (pulsar elementos y PJAX)',
    'js/actions.js no implementa data-click-target/data-pjax-submit');

check(strpos($actions, 'data-set-name') !== false
    && strpos($actions, 'data-call') !== false,
    'js/actions.js cubre escribir campos y llamar a funciones con nombre',
    'js/actions.js no implementa data-set-name/data-call');

check(strpos($actions, 'data-ost-dialog-row') !== false,
    'js/actions.js abre diálogos con el id de la fila (data-ost-dialog-row)',
    'js/actions.js no implementa data-ost-dialog-row');

/* `data-dialog` es de osTicket y guarda una URL: si el nuestro lo reutiliza,
   scp.js abre el diálogo con un número, .load() revienta y el overlay
   «Loading» se queda encima de la página. */
check(strpos($actions, "hasAttribute('data-dialog')") === false
    && strpos($actions, '[data-dialog]') === false,
    'js/actions.js no reutiliza el data-dialog de osTicket',
    'js/actions.js usa data-dialog, que en osTicket es la URL del diálogo: usa data-ost-dialog');

check(strpos(read("$root/scp/js/scp.js"), "typeof dialog !== 'string'") !== false,
    'scp.js ignora los data-dialog que no son una URL de texto',
    'scp/js/scp.js pasaría un data-dialog numérico a $.dialog y dejaría el overlay «Loading» colgado');

$numericDialog = array();
foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/include')) as $f) {
    if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php')
        continue;
    if (preg_match('/data-dialog="[0-9]/', file_get_contents($f->getPathname())))
        $numericDialog[] = str_replace($root . '/', '', $f->getPathname());
}

check(!$numericDialog,
    'ninguna plantilla usa data-dialog con un código de estado',
    'hay plantillas con data-dialog numérico: ' . implode(', ', array_slice($numericDialog, 0, 5)));

/* Buscador de usuarios: los onclick llamaban a $.userLookup con un callback
   distinto en cada sitio. Ahora el callback se describe con atributos, así
   que el marcado no lleva JavaScript y el resultado se puede comprobar. */
check(strpos($actions, 'data-ost-user-lookup') !== false
    && strpos($actions, '$.userLookup') !== false,
    'js/actions.js abre el buscador de usuarios (data-ost-user-lookup)',
    'js/actions.js no implementa data-ost-user-lookup');

check(strpos($actions, 'data-ost-user-lookup-fill') !== false
    && strpos($actions, 'data-ost-user-lookup-value') !== false
    && strpos($actions, 'data-ost-user-lookup-add') !== false,
    'el resultado del buscador se aplica con atributos (texto, campos, opciones)',
    'js/actions.js no aplica el usuario elegido con data-ost-user-lookup-fill/-value/-add');

$lookupFiles = array('include/staff/ticket-edit.inc.php',
    'include/staff/ticket-open.inc.php', 'include/staff/ticket-view.inc.php');
$inlineLookup = array();
$withoutLookup = array();
foreach ($lookupFiles as $file) {
    $source = read("$root/$file");
    if (preg_match('/\bon(?:click|change|submit)\s*=/', $source))
        $inlineLookup[] = $file;
    if (strpos($source, 'data-ost-user-lookup') === false)
        $withoutLookup[] = $file;
}

check(!$inlineLookup,
    'el buscador de usuarios ya no usa onclick en las plantillas',
    'siguen con manejadores en línea: ' . implode(', ', $inlineLookup));

check(!$withoutLookup,
    'las plantillas del buscador usan data-ost-user-lookup',
    'no usan data-ost-user-lookup: ' . implode(', ', $withoutLookup));

/* Acciones de AJAX y de filas: sustituyen a los onclick de los ajustes, del
   perfil y de los formularios dinámicos. */
foreach (array('data-ost-ajax' => 'las peticiones AJAX',
    'data-ost-mark-removed' => 'marcar una fila como quitada',
    'data-ost-drop-row' => 'quitar los campos de un bloque') as $attr => $what) {
    check(strpos($actions, $attr) !== false,
        "js/actions.js implementa $attr ($what)",
        "js/actions.js no implementa $attr: $what se queda en el onclick");
}

foreach (array('ostAuthenticationBackend' => 'elegir el backend de autenticación',
    'ostAddFormEntry' => 'añadir un formulario a la lista',
    'ostChangeAvatar' => 'cambiar el avatar del personal') as $fn => $what) {
    check(strpos($actions, 'window.' . $fn . ' =') !== false,
        "js/actions.js define $fn ($what)",
        "falta window.$fn en js/actions.js");
}

$noInline = array();
foreach (array('include/staff/profile.inc.php', 'include/staff/settings-system.inc.php',
    'include/staff/staff.inc.php', 'include/staff/templates/dynamic-form.tmpl.php',
    'include/staff/templates/user-register.tmpl.php',
    'include/staff/templates/form-manage.tmpl.php',
    'include/staff/templates/refer.tmpl.php') as $file) {
    if (preg_match('/\bon(?:click|change|submit)\s*=/', read("$root/$file")))
        $noInline[] = $file;
}

check(!$noInline,
    'los ajustes, el perfil y los formularios ya no llevan onclick',
    'siguen con manejadores en línea: ' . implode(', ', $noInline));

/* Ya no queda ningún manejador en línea (`onclick=`, `onchange=`…): es la
   condición para poder quitar 'unsafe-inline' del CSP (OST-102). Si vuelve a
   aparecer uno, esta comprobación lo dice con archivo y línea. */
$inlineFound = array();
$events = 'click|change|submit|keydown|keyup|keypress|input|focus|blur|load|'
    . 'mouseover|mouseout|mousedown|mouseup|dblclick|contextmenu|paste|error';
foreach (array('include', 'scp') as $dir) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (substr($file->getFilename(), -4) !== '.php')
            continue;
        $source = file_get_contents($file->getPathname());
        if (preg_match_all('/\bon(?:' . $events . ')\s*=\s*[\"\']/i', $source, $m,
                PREG_OFFSET_CAPTURE)) {
            $rel = str_replace($root . '/', '', $file->getPathname());
            $line = substr_count(substr($source, 0, $m[0][0][1]), "\n") + 1;
            $inlineFound[] = "$rel:$line";
        }
    }
}

check(!$inlineFound,
    'ninguna plantilla usa manejadores en línea (onclick, onchange…)',
    'siguen con manejadores en línea: ' . implode(', ', array_slice($inlineFound, 0, 5)));

foreach (array('data-ost-expand' => 'desplegar un campo de búsqueda',
    'data-ost-remove-row' => 'quitar una fila y liberar su opción',
    'data-ost-append-hidden' => 'apuntar el id de la fila quitada') as $attr => $what) {
    check(strpos($actions, $attr) !== false,
        "js/actions.js implementa $attr ($what)",
        "js/actions.js no implementa $attr: $what se queda sin hacer");
}

foreach (array('ostAddFilterAction' => 'añadir una acción a un filtro',
    'ostAddMergeTicket' => 'añadir un ticket a la fusión',
    'ostAddSequence' => 'añadir una secuencia',
    'ostAddQueueProperty' => 'añadir una propiedad a una condición',
    'ostDropAnnotation' => 'quitar una anotación de columna') as $fn => $what) {
    check(strpos($actions, 'window.' . $fn . ' =') !== false,
        "js/actions.js define $fn ($what)",
        "falta window.$fn en js/actions.js");
}

// La familia del hilo: al repartir class.thread.php (frente 6) la interfaz
// Threadable y sus rasgos pasaron a class.thread.objects.php. Se mira la
// familia entera para que el reparto no vuelva a dejar esta comprobación
// mirando un archivo que ya no contiene el método.
$threadFamily = read("$root/include/class.thread.php")
    . read("$root/include/class.thread.objects.php");
check(strpos($threadFamily, 'function getAttributes()') !== false
    && strpos(read("$root/include/staff/templates/thread-entry.tmpl.php"),
        'getAttributes()') !== false,
    'las acciones de la entrada del hilo se abren con atributos',
    'thread-entry.tmpl.php sigue generando un onclick con getJsStub()');

/* 11. Herramientas de revisión visual ------------------------------------- */
check(file_exists("$root/tools/ui/dark-audit.js"),
    'existe la auditoría de superficies claras (dark-audit)',
    'falta tools/ui/dark-audit.js: las zonas claras del tema oscuro sólo se ven a ojo');

check(strpos(read("$root/docs/theme-preview.html"), 'data-allow-light') !== false
    && strpos(read("$root/docs/theme-preview-client.html"), 'data-allow-light') !== false,
    'las vistas previas marcan el papel que debe seguir claro',
    'las vistas previas no marcan data-allow-light: dark-audit marcaría el visor de PDF y el logo');

check(strpos($actions, 'data-pjax-query') !== false
    && strpos($actions, 'ostHelpTopic') !== false
    && strpos($actions, 'ostAutodetectTimezone') !== false,
    'js/actions.js cubre ordenación, filtros, tema de ayuda y zona horaria',
    'js/actions.js no implementa data-pjax-query/ostHelpTopic/ostAutodetectTimezone');

check(strpos(read("$root/include/staff/footer.inc.php"), 'js/actions.js') !== false
    && strpos(read("$root/include/client/footer.inc.php"), 'js/actions.js') !== false,
    'los dos pies de página cargan js/actions.js',
    'alguna de las dos interfaces no carga js/actions.js: los data-* no harían nada');

/* 12. Arranque sin PHP instalado ----------------------------------------- */
check(file_exists("$root/tools/php.sh"),
    'existe tools/php.sh (PHP del host o del contenedor)',
    'falta tools/php.sh: sin PHP en el equipo, make theme/inline-audit fallan con «php: No such file or directory»');

check(strpos(read("$root/Makefile"), 'tools/php.sh') !== false,
    'el Makefile usa tools/php.sh en vez de llamar a php a pelo',
    'el Makefile llama a php directamente: en un equipo sin PHP los objetivos fallan');

/* El contenedor monta el proyecto en /var/www/html: si php.sh le pasa la ruta
   del equipo, la respuesta es «Could not open input file». */
$phpSh = read("$root/tools/php.sh");
check(strpos($phpSh, 'OST_CONTAINER_DIR') !== false
    && strpos($phpSh, '/var/www/html') !== false,
    'tools/php.sh traduce las rutas del equipo al montaje del contenedor',
    'tools/php.sh pasa rutas del equipo al contenedor: «Could not open input file»');

check(strpos($phpSh, 'docker compose run --rm --no-deps tools php') !== false,
    'tools/php.sh ejecuta dentro del servicio tools',
    'tools/php.sh no usa el servicio tools de compose');

check(file_exists("$root/tools/ui/browser-audit.sh"),
    'existe tools/ui/browser-audit.sh (navegador propio o contenedor)',
    'falta tools/ui/browser-audit.sh: las auditorías con navegador exigen instalar Playwright');

check(strpos(read("$root/Makefile"), 'browser-audit.sh') !== false,
    'make dark-audit/contrast-audit usan browser-audit.sh',
    'el Makefile llama a node directamente: sin Playwright el objetivo falla');

/* La imagen oficial de Playwright no incluye el paquete de Node (solo los
   navegadores): el lanzador tiene que instalarlo él mismo. */
$browserAudit = read("$root/tools/ui/browser-audit.sh");
check(strpos($browserAudit, 'playwright-core@') !== false
    && strpos($browserAudit, '/pwnode') !== false,
    'browser-audit.sh instala playwright-core en un volumen cacheado',
    'la imagen de Playwright no trae el paquete: browser-audit.sh debe instalarlo en un volumen');

check(strpos($browserAudit, 'host.docker.internal') !== false,
    'browser-audit.sh traduce localhost a host.docker.internal',
    'las URLs con localhost no llegarían al equipo desde el contenedor');

check(strpos(read("$root/Makefile"), '--out=$(ROOT)/ost-iconos.html') !== false,
    'make icons escribe la hoja dentro del proyecto',
    'make icons escribe en /tmp: dentro del contenedor el archivo no llega al equipo');

/* Editor: superficies y especificidad ------------------------------------- */
// Redactor 3 pone la clase `.redactor-in` al elemento editable. El tema
// hablaba de `.redactor-editor`, que no existe, asi que ninguna regla del
// area de escritura se aplicaba nunca.
$darkSinComentarios = preg_replace('#/\*.*?\*/#s', '', $dark);
check(strpos($darkSinComentarios, '.redactor-in') !== false,
    'el tema oscuro estiliza la superficie editable (.redactor-in)',
    'el tema apunta a .redactor-editor: esa clase no existe en Redactor 3');

check(strpos($darkSinComentarios, '.redactor-editor') === false,
    'no queda ninguna regla muerta de .redactor-editor',
    'quedan reglas para .redactor-editor: no se aplican a nada');

// css/redactor.css pinta la barra con cuatro clases y se carga despues de
// modern.css: sin igualar esa especificidad, en oscuro la barra sale clara.
check(strpos($darkSinComentarios,
        '.redactor-box.redactor-styles-on .redactor-toolbar.redactor-toolbar') !== false,
    'la barra del editor gana la especificidad de css/redactor.css',
    'falta la regla de cuatro clases: la barra del editor se queda blanca en oscuro');

// El panel de respuesta y la nota interna traen fondo claro de scp.css.
check(strpos($darkSinComentarios, '#response_options > form') !== false,
    'el panel de respuesta esta tematizado en oscuro',
    '#response_options > form se queda con el #f9f9f9 de scp.css');

check(strpos($darkSinComentarios, '#note') !== false,
    'la nota interna esta tematizada en oscuro',
    'form#note no tiene regla: se queda como un panel blanco entre pestañas oscuras');

check(strpos($darkSinComentarios, '.tixTitle h3') !== false,
    'el asunto del ticket tiene color propio en oscuro',
    'scp.css pinta .tixTitle h3 en #444 y el tema no lo redefine: no se lee');

check(strpos($dark, '--dk-editor-paper:') !== false,
    'el papel del editor tiene token propio',
    'el area del editor se queda en blanco puro: deslumbra dentro del marco oscuro');

// Los botones del editor: la barra solo usa botones que existen.
$redactorJs = read("$root/js/redactor-osticket.js");
foreach (array("'undo'", "'redo'", "'indent'", "'outdent'", "'ul'", "'ol'") as $boton)
    check(strpos($redactorJs, $boton) !== false,
        "la barra del editor trae el boton $boton",
        "falta el boton $boton en la barra del editor");

check(strpos($redactorJs, "'fullscreen']") !== false
    && strpos($redactorJs, "'fontfamily','fullscreen'") !== false,
    'el plugin fullscreen se carga (sin el, el boton no llega a crearse)',
    'el boton de pantalla completa esta en buttons pero su plugin no en plugins');

check(strpos($redactorJs, 'redactor-separator') !== false
    && strpos($sinComentarios, '.redactor-toolbar .redactor-separator') !== false,
    'los grupos de la barra se separan con un trazo',
    'los `|` de la lista de botones no dibujan nada: la barra sale sin grupos');

/* Tablas ---------------------------------------------------------------- */
check(strpos($sinComentarios, 'table.dashboard-stats td:not(:empty)') !== false,
    'la tabla de estadisticas anula el blanco por celda',
    'cada celda sigue blanca: la cebra y el hover no se ven');

check(strpos($sinComentarios, 'ul.tabs,') !== false
    && strpos($sinComentarios, '#response_options > form {') !== false,
    'las pestañas y el panel de respuesta siguen el lenguaje de la capa moderna',
    'ul.tabs y #response_options > form conservan el gris y las sombras de scp.css');

/* Ajustes del panel (table.form_table) ----------------------------------- */
// Las pantallas de `scp/settings.php` y `scp/admin.php` son las que siguen
// usando `table.form_table`. scp.css las deja con bordes #ddd sueltos, celdas
// de 4 px y una cabecera gris con el texto en blanco; la capa moderna las
// lleva al mismo lenguaje que el resto del panel (css/modern.css, seccion 12).
check(strpos($sinComentarios, 'table.form_table,') !== false
    && strpos($sinComentarios, 'border-collapse: separate;') !== false
    && strpos($sinComentarios, 'border-radius: var(--mod-radius);') !== false,
    'la tabla de ajustes es una tarjeta con esquinas redondeadas',
    'table.form_table conserva los bordes sueltos de scp.css: sin `border-collapse: separate` el radio no se dibuja');

check(strpos($sinComentarios, 'td:first-child:nth-last-child(2)') !== false,
    'la etiqueta se distingue de las filas de un solo campo (colspan)',
    'la regla de la etiqueta alcanza también a las filas `colspan=2`: la ayuda de la cabecera saldría en negrita');

check(strpos($sinComentarios, 'table.form_table th h4 {') !== false
    && strpos($sinComentarios, 'background: transparent;') !== false,
    'la cabecera de la tabla deja de ser una banda gris con texto blanco',
    'el h4 de la cabecera sigue pintando su propia banda encima de la del th');

check(preg_match('/html\[data-theme="dark"\] \.form_table th h4 \{[^}]*background:\s*transparent/s',
        $darkSinComentarios) === 1,
    'en oscuro la cabecera tampoco lleva doble banda',
    'css/dark.css sigue pintando el h4 sobre el th: dos bandas encima de la misma fila');

check(strpos($uicss, 'background: #5a636b') === false,
    'ui.css ya no oscurece la banda de la cabecera',
    'css/ui.css sigue forzando la banda #5a636b sobre la cabecera nueva');

check(strpos($sinComentarios, 'tbody tr:hover > td:not(.nohover)') !== false,
    'las filas de ajustes se resaltan al pasar el puntero',
    'la tabla de ajustes no resalta la fila: con dos columnas se pierde el renglón');

check(strpos($preview, 'class="form_table settings_table"') !== false
    && strpos($preview, 'class="section-break"') !== false,
    'la vista previa trae la pantalla de ajustes completa',
    'docs/theme-preview.html no muestra la pantalla de ajustes: no se puede revisar sin Docker');

/* Estados vacios -------------------------------------------------------- */
$kb = read("$root/include/staff/faq-categories.inc.php");
check(strpos($kb, "placeholder=\"<?php echo __('Search the knowledge base'); ?>\"") !== false,
    'el buscador de la base de conocimiento tiene pista',
    'el buscador de la base de conocimiento no dice que se puede escribir ahi');

check(substr_count($kb, 'class="empty-state"') === 2,
    'la base de conocimiento explica las dos salidas vacias',
    'las salidas vacias de la base de conocimiento siguen siendo una linea suelta');

/* Ficha del ticket ------------------------------------------------------ */
$vista = read("$root/include/staff/ticket-view.inc.php");
check(strpos($vista, 'Priority::lookup(') !== false
    && strpos($vista, "getAnswer()->display()") === false,
    'la fila de prioridad nunca se queda vacia',
    'la fila de prioridad sale en blanco cuando el ticket no tiene respuesta guardada');

/* Segunda pasada: lo que dejaban ver las capturas ---------------------- */
// osTicket usa select2 v4 (css/select2.min.css). El tema oscuro solo cubria
// la API v3 (`.select2-choice`, `.select2-choices`), asi que la caja y el
// desplegable seguian con el blanco del plugin.
check(strpos($darkSinComentarios, '.select2-container--default') !== false
    && strpos($darkSinComentarios, 'select2-selection--single') !== false,
    'select2 v4 (caja y desplegable) tiene equivalente oscuro',
    'el select2 v4 se queda blanco: es la caja de «Select a canned response»');

// El buscador de la base de conocimiento usa el mismo marcado que el de las
// colas pero sin la clase; la regla tiene que ir por el tipo de input.
check(strpos($sinComentarios, '#basic_search input[type="text"]') !== false,
    'el buscador por tipo alcanza tambien al de la base de conocimiento',
    'solo se estiliza `.basic-search`: el buscador de la KB se queda nativo');

// La ficha del ticket son dos tablas dentro de otra: sin reglas propias, cada
// mitad sale de un tono y el bloque parece roto.
check(strpos($sinComentarios, '.ticket_info th') !== false
    && strpos($darkSinComentarios, '.ticket_info th') !== false,
    'la ficha del ticket se lee como un solo panel en los dos temas',
    'las etiquetas de la ficha conservan el gris y el fondo de scp.css');

// Los iconos de la linea de tiempo (lapiz, reloj) son una caja con un borde
// blanco de 16 px: en oscuro, un cuadrado blanco.
check(strpos($darkSinComentarios, '.type-icon::after') !== false,
    'los iconos de la linea de tiempo siguen el tema oscuro',
    '.type-icon::after conserva el borde blanco: es un cuadrado claro en oscuro');

// El grafico del panel: scp/css/dashboard.css se carga al final del cuerpo,
// o sea despues de modern.css; por eso los selectores llevan `body` delante.
check(strpos($sinComentarios, 'body #line-chart-here') !== false,
    'el grafico del panel gana a la hoja que se carga despues',
    'sin `body` delante, scp/css/dashboard.css gana y vuelve el degradado de IE');

check(strpos($darkSinComentarios, '#line-chart-here text') !== false,
    'las etiquetas de los ejes se ven en oscuro',
    'las etiquetas de los ejes se dibujan en negro sobre la superficie oscura');

/* Verdades que la pantalla no puede contradecir ------------------------- */
$plantillaCola = read("$root/include/staff/templates/queue-tickets.tmpl.php");
check(strpos($plantillaCola, '$rendered++') !== false
    && strpos($plantillaCola, '$pageNav->setTotal(0, true);') !== false,
    'la cola no promete filas que la tabla no trae',
    'el pie sigue diciendo «Showing 1 - 23» con la tabla vacia');

// El modulo y sus rasgos: `backdate()` vive en `seed.php` y la revision y la
// reparacion en `seed.repair.php` (frente 6).
$sembrador = read("$root/include/cli/modules/seed.php")
    . read("$root/include/cli/modules/seed.repair.php");
check(strpos($sembrador, 'ThreadEntry::objects()') !== false
    && strpos($sembrador, 'function backdate(') !== false,
    'el sembrador fecha la entrada de apertura con el ticket',
    'la apertura se queda en NOW(): los tiempos de respuesta salen negativos');

$informe = read("$root/include/class.report.php");
check(strpos($informe, "new SqlFunction('GREATEST'") !== false,
    'el informe acota el tiempo de respuesta a cero',
    'una base sembrada antes del arreglo sigue publicando tiempos negativos');

// Las pistas del doctor prometian `--doctor --repair` y la opcion no existia:
// el comando respondia lo mismo con y sin ella.
check(strpos($sembrador, "'repair' => array") !== false
    && strpos($sembrador, 'function repair(') !== false,
    'el doctor puede reparar lo que detecta (--repair)',
    'las pistas dicen `--doctor --repair` y esa opcion no hace nada');

/* Resumen ---------------------------------------------------------------- */
echo "\n";
if ($failures === 0) {
    echo "Todo correcto: $checks comprobaciones.\n";
    exit(0);
}

echo "$failures de $checks comprobaciones fallaron.\n";
exit(1);
