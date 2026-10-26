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
