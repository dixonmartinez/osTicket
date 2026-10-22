#!/usr/bin/env php
<?php
/**
 * tools/ui/icon-sheet.php
 *
 * Genera una hoja con todos los iconos dibujados en js/icons.js para
 * revisarlos a ojo (alineación, grosor de trazo, formas que no se
 * entienden a 13 px). No necesita servidor ni base de datos.
 *
 * Uso:
 *   php tools/ui/icon-sheet.php > /tmp/iconos.html
 *   php tools/ui/icon-sheet.php --out=/tmp/iconos.html
 *   php tools/ui/icon-sheet.php --theme=dark > /tmp/iconos-oscuro.html
 *
 * Después abre el archivo en el navegador. Los iconos se dibujan con el
 * mismo js/icons.js de la aplicación, así que lo que se ve aquí es lo que
 * verá el agente.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$options = getopt('', ['out::', 'theme::', 'help']);

if (isset($options['help'])) {
    echo "icon-sheet — hoja de revisión de los iconos SVG\n\n"
        . "  php tools/ui/icon-sheet.php [--out=archivo.html] [--theme=light|dark]\n\n";
    exit(0);
}

$source = @file_get_contents("$root/js/icons.js");
if ($source === false) {
    fwrite(STDERR, "No se pudo leer js/icons.js\n");
    exit(1);
}

preg_match_all("/'(icon-[a-z0-9-]+)'\s*:/", $source, $matches);
$icons = array_values(array_unique($matches[1]));
sort($icons);

if (!$icons) {
    fwrite(STDERR, "js/icons.js no tiene entradas en SVG_ICONS\n");
    exit(1);
}

$theme = isset($options['theme']) && $options['theme'] === 'dark' ? 'dark' : 'light';
$colors = $theme === 'dark'
    ? ['bg' => '#12161b', 'card' => '#1c242d', 'border' => '#333e4a', 'text' => '#e6ebf0',
       'muted' => '#a6b2be', 'icon' => '#cfe2f6']
    : ['bg' => '#ffffff', 'card' => '#f7f9fb', 'border' => '#d8dee5', 'text' => '#1f2933',
       'muted' => '#5b6670', 'icon' => '#1b57a5'];

$cells = '';
foreach ($icons as $icon) {
    $cells .= sprintf(
        "<figure><i class=\"%s\"></i><figcaption>%s</figcaption></figure>\n",
        $icon, htmlspecialchars(substr($icon, 5), ENT_QUOTES, 'UTF-8'));
}

$html = <<<HTML
<!doctype html>
<html lang="es" data-svg-icons="on">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Iconos SVG — {$theme} ({%COUNT%})</title>
<style>
:root { color-scheme: {$theme}; }
body { margin: 0; padding: 24px; background: {$colors['bg']}; color: {$colors['text']};
       font: 14px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
h1 { font-size: 18px; margin: 0 0 4px; }
p.lead { margin: 0 0 20px; color: {$colors['muted']}; font-size: 13px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 8px; }
figure { margin: 0; padding: 10px 6px; text-align: center; background: {$colors['card']};
         border: 1px solid {$colors['border']}; border-radius: 8px; }
i { display: flex; align-items: center; justify-content: center; height: 26px;
    font-size: 16px; color: {$colors['icon']}; }
figcaption { margin-top: 6px; font-size: 10px; color: {$colors['muted']};
             overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
</head>
<body>
<h1>Iconos SVG — tema {$theme} ({%COUNT%})</h1>
<p class="lead">Generado por <code>tools/ui/icon-sheet.php</code>. Si un icono no se
entiende a este tamaño, conviene redibujarlo en <code>js/icons.js</code>.</p>
<div class="grid">
{$cells}</div>
<script src="{%ICONS_JS%}"></script>
</body>
</html>
HTML;

$html = str_replace(
    ['{%COUNT%}', '{%ICONS_JS%}'],
    [count($icons), 'file://' . realpath("$root/js/icons.js")],
    $html);

if (isset($options['out']) && $options['out'] !== false) {
    if (file_put_contents($options['out'], $html) === false) {
        fwrite(STDERR, "No se pudo escribir {$options['out']}\n");
        exit(1);
    }
    fwrite(STDERR, count($icons) . " iconos en {$options['out']}\n");
} else {
    echo $html;
}

exit(0);
