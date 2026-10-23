<?php
/**
 * include/class.thread.email.php
 *
 * Piezas de Thread::postEmail() que no necesitan base de datos ni sesión
 * (frente 2 de docs/CODE_QUALITY.md). postEmail() es el punto por donde
 * entra todo el correo de la aplicación: decide de quién es el mensaje, con
 * qué tipo de entrada se guarda y a quién se le atribuye. Esa decisión se
 * puede probar sin base de datos, así que vive aquí.
 *
 * Lo que NO está aquí, a propósito: la búsqueda del colaborador, la detección
 * de correo propio y el guardado de la entrada. Eso sí necesita la base de
 * datos y se queda en Thread::postEmail().
 */
class ThreadEmailInfo {

    /* Igual que ThreadEntry::FLAG_COLLABORATOR. Se repite el valor para que
       este archivo se pueda cargar sin el ORM; hay una prueba que comprueba
       que los dos números coinciden. */
    const FLAG_COLLABORATOR = 0x0020;

    /**
     * ¿Se puede aceptar este correo en este hilo?
     *
     * Un ticket cerrado que no se puede reabrir no acepta correo del cliente
     * (sí de un agente, que lo reabre al responder).
     */
    static function accepts($object, $staffId) {
        if (!$object)
            return false;

        if ($object instanceof Ticket)
            return $staffId || !$object->isClosed() || $object->isReopenable();

        return true;
    }

    /**
     * Variables de la entrada a partir de lo que ha leído el analizador de
     * correo. Es una traducción de nombres, sin decisiones.
     */
    static function varsFromMail($mailinfo, $entry=null, $object=null) {
        $vars = array(
            'mid' =>                    $mailinfo['mid'] ?? null,
            'header' =>                 $mailinfo['header'] ?? null,
            'poster' =>                 $mailinfo['name'] ?? null,
            'origin' =>                 'Email',
            'source' =>                 'Email',
            'ip' =>                     '',
            'reply_to' =>               $entry,
            'recipients' =>             $mailinfo['recipients'] ?? null,
            'thread_entry_recipients' => $mailinfo['thread_entry_recipients'] ?? null,
            'to-email-id' =>            $mailinfo['to-email-id'] ?? null,
            'autorespond' =>            !isset($mailinfo['passive']),
        );

        if ($object instanceof Ticket)
            $vars['ticketId'] = $object->getId();
        if ($object instanceof Task)
            $vars['taskId'] = $object->getId();

        if (isset($mailinfo['attachments']))
            $vars['attachments'] = $mailinfo['attachments'];

        return $vars;
    }

    /**
     * Tipo de entrada que corresponde a la clase de remitente que ha
     * deducido el analizador de correo:
     *
     *   C  colaborador del hilo  -> mensaje, con la bandera de colaborador
     *   U  dueño del ticket      -> mensaje
     *   A  administrador         -> nota interna
     *   S  agente                -> nota interna
     *
     * Devuelve null cuando no lo sabe (cabeceras In-Reply-To ausentes o
     * rotas): entonces hay que mirar el ticket, y eso lo hace postEmail().
     *
     * @return array|null  array('thread-type' => 'M'|'N', 'poster' => 'user'|'staff', 'flags' => int)
     */
    static function entryTypeForUserClass($class) {
        switch ($class) {
        case 'C':
            return array('thread-type' => 'M', 'poster' => 'user',
                'flags' => self::FLAG_COLLABORATOR);
        case 'U':
            return array('thread-type' => 'M', 'poster' => 'user');
        case 'A':
        case 'S':
            return array('thread-type' => 'N', 'poster' => 'staff');
        }

        return null;
    }

    /**
     * Nombre con el que se firma la entrada: el que ya venía, si no el del
     * remitente, y si no su dirección. Nunca vacío cuando hay dirección.
     */
    static function poster($poster, $name, $email) {
        return $poster ?: ($name ?: $email);
    }

    /**
     * Aviso que se pone delante del cuerpo cuando el correo viene de alguien
     * que el sistema no reconoce.
     */
    static function receivedFrom($name, $email) {
        return sprintf(_S('Received From: %1$s <%2$s>') . "\n\n", $name, $email);
    }
}
