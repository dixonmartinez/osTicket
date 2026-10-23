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

/* Resumen ---------------------------------------------------------------- */
echo "\n";
if ($failures === 0) {
    echo "Todo correcto: $checks comprobaciones.\n";
    exit(0);
}

echo "$failures de $checks comprobaciones fallaron.\n";
exit(1);
