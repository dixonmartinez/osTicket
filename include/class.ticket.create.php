<?php
/**
 * include/class.ticket.create.php
 *
 * Piezas de Ticket::create() que no necesitan base de datos (frente 2 de
 * docs/CODE_QUALITY.md).
 *
 * create() es la función más larga de la aplicación (536 líneas) y casi todo
 * su cuerpo es flujo con base de datos: buscar el tema, el departamento, el
 * correo, crear el usuario, guardar el formulario, los colaboradores y el
 * mensaje. Eso no se puede sacar sin pruebas de integración.
 *
 * Lo que sí es puro, y es justo lo que más se rompe al tocar los orígenes
 * (web, staff, api, email), está aquí:
 *
 *   - qué campos exige cada origen y cómo se validan,
 *   - qué campos se validan según quién abre el ticket,
 *   - si la fecha límite es válida,
 *   - cómo se normaliza el origen para guardarlo.
 */
class TicketCreate {

    /**
     * Campos obligatorios de la petición según el origen.
     *
     * Reproduce el `switch` de create(), incluido el caso de 'staff' que
     * además exige `source` (cae al bloque de 'api').
     *
     * @return array|false  false si el origen no es válido
     */
    static function requiredFields($origin) {
        switch (strtolower($origin)) {
        case 'web':
            return array(
                'topicId' => array('type' => 'int', 'required' => 1,
                    'error' => __('Select a Help Topic')),
            );

        case 'staff':
            return array(
                'deptId' => array('type' => 'int', 'required' => 0,
                    'error' => __('Department selection is required')),
                'topicId' => array('type' => 'int', 'required' => 1,
                    'error' => __('Help topic selection is required')),
                'duedate' => array('type' => 'date', 'required' => 0,
                    'error' => __('Invalid date format - must be MM/DD/YY')),
                'source' => array('type' => 'string', 'required' => 1,
                    'error' => __('Indicate ticket source')),
            );

        case 'api':
            return array(
                'source' => array('type' => 'string', 'required' => 1,
                    'error' => __('Indicate ticket source')),
            );

        case 'email':
            return array(
                'emailId' => array('type' => 'int', 'required' => 1,
                    'error' => __('Unknown system email')),
            );
        }

        return false;
    }

    /**
     * Filtro de validación de campos según quién abre el ticket.
     *
     * Se devuelve con la misma forma que usaba create() —un closure que
     * recibe un nombre de formulario ('user', 'ticket', 'topic') y devuelve
     * el filtro de campo— para no cambiar las llamadas:
     *
     *   $field_filter = TicketCreate::fieldFilter($origin);
     *   $form->isValid($field_filter('ticket'));
     *
     * El nombre del formulario no se usa: los cuatro orígenes se comportan
     * igual para todos.
     */
    static function fieldFilter($origin) {
        $origin = strtolower($origin);

        return function ($type) use ($origin) {
            return function ($f) use ($origin) {
                switch ($origin) {
                case 'email':
                    // Por correo no se puede validar nada
                    return false;
                case 'staff':
                    // Los campos de contacto no son obligatorios si abre un
                    // agente
                    return $f->isVisibleToStaff();
                case 'web':
                    return $f->isVisibleToUsers();
                }

                return true;
            };
        };
    }

    /**
     * Error de la fecha límite, o null si es correcta.
     *
     * @param string   $duedate  fecha tal como llega de la petición
     * @param callable $toGmt    conversión de hora local a GMT
     *                           (`Misc::user2gmtime`)
     * @return string|null       mensaje para el usuario
     */
    static function dueDateError($duedate, callable $toGmt) {
        if (!$duedate)
            return null;

        if (strtotime($duedate) === false)
            return __('Invalid due date');

        if ($toGmt($duedate) <= $toGmt())
            return __('Due date must be in the future');

        return null;
    }

    /** Origen tal como se guarda: la primera letra en mayúscula. */
    static function source($source) {
        return ucfirst((string) $source);
    }
}
