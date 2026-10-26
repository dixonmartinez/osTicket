<?php
/*********************************************************************
    include/class.db.failure.php

    Memoria del último error de la conexión a la base de datos.

    Por qué existe: `mysqli::$error` describe la **última** operación de la
    conexión, y registrar un fallo lo borra. `db_query()` llama a
    `$ost->logDBError()`, que escribe el error en `ost_syslog` (y puede mandar
    un aviso por correo); ese INSERT deja `mysqli::$error` en blanco. Quien
    luego lee el error para contarlo —el ORM, al lanzar «Unable to prepare
    query:»— se encuentra una cadena vacía y el mensaje de MariaDB se pierde:

        Unable to prepare query:  SELECT A1.* FROM `ost_staff` A1 WHERE …

    Con dos espacios donde debería estar la explicación. Guardar el error
    antes de registrar nada deja el motivo disponible para el mensaje.

    Va en su propio archivo, y no dentro de `mysqli.php`, para que las pruebas
    puedan cargarlo sin arrastrar la conexión (definir `db_query()` cambia el
    camino que toman otras pruebas del sembrador).

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

/**
 * Último error de la conexión, conservado al margen de mysqli.
 *
 * `mysqli::$error` describe la **última** operación, y registrar el fallo
 * (INSERT en `ost_syslog`, aviso por correo) lo borra. Sin esta copia, el
 * mensaje que se muestra es «Unable to prepare query:  SELECT …», con dos
 * espacios donde debería estar la explicación de MariaDB.
 */
function db_remember_failure($errno, $error, $query=null) {
    if (!$errno && !$error)
        return;

    $GLOBALS['__db_last_failure'] = array(
        'errno' => (int) $errno,
        'error' => (string) $error,
        'query' => $query,
    );
}

function db_last_error() {
    return isset($GLOBALS['__db_last_failure'])
        ? $GLOBALS['__db_last_failure']['error'] : '';
}

function db_last_errno() {
    return isset($GLOBALS['__db_last_failure'])
        ? $GLOBALS['__db_last_failure']['errno'] : 0;
}

/** El error de la última consulta fallida, como texto para un mensaje. */
function db_last_failure_text() {
    if (!isset($GLOBALS['__db_last_failure']))
        return '';

    $failure = $GLOBALS['__db_last_failure'];
    return sprintf('[%d] %s', $failure['errno'],
        $failure['error'] ?: 'MariaDB no dio explicación');
}
