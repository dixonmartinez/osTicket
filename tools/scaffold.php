#!/usr/bin/env php
<?php
/**
 * tools/scaffold.php — generador de esqueletos de código para osTicket.
 *
 * Elimina el trabajo mecánico al añadir una pantalla o un endpoint AJAX, de
 * forma que el tiempo se dedique a la lógica. Los esqueletos ya incluyen el
 * control de acceso, el token CSRF y el escape de salida, que son los tres
 * puntos donde históricamente aparecen los defectos.
 *
 * Uso:
 *   php tools/scaffold.php page   <nombre> [--title="Título"] [--client]
 *   php tools/scaffold.php ajax   <nombre>
 *
 * Nunca sobrescribe archivos existentes.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Sólo se puede ejecutar desde la línea de comandos\n");
    exit(2);
}

$root = dirname(__DIR__);

function usage() {
    echo <<<TXT
osTicket scaffolder

  php tools/scaffold.php page <nombre> [--title="Título"] [--client]
  php tools/scaffold.php ajax <nombre>

Ejemplos
  php tools/scaffold.php page informes --title="Informes"
  php tools/scaffold.php page mis-datos --client --title="Mis datos"
  php tools/scaffold.php ajax inventario

TXT;
}

function fail($msg) {
    fwrite(STDERR, "✗ $msg\n");
    exit(1);
}

function className($slug) {
    return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug)));
}

function writeFile($path, $contents) {
    if (file_exists($path))
        fail("Ya existe: $path (no se sobrescribe)");
    if (!is_dir(dirname($path)))
        mkdir(dirname($path), 0775, true);
    file_put_contents($path, $contents);
    echo '  creado: ' . str_replace(dirname(__DIR__) . '/', '', $path) . "\n";
}

function render($template, array $vars) {
    foreach ($vars as $key => $value)
        $template = str_replace('{{' . $key . '}}', $value, $template);
    return $template;
}

$args = array_slice($argv, 1);
$options = [];
foreach ($args as $k => $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? true;
        unset($args[$k]);
    }
}
$args = array_values($args);

if (count($args) < 2 || isset($options['help'])) {
    usage();
    exit(0);
}

list($kind, $name) = $args;
$slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name));
if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug))
    fail("Nombre inválido: '$name'. Usa letras, números, guiones o guiones bajos.");

$class = className($slug);
$title = $options['title'] ?? ucwords(str_replace(['-', '_'], ' ', $slug));
$vars = [
    'slug'  => $slug,
    'class' => $class,
    'title' => $title,
];

switch ($kind) {
case 'page':
    $client = isset($options['client']);

    if ($client) {
        $pageTpl = <<<'PHP'
<?php
/*********************************************************************
    {{slug}}.php

    Página del portal del cliente.

    Generada con tools/scaffold.php — completa la lógica de negocio.
**********************************************************************/
require_once('client.inc.php');
if (!defined('INCLUDE_DIR')) die('Fatal Error');
define('CLIENTINC_DIR', INCLUDE_DIR.'client/');
define('OSTCLIENTINC', TRUE);

require_once(INCLUDE_DIR.'class.client.php');

// client.inc.php ya validó la sesión del cliente y el token CSRF de los POST
$errors = [];
$msg = '';

$inc = '{{slug}}.inc.php';

require CLIENTINC_DIR.'header.inc.php';
require CLIENTINC_DIR.$inc;
require CLIENTINC_DIR.'footer.inc.php';
?>
PHP;
        $incTpl = <<<'PHP'
<?php
if (!defined('OSTCLIENTINC')) die('Acceso denegado');
?>
<h1><?php echo Format::htmlchars(__('{{title}}')); ?></h1>

<div class="section-break"></div>

<!-- Escapa siempre la salida: Format::htmlchars() para texto,
     Format::display() sólo para HTML ya saneado -->
<p><?php echo __('Contenido pendiente'); ?></p>
PHP;
        $pageFile = $root . '/' . $slug . '.php';
        $incFile = $root . '/include/client/' . $slug . '.inc.php';
    }
    else {
        $pageTpl = <<<'PHP'
<?php
/*********************************************************************
    {{slug}}.php

    Página del panel de agentes.

    Generada con tools/scaffold.php — completa la lógica de negocio.
**********************************************************************/
require('staff.inc.php');

/* Control de acceso: ajusta el permiso a tu dominio.
   staff.inc.php ya exige sesión válida; aquí se refina el permiso. */
if (!$thisstaff || !$thisstaff->isValid()) {
    header('Location: index.php');
    exit;
}

$errors = [];
$msg = '';

require(STAFFINC_DIR.'header.inc.php');
require(STAFFINC_DIR.'{{slug}}.inc.php');
require(STAFFINC_DIR.'footer.inc.php');
?>
PHP;
        $incTpl = <<<'PHP'
<?php
if (!defined('OSTSCPINC')) die('Acceso denegado');
?>
<h2><?php echo Format::htmlchars(__('{{title}}')); ?></h2>

<div class="section-break"></div>

<form method="post" action="{{slug}}.php">
    <?php csrf_token(); ?>
    <!-- Todo campo POST se valida y escapa en el servidor -->
    <p><?php echo __('Contenido pendiente'); ?></p>
    <button class="button" type="submit" name="do"
        value="save"><?php echo __('Save'); ?></button>
</form>
PHP;
        $pageFile = $root . '/scp/' . $slug . '.php';
        $incFile = $root . '/include/staff/' . $slug . '.inc.php';
    }

    writeFile($pageFile, render($pageTpl, $vars));
    writeFile($incFile, render($incTpl, $vars));
    echo "\nListo. Si la página debe aparecer en el menú, regístrala en\n";
    echo "include/class.nav.php (StaffNav) o include/client/nav.php.\n";
    break;

case 'ajax':
    $tpl = <<<'PHP'
<?php
/*********************************************************************
    ajax.{{slug}}.php

    Endpoint AJAX.

    Generada con tools/scaffold.php. El router está en scp/ajax.php
    (panel de agentes) o ajax.php (portal del cliente).
**********************************************************************/
if (!defined('INCLUDE_DIR')) die('!');

class {{class}}AjaxAPI extends AjaxController {

    /**
     * GET /{{slug}}/<id>
     */
    function get($id) {
        $this->staffOnly();   // quitar si lo usa el portal del cliente
        global $thisstaff;

        if (!($id = (int) $id))
            Http::response(400, __('Id inválido'));

        return $this->json_encode([
            'id' => $id,
            'label' => __('Ejemplo'),
        ]);
    }

    /**
     * POST /{{slug}}/<id>
     */
    function update($id) {
        $this->staffOnly();

        if (!($id = (int) $id))
            Http::response(400, __('Id inválido'));

        // La escritura va aquí. Valida SIEMPRE en el servidor:
        //   $errors = [];
        //   if (!Validator::is_numeric($_POST['campo'], $errors['campo'])) ...
        Http::response(204);
    }
}
PHP;
    writeFile($root . '/include/ajax.' . $slug . '.php', render($tpl, $vars));
    echo "\nRegistra la ruta en el router, por ejemplo en scp/ajax.php:\n";
    echo "  url('^/{$slug}/(?P<id>\\d+)$',\n";
    echo "      array('ajax.{$slug}.php:{$class}AjaxAPI', 'get'))\n";
    break;

default:
    usage();
    exit(1);
}
