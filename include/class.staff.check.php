<?php
/*********************************************************************
    class.staff.check.php

    Reglas del alta y la edición de un agente que NO tocan la base de datos
    (frente 2 de docs/CODE_QUALITY.md). Estaban dentro de `Staff::update()`,
    que así llegaba a 137 líneas, y una de ellas —la del último
    administrador— ya escondía un defecto: comparaba con una variable que
    sólo existe cuando el nombre de usuario o el correo han cambiado.

    Aquí no se consulta nada: quien llama pasa lo que ha leído.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

class StaffCheck {

    /**
     * Qué le falta al agente para poder guardarse.
     *
     * @param array $vars  datos del formulario
     * @return array       errores por campo
     */
    static function missing($vars) {
        $errors = array();

        if (empty($vars['firstname']))
            $errors['firstname'] = __('First name required');
        if (empty($vars['lastname']))
            $errors['lastname'] = __('Last name required');
        if (empty($vars['dept_id']))
            $errors['dept_id'] = __('Department is required');
        if (empty($vars['role_id']))
            $errors['role_id'] = __('Role for primary department is required');

        return $errors;
    }

    /**
     * ¿Este guardado dejaría la instalación sin ningún administrador activo?
     *
     * La comprobación se hace cuando el agente deja de ser administrador o
     * se bloquea: si el único administrador activo es él, se rechaza. Para
     * cualquier otro guardado no hay nada que comprobar.
     *
     * @param array $adminIds  ids de los administradores activos
     * @param int   $staffId   id del agente que se guarda
     * @param mixed $isAdmin   '1' si el formulario lo marca como administrador
     * @param mixed $isLocked  '1' si el formulario lo marca como bloqueado
     * @return string|null     el mensaje de error, o null si no hay problema
     */
    static function lastAdminError($adminIds, $staffId, $isAdmin, $isLocked) {
        // Sigue siendo administrador activo: no se puede quedar sin él
        if ($isAdmin === '1' && $isLocked !== '1')
            return null;

        if (count($adminIds) == 1 && (int) $adminIds[0] == (int) $staffId)
            return __('Cowardly refusing to remove or lock out the only active administrator');

        return null;
    }

    /**
     * Acceso a departamentos en la forma que espera `updateAccess()`:
     * `[array(dept_id, role_id, avisos?)]`.
     */
    static function accessPairs($ids, $roles, $alerts) {
        $access = array();
        if (!is_array($ids))
            return $access;

        foreach ($ids as $deptId) {
            $access[] = array($deptId,
                isset($roles[$deptId]) ? $roles[$deptId] : null,
                isset($alerts[$deptId]) ? $alerts[$deptId] : null);
        }

        return $access;
    }

    /**
     * Pertenencia a equipos en la forma que espera `updateTeams()`:
     * `[array(team_id, avisos?)]`.
     */
    static function teamPairs($ids, $alerts) {
        $teams = array();
        if (!is_array($ids))
            return $teams;

        foreach ($ids as $teamId)
            $teams[] = array($teamId,
                isset($alerts[$teamId]) ? $alerts[$teamId] : null);

        return $teams;
    }
}
