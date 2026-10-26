<?php
/*********************************************************************
    class.mail.attachment.php

    Qué es un adjunto de un correo y cómo se llama (frente 2 de
    docs/CODE_QUALITY.md).

    `MailParse::getAttachments()` era un método de 127 líneas que recorría el
    árbol MIME y, para cada parte, decidía dos cosas antes de leer el cuerpo:
    si la parte es un adjunto y cómo se llama el archivo. La segunda eran ocho
    ramas seguidas —`filename`, `filename*` (RFC 5987), `name`, `name*`,
    `filename="…"`, imágenes sin nombre, `message/rfc822` y la forma
    fragmentada `filename*0=`— que nadie probaba y que sólo se ejercitaban
    cuando llegaba el correo raro del cliente raro.

    Aquí están esas dos decisiones, recibiendo la parte ya normalizada como
    arreglo. Las funciones de formato y el generador de nombres se inyectan,
    así que esto no depende de la configuración ni del azar.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

class MailAttachment {

    /**
     * ¿Esta parte del correo es un adjunto?
     *
     * Lo es si trae `Content-Disposition: attachment` o `inline`, o si su tipo
     * principal es `image` o `application` (hay clientes que no ponen la
     * cabecera).
     *
     * @param string $disposition  `attachment`, `inline`, otra cosa o null
     * @param string $primary      tipo principal (`text`, `image`, `application`…)
     * @return bool
     */
    static function isAttachment($disposition, $primary) {
        if ($disposition
                && (!strcasecmp($disposition, 'attachment')
                    || !strcasecmp($disposition, 'inline')))
            return true;

        return !strcasecmp($primary, 'image')
            || !strcasecmp($primary, 'application');
    }

    /**
     * El nombre del archivo, por el orden en que lo dicen las cabeceras.
     *
     * @param array $part   `d_parameters`, `ctype_parameters`, `headers`,
     *                      `primary`, `secondary` y, para `message/rfc822`,
     *                      `parts` (con el `subject` del mensaje adjunto)
     * @param array $tools  `mimedecode` (texto, charset), `rfc5987` (texto) y
     *                      `random` (n)
     * @param string $charset  juego de caracteres del correo
     * @return string|false    false si la parte no es un adjunto
     */
    static function filename(array $part, array $tools, $charset = null) {
        $part = self::part($part);
        $d = $part['d_parameters'];
        $ctype = $part['ctype_parameters'];
        $headers = $part['headers'];
        $primary = $part['primary'];
        $secondary = $part['secondary'];
        $disposition = isset($headers['content-disposition'])
            ? $headers['content-disposition'] : '';

        // `filename` de la cabecera de disposición
        if (isset($d['filename']))
            return call_user_func($tools['mimedecode'], $d['filename'], $charset);

        // RFC 6266 / RFC 5987
        if (isset($d['filename*']))
            return call_user_func($tools['rfc5987'], $d['filename*']);

        // Clientes que sólo ponen `name` en el Content-Type
        if (isset($ctype['name']))
            return call_user_func($tools['mimedecode'], $ctype['name'], $charset);

        if (isset($ctype['name*']))
            return call_user_func($tools['rfc5987'], $ctype['name*']);

        if ($disposition
                && preg_match('/filename="([^"]+)"/', $disposition, $matches))
            return call_user_func($tools['mimedecode'], $matches[1], $charset);

        // Imágenes sin nombre (Lotus Notes / Domino y compañía): se inventa
        // uno con su extensión, porque el adjunto se guarda igual.
        if (!empty($headers['content-id'])
                && 0 === strcasecmp($primary, 'image'))
            return 'image-' . call_user_func($tools['random'], 4) . '.'
                . strtolower($secondary);

        // `message/rfc822` sin nombre: el asunto del mensaje adjunto, o un
        // nombre genérico. Siempre con extensión `.eml`.
        if (strcasecmp($primary, 'message') === 0) {
            $inner = isset($part['parts'][0]) ? $part['parts'][0] : null;
            if ($inner && isset($inner['headers']['subject']))
                return call_user_func($tools['mimedecode'],
                    $inner['headers']['subject'], $charset) . '.eml';

            return 'email-message-' . call_user_func($tools['random'], 4) . '.eml';
        }

        // Forma fragmentada: `filename*0=`, `filename*1=`… Se concatenan en el
        // orden en que llegan las claves.
        if ($disposition
                && preg_match('/filename\*\d+=([^;]+);/', $disposition) === 1) {
            $filename = '';
            foreach ($d as $key => $value)
                if (strpos($key, 'filename*') !== false)
                    $filename .= call_user_func($tools['rfc5987'], $value);

            if ($filename)
                return $filename;
        }

        // Not an attachment?
        return false;
    }

    /**
     * Nombre y tipo de la parte, o `false` si no es un adjunto.
     *
     * Es la respuesta completa que necesita quien recibe el correo: el nombre
     * y el tipo ya decididos, sin que quien llama tenga que saber en qué
     * orden se miran las cabeceras.
     *
     * @param object|array $part  la parte MIME (objeto de PEAR o arreglo)
     * @param array $tools        igual que en `filename()`
     * @param string $charset
     * @return array|false        `array('name' => ..., 'type' => ...)`
     */
    static function describe($part, array $tools, $charset = null) {
        $part = self::part($part);

        if (!self::isAttachment($part['disposition'], $part['primary']))
            return false;

        $name = self::filename($part, $tools, $charset);
        if ($name === false)
            return false;

        return array(
            'name' => $name,
            'type' => self::type($part['primary'], $part['secondary']),
        );
    }

    /**
     * La parte MIME como arreglo, con todo lo que hace falta y nada más.
     *
     * Las propiedades de una parte no siempre están (PEAR deja sin definir las
     * que el correo no trae), así que aquí se normalizan una sola vez y quien
     * decide no vuelve a preguntar por `isset()`.
     *
     * @param object|array $part
     * @return array
     */
    static function part($part) {
        if (is_array($part))
            $data = $part;
        else
            $data = array(
                'd_parameters' => isset($part->d_parameters)
                    ? (array) $part->d_parameters : array(),
                'ctype_parameters' => isset($part->ctype_parameters)
                    ? (array) $part->ctype_parameters : array(),
                'headers' => isset($part->headers)
                    ? (array) $part->headers : array(),
                'disposition' => isset($part->disposition)
                    ? $part->disposition : null,
                'primary' => isset($part->ctype_primary)
                    ? $part->ctype_primary : '',
                'secondary' => isset($part->ctype_secondary)
                    ? $part->ctype_secondary : '',
                'parts' => isset($part->parts[0]) ? array(array(
                    'headers' => isset($part->parts[0]->headers)
                        ? (array) $part->parts[0]->headers : array(),
                )) : array(),
            );

        return $data + array(
            'd_parameters' => array(),
            'ctype_parameters' => array(),
            'headers' => array(),
            'disposition' => null,
            'primary' => '',
            'secondary' => '',
            'parts' => array(),
        );
    }

    /**
     * El tamaño en bytes del contenido, tal como se guarda.
     *
     * `mb_strlen(..., '8bit')` cuenta bytes y no caracteres; `strlen()` es el
     * mismo número, y es el que queda cuando no hay `mbstring`.
     *
     * @param string $data
     * @return int
     */
    static function size($data) {
        return extension_loaded('mbstring')
            ? mb_strlen($data, '8bit') : strlen($data);
    }

    /**
     * El tipo MIME tal como se guarda: en minúsculas y con la barra.
     *
     * @param string $primary
     * @param string $secondary
     * @return string
     */
    static function type($primary, $secondary) {
        return strtolower($primary . '/' . $secondary);
    }

    /**
     * El identificador de las imágenes incrustadas, sin los ángulos.
     *
     * @param string|null $contentId
     * @return string|false
     */
    static function contentId($contentId) {
        return $contentId ? rtrim(ltrim($contentId, '<'), '>') : false;
    }
}
