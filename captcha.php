<?php
/*********************************************************************
    captcha.php

    Simply returns captcha image.

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
require_once('main.inc.php');
require(INCLUDE_DIR.'class.captcha.php');

// Sin GD no hay imagen. Un 500 explícito es más útil para el operador que
// un PNG vacío (el formulario avisa de que el captcha no se pudo leer).
if (!extension_loaded('gd'))
    Http::response(500, __('Captcha image is not available'));

$captcha = new Captcha(5,12,ROOT_DIR.'images/captcha/');

// La imagen es de un solo uso: no puede quedar en la caché del navegador ni
// en la de un proxy intermedio.
header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

echo $captcha->getImage();
exit;
