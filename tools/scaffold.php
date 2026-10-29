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
 *   php tools/scaffold.php plugin <nombre> [--title="Nombre"] [--author="…"]
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
  php tools/scaffold.php plugin <nombre> [--title="Nombre"] [--author="…"]

Ejemplos
  php tools/scaffold.php page informes --title="Informes"
  php tools/scaffold.php page mis-datos --client --title="Mis datos"
  php tools/scaffold.php ajax inventario
  php tools/scaffold.php plugin ldap --title="Autenticación LDAP" --author="Ana"

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

$author = $options['author'] ?? '';

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

case 'plugin':
    /* Un plugin es una carpeta en include/plugins/ con un plugin.php que
       DEVUELVE un arreglo. Las claves las normaliza PluginInfo
       (include/class.plugin.info.php); las de abajo son las obligatorias. */
    $pluginTpl = <<<'PHP'
<?php
/**
 * include/plugins/{{slug}}/plugin.php
 *
 * Declaración del plugin. **Devuelve un arreglo**; no imprime nada.
 * Las claves que falten las completa `PluginInfo::normalize()`.
 */
require_once(INCLUDE_DIR . 'plugins/{{slug}}/class.{{slug}}.php');

return array(
    // archivo:Clase dentro de la carpeta del plugin
    'plugin'      => 'class.{{slug}}.php:{{class}}Plugin',
    'name'        => '{{title}}',
    'version'     => '0.1.0',
    // Versión de osTicket que necesita. Se compara por mayor.menor, así que
    // '1.18' vale para cualquier 1.18.x. Omítela si no depende de nada.
    'ost_version' => '1.18',
    // Subcarpeta de las clases (por omisión, 'include/')
    'include'     => '',
    'author'      => '{{author}}',
    'description' => '{{title}}',
);
PHP;

    $classTpl = <<<'PHP'
<?php
/**
 * include/plugins/{{slug}}/class.{{slug}}.php
 *
 * Lógica del plugin. `bootstrap()` se ejecuta en cada petición cuando el
 * plugin está activo; `getConfigClass()` devuelve la clase que declara las
 * opciones que se ven en Administración → Plugins.
 */
class {{class}}Plugin extends Plugin {

    /** Clase de configuración (debe heredar de PluginConfig). */
    var $config_class = '{{class}}PluginConfig';

    /**
     * Se llama al arrancar la aplicación, sólo si el plugin está activo.
     * Engancha aquí tus señales, filtros o rutas.
     */
    function bootstrap() {
        // Ejemplos:
        //   Signal::connect('ticket.created', array($this, 'onTicketCreated'));
        //   osTicket::register_namespace(dirname(__FILE__) . '/lib');
    }

    /**
     * Una sola instancia basta para este plugin. Devuelve true si quieres
     * poder crear varias (con su propia configuración cada una).
     */
    function isMultiInstance() {
        return false;
    }
}

/**
 * Opciones del plugin. Cada clave es una columna en la tabla de
 * configuración; los campos son los de `include/class.forms.php`.
 */
class {{class}}PluginConfig extends PluginConfig {

    function getOptions() {
        return array(
            'enabled' => new BooleanField(array(
                'label' => __('Activo'),
                'default' => true,
                'configuration' => true,
            )),
        );
    }
}
PHP;

    $readmeTpl = <<<'MD'
# {{title}}

Plugin de osTicket generado con `tools/scaffold.php`.

## Qué trae

| Archivo | Para qué |
|---|---|
| `plugin.php` | La declaración que lee `PluginInfo` (nombre, versión, clase) |
| `class.{{slug}}.php` | El plugin (`bootstrap()`) y su configuración (`PluginConfig`) |

## Probarlo

1. Copia la carpeta `{{slug}}/` a `include/plugins/` de la instalación.
2. Administración → **Manage → Plugins → Add New Plugin**: aparece en la
   lista y se instala con un botón.
3. Sus opciones salen en la ficha del plugin, pestaña **Instances**.

## Reglas

- `plugin.php` **devuelve** un arreglo. No imprimas nada ahí.
- La clase se declara como `archivo.php:Clase` y el archivo se resuelve
  dentro de la carpeta del plugin.
- No edites `include/class.plugin.php` desde un plugin: usa las señales y
  los ganchos públicos.
- Para traducir, `Plugin::translate($dominio)` con los `.mo` en `i18n/`.
MD;

    $vars['author'] = $author;
    $file = $root . '/include/plugins/' . $slug . '/plugin.php';
    writeFile($file, render($pluginTpl, $vars));
    writeFile($root . '/include/plugins/' . $slug . '/class.' . $slug . '.php',
        render($classTpl, $vars));
    writeFile($root . '/include/plugins/' . $slug . '/README.md',
        render($readmeTpl, $vars));
    echo "\nListo. Instálalo en Administración → Manage → Plugins →\n";
    echo "Add New Plugin: la carpeta ya está en include/plugins/$slug/.\n";
    break;

default:
    usage();
    exit(1);
}
