<?php
/*********************************************************************
    class.model.rules.php

    Reglas de modelo que no tocan la base de datos (frente 2 de
    docs/CODE_QUALITY.md). Estaban copiadas en `Dept::update()` y
    `Topic::update()` —el mismo `switch` de estado, letra por letra— y en el
    caso de las asignaciones de los temas, en un bloque de tres ramas con
    expresiones regulares que nadie probaba.

    Quien llama pasa lo que ha leído de la base de datos; aquí sólo se decide.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

class ModelRules {

    /**
     * Banderas que corresponden al estado de un modelo con estados
     * `active` / `disabled` / `archived` (departamentos y temas de ayuda).
     *
     * @param string $status
     * @return array|null  `['active' => bool, 'archived' => bool]`, o null
     *                     si el estado no es ninguno de los tres
     */
    static function statusFlags($status) {
        switch ($status) {
        case 'active':
            return array('active' => true, 'archived' => false);
        case 'disabled':
            return array('active' => false, 'archived' => false);
        case 'archived':
            return array('active' => false, 'archived' => true);
        }
        return null;
    }

    /**
     * ¿Este guardado dejaría vacía la lista de los que tienen la marca?
     *
     * Es la regla de «no puedes quitar el último administrador», «tiene que
     * quedar al menos un tema público» y «al menos un tema activo»: si el
     * único elemento marcado es este y el guardado le quita la marca, se
     * rechaza.
     *
     * @param array $selected  los marcados, con su id como clave
     * @param int   $id        id del modelo que se guarda
     * @param bool  $keepsMark si el guardado conserva la marca
     * @return bool
     */
    static function wouldEmpty($selected, $id, $keepsMark) {
        if ($keepsMark)
            return false;

        return count($selected) == 1 && array_key_exists($id, $selected);
    }

    /**
     * Campos opcionales que apuntan a otro modelo: si vienen, tienen que
     * existir.
     *
     * `Dept::update()` repetía cinco veces la misma forma —`if ($vars['x'] &&
     * !Model::lookup($vars['x'])) $errors['x'] = __('…')`— para el SLA, el
     * gerente, la dirección de correo, la plantilla y la dirección de la
     * respuesta automática. El buscador se inyecta, así que la regla se puede
     * probar sin base de datos.
     *
     * @param array $vars   valores del formulario
     * @param array $checks campo => `array(mensaje, buscador)`
     * @return array        campo => mensaje, sólo de los que no existen
     */
    static function unknownReferences(array $vars, array $checks) {
        $errors = array();

        foreach ($checks as $field => $check) {
            list($message, $lookup) = $check;
            $value = isset($vars[$field]) ? $vars[$field] : null;
            if ($value && !call_user_func($lookup, $value))
                $errors[$field] = $message;
        }

        return $errors;
    }

    /**
     * Las dos banderas de «a quién se le puede asignar» un departamento.
     *
     * El formulario manda `all`, `members` o `primary`; el `switch` estaba
     * escrito a mano en `Dept::update()`.
     *
     * @param string $assignment
     * @return array|null  `['members_only' => bool, 'primary_only' => bool]`,
     *                     o null si el valor no es ninguno de los tres (en ese
     *                     caso las banderas se quedan como están)
     */
    static function assignmentFlags($assignment) {
        switch ($assignment) {
        case 'all':
            return array('members_only' => false, 'primary_only' => false);
        case 'members':
            return array('members_only' => true, 'primary_only' => false);
        case 'primary':
            return array('members_only' => false, 'primary_only' => true);
        }
        return null;
    }

    /**
     * Qué avisos de «editado» hay que mandar al guardar.
     *
     * Era el bucle de `Dept::update()` que recorre las variables del
     * formulario y manda una señal por cada campo que cambia. El estado va
     * aparte porque su señal lleva el estado nuevo y no el nombre del campo.
     *
     * @param array    $vars     valores del formulario
     * @param callable $current  campo => `array(existe, valor guardado)`
     * @param string   $status   estado actual traducido ('' si es nuevo)
     * @param array    $flags    campo => bool: cambió una bandera que no es
     *                           una columna de la tabla
     * @return array             lista de `$type` para `Signal::send()`
     */
    static function editedSignals(array $vars, $current, $status,
            array $flags = array()) {
        $signals = array();

        foreach ($vars as $key => $value) {
            if ($key == 'status' && $status
                    && strtolower($status) != $value) {
                $signals[] = array('type' => 'edited',
                    'status' => ucfirst($value));
                continue;
            }

            list($exists, $stored) = call_user_func($current, $key);
            if (($exists && $stored != $value && $key != 'members')
                    || (!empty($flags[$key])))
                $signals[] = array('type' => 'edited', 'key' => $key);
        }

        return $signals;
    }

    /**
     * De las banderas de una organización, cuál cambió (la última que cambió).
     *
     * `Organization::update()` calculaba tres `autoFlagChanged()` en cadena y
     * se quedaba con la última `$key` asignada. Se conserva esa preferencia
     * —la última, no la primera— porque es lo que ya se avisa hoy.
     *
     * @param array $audits bandera => `array(estado actual, valor recibido)`
     * @return string|null  el nombre de la que cambió, o null si ninguna
     */
    static function lastFlagAudit(array $audits) {
        $changed = null;

        foreach ($audits as $key => $audit) {
            list($current, $incoming) = $audit;
            if (($current && !$incoming) || (!$current && $incoming))
                $changed = $key;
        }

        return $changed;
    }

    /**
     * ¿Cambió la lista de identificadores marcados?
     *
     * Los contactos principales de una organización llegaban unos como
     * objetos (`getId()`) y otros como texto del formulario; aquí se comparan
     * los dos ya como texto.
     *
     * @param array $incoming ids que llegan del formulario
     * @param array $current  ids guardados
     * @return bool
     */
    static function idListChanged($incoming, array $current) {
        // `array_values()` porque las claves no tienen por qué coincidir: los
        // guardados llegan de una lista con sus propias claves.
        $incoming = array_values(array_map('strval', (array) $incoming));
        $current = array_values(array_map('strval', $current));
        return $incoming != $current;
    }

    /**
     * Columnas que ya tenían valor y el formulario cambia.
     *
     * @param array    $vars    valores del formulario
     * @param callable $current campo => valor guardado
     * @param array    $ignore  campos que no se vigilan (`id`)
     * @return array            nombres de campo
     */
    static function editedColumns(array $vars, $current, array $ignore = array()) {
        $changed = array();

        foreach ($vars as $key => $value) {
            if (in_array($key, $ignore))
                continue;
            $stored = call_user_func($current, $key);
            if ($stored && $value != $stored)
                $changed[] = $key;
        }

        return $changed;
    }

    /**
     * Los valores del formulario, ya con el tipo de cada columna.
     *
     * `Dept::update()` copiaba quince columnas a mano, cada una con su
     * comprobación (`isset(...) ? (int) … : 0`, `$x ?: null`, un valor por
     * omisión para las dos de respuesta automática). Aquí es una tabla.
     *
     * Tipos: `int` (número, 0 si no viene), `nullable` (vacío es `null`) y
     * `raw` (tal cual). Los valores por omisión se aplican sólo cuando el
     * formulario **no** manda el campo.
     *
     * @param array $vars     valores del formulario
     * @param array $fields   campo => tipo
     * @param array $defaults campo => valor por omisión
     * @return array          campo => valor listo para asignar
     */
    static function fieldValues(array $vars, array $fields,
            array $defaults = array()) {
        $values = array();

        foreach ($fields as $field => $type) {
            if (!isset($vars[$field]) && array_key_exists($field, $defaults)) {
                $values[$field] = $defaults[$field];
                continue;
            }

            $value = isset($vars[$field]) ? $vars[$field] : null;
            switch ($type) {
            case 'int':
                $value = (int) $value;
                break;
            case 'nullable':
                $value = $value ?: null;
                break;
            }
            $values[$field] = $value;
        }

        return $values;
    }

    /**
     * Destino de la asignación automática de un tema de ayuda.
     *
     * El valor del formulario viene como `s123` (agente) o `t45` (equipo).
     * Cualquier otra cosa —o nada— deja el tema sin asignar.
     *
     * @param string $assign
     * @return array  `['staff_id' => int, 'team_id' => int]`
     */
    static function assignTarget($assign) {
        $target = array('staff_id' => 0, 'team_id' => 0);
        if (!$assign)
            return $target;

        $id = (int) preg_replace('/[^0-9]/', '', $assign);
        switch ($assign[0]) {
        case 's':
            $target['staff_id'] = $id;
            break;
        case 't':
            $target['team_id'] = $id;
            break;
        }

        return $target;
    }
}
