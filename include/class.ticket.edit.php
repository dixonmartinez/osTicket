<?php
/*********************************************************************
    class.ticket.edit.php

    Reglas de la edición de un ticket que no tocan la base de datos (frente 2
    de docs/CODE_QUALITY.md). Estaban dentro de `Ticket::update()`, que así
    llegaba a 133 líneas y mezclaba cuatro comprobaciones con el guardado, los
    formularios y el registro de cambios.

    Quien llama pasa lo que ha leído; aquí sólo se decide.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

class TicketEdit {

    /**
     * Campos que valida `Validator::process()` al editar un ticket.
     *
     * Es una tabla de datos con sus mensajes: estaban escritos a mano en el
     * método y se leen peor mezclados con el flujo.
     */
    static function fields() {
        return array(
            'topicId' => array('type' => 'int', 'required' => 1,
                'error' => __('Help topic selection is required')),
            'slaId' => array('type' => 'int', 'required' => 0,
                'error' => __('Select a valid SLA')),
            'duedate' => array('type' => 'date', 'required' => 0,
                'error' => __('Invalid date format - must be MM/DD/YY')),
            'user_id' => array('type' => 'int', 'required' => 0,
                'error' => __('Invalid user-id')),
        );
    }

    /**
     * Qué le pasa a la fecha límite que se quiere poner.
     *
     * Las tres reglas, en el orden en que se comprobaban: un ticket cerrado no
     * admite fecha límite, la fecha tiene que entenderse y tiene que ser
     * futura.
     *
     * @param mixed    $duedate   lo que llega del formulario
     * @param bool     $isClosed
     * @param callable $parse     texto de fecha -> marca de tiempo (o false)
     * @param int      $now       marca de tiempo actual, en la misma escala
     * @return string|null        el mensaje, o null si la fecha vale
     */
    static function dueDateError($duedate, $isClosed, $parse, $now) {
        if (!$duedate)
            return null;

        if ($isClosed)
            return __('Due date can NOT be set on a closed ticket');

        $timestamp = call_user_func($parse, $duedate);
        if ($timestamp === false)
            return __('Invalid due date');

        if ($timestamp <= $now)
            return __('Due date must be in the future');

        return null;
    }

    /**
     * ¿El origen del ticket es uno de los conocidos?
     *
     * @param mixed $source   valor del formulario
     * @param array $sources  orígenes válidos (clave => nombre)
     * @return string|null    el mensaje, o null si es válido o no se indicó
     */
    static function sourceError($source, array $sources) {
        if (!isset($source) || array_key_exists($source, $sources))
            return null;

        return sprintf(__('Invalid source given - %s'),
            Format::htmlchars($source));
    }

    /**
     * Los cambios que merecen quedar en el historial del ticket.
     *
     * @param array    $dirty   campo => valor anterior
     * @param callable $current campo => valor nuevo
     * @return array            campo => [anterior, nuevo]
     */
    static function trackedChanges(array $dirty, $current) {
        $tracked = array('topic_id', 'user_id', 'source', 'duedate', 'sla_id');
        $changes = array();

        foreach ($dirty as $field => $old) {
            if (in_array($field, $tracked))
                $changes[$field] = array($old, call_user_func($current, $field));
        }

        return $changes;
    }
}
