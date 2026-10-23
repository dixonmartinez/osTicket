<?php
/**
 * tests/bootstrap.php
 *
 * Entorno mínimo para las pruebas unitarias del proyecto (frente 1 de
 * docs/CODE_QUALITY.md).
 *
 * Qué hace:
 *   1. Define ROOT_DIR / INCLUDE_DIR / PEAR_DIR, que es lo único que
 *      necesitan las clases «puras» para cargarse fuera de la aplicación.
 *   2. Sustituye `__()` por una función que devuelve el texto tal cual: en
 *      las pruebas interesa el mensaje, no su traducción.
 *   3. Carga las clases que se prueban.
 *   4. Elige la clase base de los tests: la de PHPUnit si está instalada
 *      (`composer install`); si no, una mínima incluida en tests/lib, para
 *      que `make unit` funcione recién clonado el repositorio, sin red.
 *
 * No necesita base de datos, ni configuración, ni sesión.
 */

if (!defined('ROOT_DIR'))
    define('ROOT_DIR', dirname(__DIR__));
if (!defined('INCLUDE_DIR'))
    define('INCLUDE_DIR', ROOT_DIR . '/include/');
if (!defined('PEAR_DIR'))
    define('PEAR_DIR', INCLUDE_DIR . 'pear/');

/* Los mensajes de las clases pasan por gettext. Sin traducción, el texto
   original es justo lo que interesa comprobar. */
if (!function_exists('__')) {
    function __($text) {
        return $text;
    }
}

/* Igual que arriba para las variantes que usan algunas clases. */
if (!function_exists('_N')) {
    function _N($text) {
        return $text;
    }
}
if (!function_exists('dgettext')) {
    function dgettext($domain, $text) {
        return $text;
    }
}

/* PEAR se busca por include_path (Mail/RFC822.php hace
   `require_once 'PEAR.php'`), igual que hace bootstrap.php en la aplicación. */
set_include_path(PEAR_DIR . PATH_SEPARATOR . INCLUDE_DIR . PATH_SEPARATOR
    . get_include_path());

require_once INCLUDE_DIR . 'class.charset.php';
require_once INCLUDE_DIR . 'class.format.php';
require_once INCLUDE_DIR . 'class.validator.php';

/* Clase base: PHPUnit si se ha instalado con Composer; si no, la de lib/. */
if (class_exists('PHPUnit\Framework\TestCase'))
    class_alias('PHPUnit\Framework\TestCase', 'UnitTestCase');
else
    require_once __DIR__ . '/lib/UnitTestCase.php';
