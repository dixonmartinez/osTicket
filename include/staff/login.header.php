<?php
defined('OSTSCPINC') or die('Invalid path');
Http::security_headers($cfg);
header('Content-Security-Policy: '.Http::csp($cfg));
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en"
      data-theme-default="<?php echo Format::htmlchars(osTicket::getThemeDefault()); ?>"
      data-density-default="<?php echo Format::htmlchars(osTicket::getDensityDefault()); ?>">
<head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8" />
    <meta http-equiv="refresh" content="7200" />
    <title>osTicket :: <?php echo __('Agent Login'); ?></title>
    <link rel="stylesheet" href="css/login.css" type="text/css" />
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/font-awesome.min.css">
    <link rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/ui.css" media="screen">
    <!-- Capa moderna (ver docs/UI_GUIDE.md, cap. 4) -->
    <link rel="stylesheet" href="<?php echo ROOT_PATH ?>css/modern.css" media="screen">
    <!-- Tema oscuro (ver docs/UI_GUIDE.md) -->
    <link rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/dark.css" media="screen">
    <link rel="stylesheet" href="<?php echo ROOT_PATH ?>css/density.css" media="screen">
    <meta name="robots" content="noindex" />
    <meta http-equiv="cache-control" content="no-cache" />
    <meta http-equiv="pragma" content="no-cache" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#184E81">
    <meta name="color-scheme" content="light dark">
    <link rel="icon" type="image/png" href="<?php echo ROOT_PATH ?>images/oscar-favicon-32x32.png" sizes="32x32" />
    <link rel="icon" type="image/png" href="<?php echo ROOT_PATH ?>images/oscar-favicon-16x16.png" sizes="16x16" />
    <!-- Tema claro/oscuro: se aplica antes del primer pintado (sin destello) -->
    <script type="text/javascript" src="<?php echo ROOT_PATH; ?>js/theme.js"></script>
    <script type="text/javascript" src="<?php echo ROOT_PATH; ?>js/jquery-3.7.0.min.js"></script>
    <script nonce="<?php echo Http::nonce(); ?>" type="text/javascript">
        $(document).ready(function() {
            $("input:not(.dp):visible:enabled:first").focus();
         });
    </script>
</head>
<body id="loginBody">
<?php // Selector de tema: en esta pantalla flota en la esquina (ver css/dark.css)
include INCLUDE_DIR . 'templates/theme-toggle.inc.php'; ?>

