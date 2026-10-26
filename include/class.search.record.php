<?php
/*********************************************************************
    class.search.record.php

    Cómo se arma un registro del índice de búsqueda (frente 2 de
    docs/CODE_QUALITY.md).

    `Search::IndexOldStuff()` recorría seis tipos de contenido y, para cada
    uno, armaba a mano la tupla `[tipo, id, título, contenido]` con sus
    diferencias: el de las entradas se salta las que no tienen ni título ni
    cuerpo, el del ticket no recorta, el de usuarios y organizaciones sí, y el
    de la base de conocimiento mete las palabras clave delante de la pregunta.
    Aquí están esas diferencias, en funciones con nombre y con pruebas; el
    método se queda con las consultas y el guardado.

    La normalización del texto (`Format::searchable()`) se inyecta: así esta
    pieza no depende del formato ni de la configuración global y se puede
    probar sola.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

class SearchRecord {

    /** Tipos de objeto del índice (`object_type` de `ost_search`). */
    const THREAD_ENTRY = 'H';
    const TICKET = 'T';
    const USER = 'U';
    const ORGANIZATION = 'O';
    const FAQ = 'K';

    /**
     * Los trozos de contenido que valen, como texto.
     *
     * @param array $values
     * @return array
     */
    static function parts(array $values) {
        $parts = array();
        foreach ($values as $value) {
            if (is_scalar($value) && (string) $value !== '')
                $parts[] = (string) $value;
        }
        return $parts;
    }

    /**
     * Contenido de un registro: los trozos no vacíos, unidos por saltos de
     * línea.
     *
     * @param array $values
     * @param bool  $trim   recortar el resultado (usuarios y organizaciones)
     * @return string
     */
    static function content(array $values, $trim = false) {
        $text = implode("\n", self::parts($values));
        return $trim ? trim($text) : $text;
    }

    /**
     * La tupla que se guarda en el índice.
     *
     * @param string   $type
     * @param int      $id
     * @param string   $title
     * @param array    $values  trozos de contenido
     * @param callable $searchable  normalizador del texto
     * @param bool     $trim    recortar el contenido
     * @return array
     */
    static function record($type, $id, $title, array $values,
            $searchable = null, $trim = false) {
        if ($searchable)
            $title = call_user_func($searchable, $title);

        return array($type, $id, $title, self::content($values, $trim));
    }

    /**
     * Una entrada del hilo, o null si no hay nada que indexar.
     *
     * El título y el cuerpo ya llegan convertidos a texto buscable
     * (`ThreadEntryBody::getSearchable()` y `Format::searchable()`), así que
     * aquí no se normaliza otra vez. Es el único tipo que se salta los vacíos:
     * una entrada sin título ni cuerpo no aporta nada al índice.
     *
     * @param int    $id
     * @param string $title
     * @param string $body
     * @return array|null
     */
    static function entry($id, $title, $body) {
        if (!$title && !$body)
            return null;

        return array(self::THREAD_ENTRY, $id, (string) $title, (string) $body);
    }

    /**
     * Un ticket: el número y el asunto buscan, los campos del formulario son
     * el contenido. No se recorta, como antes.
     *
     * @param int      $id
     * @param string   $number
     * @param string   $subject
     * @param array    $values   campos buscables (sin el asunto, que va en el título)
     * @param callable $searchable
     * @return array
     */
    static function ticket($id, $number, $subject, array $values,
            $searchable = null) {
        return self::record(self::TICKET, $id, $number . ' ' . $subject,
            $values, $searchable);
    }

    /**
     * Un usuario: su nombre en el título; los correos y los campos del
     * formulario, en el contenido. El contenido se recorta.
     *
     * @param int      $id
     * @param string   $fullName
     * @param array    $addresses
     * @param array    $values
     * @param callable $searchable
     * @return array
     */
    static function user($id, $fullName, array $addresses, array $values,
            $searchable = null) {
        return self::record(self::USER, $id, $fullName,
            array_merge($addresses, $values), $searchable, true);
    }

    /**
     * Una organización: su nombre en el título y los campos del formulario en
     * el contenido, recortado.
     *
     * @param int      $id
     * @param string   $name
     * @param array    $values
     * @param callable $searchable
     * @return array
     */
    static function organization($id, $name, array $values,
            $searchable = null) {
        return self::record(self::ORGANIZATION, $id, $name, $values,
            $searchable, true);
    }

    /**
     * Un artículo de la base de conocimiento: las palabras clave van delante
     * de la pregunta, y la respuesta es el contenido.
     *
     * @param int      $id
     * @param string   $question
     * @param string   $keywords
     * @param string   $answer
     * @param callable $searchable
     * @return array
     */
    static function faq($id, $question, $keywords, $answer,
            $searchable = null) {
        $title = $keywords ? $keywords . ' ' . $question : $question;

        return self::record(self::FAQ, $id, $title, array($answer), $searchable);
    }
}
