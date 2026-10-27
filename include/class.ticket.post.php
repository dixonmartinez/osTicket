<?php
/*********************************************************************
    class.ticket.post.php

    Piezas puras del alta de un mensaje en un ticket (`Ticket::postMessage`).
    Se sacaron de ahí por dos motivos:

      1. `postMessage` tenía 190 líneas y mezclaba normalización de la
         entrada, búsqueda de usuarios y avisos. Estas dos partes no tocan la
         base de datos, así que se pueden probar solas (tests/TicketPostTest).
      2. Son las que más fácil se rompen al tocar el correo entrante: el
         origen del mensaje y quién cuenta como destinatario.

    Este archivo no depende de nada: se puede cargar sin la aplicación.

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

/**
 * Normaliza y filtra lo que `Ticket::postMessage` recibe de fuera.
 *
 * Todo son funciones estáticas sin estado: reciben datos, devuelven datos.
 */
class TicketPost {

    /**
     * Prepara el arreglo del mensaje antes de guardarlo.
     *
     *  - Si llega un origen explícito, manda sobre el que traiga el arreglo.
     *  - `ip` es el nombre corto que usan algunos llamadores; el campo real
     *    es `ip_address`.
     *  - Si no hay IP en el arreglo, se usa la del cliente de la petición.
     *
     * @param array       $vars       datos del mensaje (no se modifican)
     * @param string      $origin     origen explícito ('' si no lo hay)
     * @param string|null $remoteAddr $_SERVER['REMOTE_ADDR'] o null
     *
     * @return array el arreglo listo para guardar
     */
    static function normalizeVars(array $vars, $origin='', $remoteAddr=null) {
        if ($origin)
            $vars['origin'] = $origin;

        if (isset($vars['ip']))
            $vars['ip_address'] = $vars['ip'];
        elseif (empty($vars['ip_address']) && $remoteAddr)
            $vars['ip_address'] = $remoteAddr;

        return $vars;
    }

    /**
     * Quita de la lista de destinatarios a quien escribe el mensaje.
     *
     * En el portal, la respuesta se envía a «todos» los participantes y luego
     * se descarta al autor: si no, se mandaría copia a sí mismo. Un
     * destinatario sin contacto no se puede identificar, así que se deja.
     *
     * @param array $recipients destinatarios (objetos con getContact())
     * @param mixed $userId     id del autor del mensaje
     *
     * @return array los destinatarios que quedan, con sus claves originales
     */
    static function withoutPoster($recipients, $userId) {
        if (!$recipients || !is_array($recipients) && !$recipients instanceof Traversable)
            return $recipients;

        $out = array();

        foreach ($recipients as $key => $recipient) {
            $contact = $recipient->getContact();

            if (!$contact) {
                $out[$key] = $recipient;
                continue;
            }

            // El contacto puede ser de un usuario (getUserId) o anónimo, en
            // cuyo caso se compara por su propio id.
            $id = $contact->getUserId() ?: $contact->getId();

            if ($id == $userId)
                continue;

            $out[$key] = $recipient;
        }

        return $out;
    }

    /**
     * ¿El mensaje entrante es un rebote o una respuesta automática?
     *
     * Esos mensajes no generan acuse ni reabren el ticket. `mailflags` lo
     * rellena el lector de correo; sin él se asume un mensaje normal.
     *
     * @param array $vars datos del mensaje
     *
     * @return bool true si hay que responder y permitir la reapertura
     */
    static function shouldAutoRespond(array $vars) {
        if (!isset($vars['mailflags']))
            return true;

        $flags = $vars['mailflags'];

        return empty($flags['bounce']) && empty($flags['auto-reply']);
    }

    /**
     * A quién le llega el aviso de mensaje nuevo, en el orden del original:
     * el último que respondió, el asignado (o su equipo), el jefe del
     * departamento y el gestor de la cuenta (o los miembros de su equipo).
     *
     * Cada bloque tiene su propia opción en la configuración; si está
     * apagada, ese destinatario no entra. El aviso se manda después, y ahí se
     * descartan los repetidos y los agentes sin correo o no disponibles
     * (`alertableRecipients()`).
     *
     * @param object $cfg    configuración del sistema
     * @param object $ticket el ticket (o su padre, si es un hijo combinado)
     * @param object $dept   departamento del ticket
     * @param object $owner  de quién se lee la organización para el gestor de
     *                       la cuenta; por omisión, el mismo `$ticket`. En un
     *                       hijo combinado el mensaje va al padre pero la
     *                       organización se leía del hijo, y así se sigue
     *                       haciendo
     *
     * @return array agentes y equipos por los que preguntar
     */
    static function alertRecipients($cfg, $ticket, $dept, $owner = null) {
        if (!$owner)
            $owner = $ticket;

        $recipients = array();

        // Last respondent.
        if ($cfg->alertLastRespondentONNewMessage()
                && ($lr = $ticket->getLastRespondent()))
            $recipients[] = $lr;

        // Assigned staff if any… could be the last respondent
        if ($cfg->alertAssignedONNewMessage() && $ticket->isAssigned()) {
            if ($staff = $ticket->getStaff())
                $recipients[] = $staff;
            elseif ($team = $ticket->getTeam())
                $recipients = array_merge($recipients,
                    $team->getMembersForAlerts());
        }

        // Dept manager
        if ($cfg->alertDeptManagerONNewMessage()
                && $dept
                && ($manager = $dept->getManager()))
            $recipients[] = $manager;

        // Account manager
        if ($cfg->alertAcctManagerONNewMessage()
                && ($org = $owner->getOwner()->getOrganization())
                && ($acct_manager = $org->getAccountManager())) {
            if ($acct_manager instanceof Team)
                $recipients = array_merge($recipients,
                    $acct_manager->getMembersForAlerts());
            else
                $recipients[] = $acct_manager;
        }

        return $recipients;
    }

    /**
     * Los destinatarios del aviso que de verdad pueden recibirlo: fuera los
     * vacíos, los que no tienen correo, los agentes no disponibles y los
     * repetidos (se compara la dirección, que es lo que llega al correo).
     *
     * @param array $recipients agentes y equipos de `alertRecipients()`
     *
     * @return array agentes únicos, en el mismo orden
     */
    static function alertableRecipients(array $recipients) {
        $list = $sent = array();
        foreach ($recipients as $staff) {
            if (!$staff || !$staff->getEmail() || !$staff->isAvailable()
                    || in_array($staff->getEmail(), $sent))
                continue;

            $list[] = $staff;
            $sent[] = $staff->getEmail();
        }

        return $list;
    }
}
