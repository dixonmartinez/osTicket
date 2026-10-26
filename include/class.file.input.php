<?php
/*********************************************************************
    class.file.input.php

    Lo que se decide antes de guardar un archivo (frente 2 de
    docs/CODE_QUALITY.md).

    `File::create()` recibía un arreglo con los datos del archivo —del
    formulario, de la API o de un correo— y entre consulta y consulta tomaba
    cinco decisiones que no tienen nada que ver con la base de datos: qué
    hacer si los datos vienen en base64, de dónde sacarlos si llegan por
    llamada diferida, qué tipo poner cuando no se sabe, cómo calcular el
    tamaño cuando el cliente no lo manda y cuándo merece la pena buscar un
    archivo idéntico ya guardado.

    Aquí están esas cinco, sin E/S. Quien llama se encarga de la base.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

class FileInput {

    /** Lo que se pone cuando no hay forma de saber el tipo. */
    const DEFAULT_TYPE = 'application/octet-stream';

    /**
     * Los datos, tal como los manda el cliente.
     *
     * El único transporte que se admite codificado es base64 (`encoding`).
     *
     * @param array $file
     * @return array  el mismo arreglo, con `data` ya decodificado
     */
    static function decode(array $file) {
        if (isset($file['encoding']) && $file['encoding'] === 'base64'
                && isset($file['data']))
            $file['data'] = base64_decode($file['data']);

        return $file;
    }

    /**
     * Resuelve los datos cuando llegan por llamada diferida.
     *
     * `data_cbk` permite no leer el archivo hasta que hace falta (adjuntos
     * grandes, contenido que hay que ir a buscar). Se llama **una vez**: el
     * resultado se guarda en `data` y la llamada se quita, para que nadie la
     * ejecute dos veces sin querer.
     *
     * @param array $file
     * @return array
     */
    static function resolve(array $file) {
        if (!isset($file['data']) && isset($file['data_cbk'])
                && is_callable($file['data_cbk'])) {
            $file['data'] = call_user_func($file['data_cbk']);
            unset($file['data_cbk']);
        }

        return $file;
    }

    /**
     * El tipo del archivo: el que diga el cliente o, si no, el que detecte el
     * sistema; y si tampoco, el genérico.
     *
     * @param string        $type      tipo declarado ('' o null si no hay)
     * @param callable|null $detect    devuelve el tipo o null (finfo)
     * @return string
     */
    static function mimeType($type, $detect = null) {
        if (!$type && $detect && ($detected = call_user_func($detect)))
            $type = $detected;

        return $type ?: self::DEFAULT_TYPE;
    }

    /**
     * Tamaño del archivo cuando el cliente no lo manda.
     *
     * El orden importa: primero lo que diga el backend que lo guardó, después
     * `mb_strlen()` en modo `8bit` —que respeta `mbstring.func_overload`— y
     * sólo al final `strlen()`, que cuenta bytes.
     *
     * @param string|null $data         contenido (si se tiene en memoria)
     * @param int|null    $backendSize  lo que mide el backend
     * @param bool        $hasMbstring
     * @return int|null                 null si no hay forma de saberlo
     */
    static function size($data, $backendSize = null, $hasMbstring = true) {
        if ($backendSize)
            return (int) $backendSize;

        if (!is_string($data))
            return null;

        return $hasMbstring ? mb_strlen($data, '8bit') : strlen($data);
    }

    /**
     * ¿Hay que buscar un archivo idéntico ya guardado?
     *
     * Sólo si se pidió deduplicar y el tamaño es conocido: la búsqueda va por
     * firma y tamaño, así que sin tamaño no hay nada que comparar. Antes la
     * consulta se hacía igual y el resultado se tiraba cuando no se pedía
     * deduplicar.
     *
     * @param array $file
     * @param bool  $deduplicate
     * @return bool
     */
    static function shouldLookup(array $file, $deduplicate = true) {
        return (bool) $deduplicate
            && isset($file['size']) && $file['size'] > 0;
    }

    /**
     * Los atributos con los que se construye el modelo del archivo.
     *
     * @param array  $file  con `type`, `name`, `key`, `signature`, `size`
     * @param string $ft    tipo de archivo de osTicket (`T`, `C`…)
     * @return array
     */
    static function attributes(array $file, $ft = 'T') {
        $attributes = array(
            // El tipo se guarda en minúsculas: `image/PNG` y `image/png` son
            // el mismo tipo, y así se pueden comparar.
            'type' => strtolower($file['type']),
            'name' => $file['name'],
            'key' => $file['key'],
            'ft' => $ft ?: 'T',
            'signature' => $file['signature'],
        );

        if (isset($file['size']))
            $attributes['size'] = $file['size'];

        return $attributes;
    }
}
