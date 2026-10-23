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

/* El ORM construye la clave de su caché de APCu con estas constantes. En
   una instalación las define osTicket; aquí no hay instalación, y el
   contenedor sí trae APCu, así que se dejan definidas. */
if (!defined('SECRET_SALT'))
    define('SECRET_SALT', 'tests');
if (!defined('GIT_VERSION'))
    define('GIT_VERSION', 'tests');

/* Igual que arriba para las variantes que usan algunas clases. */
if (!function_exists('_S')) {
    function _S($text) {
        return __($text);
    }
}
/* `_N` es la forma de singular/plural: sin traducción, elige la que toca
   según el número, que es lo que comprueban las pruebas de los mensajes. */
if (!function_exists('_N')) {
    function _N($singular, $plural = null, $count = 1) {
        if ($plural === null)
            return $singular;

        return (is_numeric($count) && (int) $count === 1) ? $singular : $plural;
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
/* `Signal` y `Misc` los usa el ORM: `save()`/`delete()` emiten señales y
   `db_connect()` siembra el generador aleatorio con `Misc`. Sin ellas, la
   primera prueba que toca la base falla con «Class not found» en vez de
   decir qué pasa con la base. */
require_once INCLUDE_DIR . 'class.signal.php';
require_once INCLUDE_DIR . 'class.misc.php';
require_once INCLUDE_DIR . 'class.format.php';
require_once INCLUDE_DIR . 'class.validator.php';
/* Piezas puras sacadas de las clases grandes (frente 2). Este archivo no
   depende de la aplicación: por eso se puede cargar aquí. */
require_once INCLUDE_DIR . 'class.ticket.post.php';
require_once INCLUDE_DIR . 'class.ticket.create.php';
require_once INCLUDE_DIR . 'class.massaction.php';
require_once INCLUDE_DIR . 'class.mailer.message.php';
require_once INCLUDE_DIR . 'class.http.php';
require_once INCLUDE_DIR . 'class.thread.email.php';
require_once INCLUDE_DIR . 'class.thread.entry.php';

/* Clase base: PHPUnit si se ha instalado con Composer; si no, la de lib/. */
if (class_exists('PHPUnit\Framework\TestCase'))
    class_alias('PHPUnit\Framework\TestCase', 'UnitTestCase');
else
    require_once __DIR__ . '/lib/UnitTestCase.php';
