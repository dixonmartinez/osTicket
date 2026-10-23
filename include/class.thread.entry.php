<?php
/**
 * include/class.thread.entry.php
 *
 * Piezas de ThreadEntry::create() que no necesitan base de datos (frente 2 de
 * docs/CODE_QUALITY.md). create() guarda la entrada y mueve adjuntos, pero
 * antes de eso decide cuatro cosas que se pueden probar por separado:
 *
 *   - con qué título y con qué firma se guarda,
 *   - si la entrada es «respuesta a todos» o «respuesta al usuario»,
 *   - si es una nota de sistema y si el HTML ya viene equilibrado,
 *   - qué adjuntos van incrustados en el cuerpo y cuáles se descartan.
 *
 * Las banderas repiten el valor de ThreadEntry::FLAG_* para poder cargar este
 * archivo sin el ORM; hay una prueba que comprueba que los números coinciden.
 */
class ThreadEntryInfo {

    const FLAG_COLLABORATOR = 0x0020;
    const FLAG_BALANCED     = 0x0040;   // El HTML no hay que equilibrarlo
    const FLAG_SYSTEM       = 0x0080;   // Nota del sistema
    const FLAG_REPLY_ALL    = 0x0100;   // Respuesta del agente, a todos
    const FLAG_REPLY_USER   = 0x0200;   // Respuesta del agente, al usuario

    /** Título limpio: sin emoticonos y sin HTML. */
    static function title($title) {
        return Format::strip_emoticons(Format::sanitize($title, true));
    }

    /**
     * Firma de la entrada. Puede llegar como objeto (Staff, User): se guarda
     * su versión en texto.
     */
    static function poster($poster) {
        if ($poster && is_object($poster))
            $poster = (string) $poster;

        return Format::sanitize($poster);
    }

    /**
     * Bandera según a cuántos destinatarios va la respuesta:
     *
     *   más de uno -> respuesta a todos
     *   uno        -> respuesta al usuario
     *   ninguno    -> ninguna (no es una respuesta del agente)
     */
    static function replyFlags($recipients) {
        if (!$recipients)
            return 0;

        $count = 0;
        foreach ($recipients as $value)
            $count += is_array($value) ? count($value) : 1;

        return $count > 1 ? self::FLAG_REPLY_ALL : self::FLAG_REPLY_USER;
    }

    /**
     * Banderas que dependen del contenido y de quién firma: el HTML que
     * genera la aplicación ya viene equilibrado, y una entrada sin agente ni
     * usuario es una nota del sistema.
     */
    static function bodyFlags($format, $staffId, $userId) {
        $flags = 0;

        if ($format == 'html')
            $flags |= self::FLAG_BALANCED;

        if (!$staffId && !$userId)
            $flags |= self::FLAG_SYSTEM;

        return $flags;
    }

    /**
     * Quita de la lista los adjuntos que el analizador de correo ya había
     * incrustado en el cuerpo y que se han descartado al limpiarlo.
     *
     * @param array $attachments  lista de adjuntos (con 'cid' los incrustados)
     * @param array $cids         content-ids descartados
     */
    static function dropStrippedImages($attachments, $cids) {
        if (!$attachments || !$cids)
            return $attachments;

        $stripped = array();
        foreach ($cids as $cid)
            $stripped[(string) $cid] = true;

        foreach ($attachments as $i => $a) {
            if (isset($a['cid']) && $a['cid']
                    && isset($stripped[(string) $a['cid']]))
                unset($attachments[$i]);
        }

        return $attachments;
    }

    /**
     * Marca los adjuntos que el cuerpo referencia como imagen incrustada
     * (`cid:<content-id>`): se guardan como tales y no como archivo aparte.
     */
    static function markInline($files, $body) {
        if (!is_array($files) || !$body)
            return $files;

        foreach ($files as $i => $a) {
            if (isset($a['cid']) && $a['cid']
                    && strpos((string) $body, 'cid:'.$a['cid']) !== false)
                $files[$i]['inline'] = true;
        }

        return $files;
    }
}
