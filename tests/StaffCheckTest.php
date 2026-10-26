<?php
/**
 * tests/StaffCheckTest.php
 *
 * Pruebas de las reglas del alta y la edición de un agente que no tocan la
 * base de datos (frente 2 de docs/CODE_QUALITY.md).
 *
 * La que más falta hacía es la del último administrador: comparaba con una
 * variable que sólo existe cuando el nombre de usuario o el correo han
 * cambiado en el mismo guardado, así que el aviso no se veía casi nunca.
 */

require_once INCLUDE_DIR . 'class.staff.check.php';

class StaffCheckTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* Campos obligatorios                                                 */
    /* ------------------------------------------------------------------ */

    function testUnAgenteSinNombreNiApellidoNoSeGuarda() {
        $errors = StaffCheck::missing(array('dept_id' => 1, 'role_id' => 2));

        $this->assertSame('First name required', $errors['firstname']);
        $this->assertSame('Last name required', $errors['lastname']);
        $this->assertFalse(isset($errors['dept_id']));
        $this->assertFalse(isset($errors['role_id']));
    }

    function testUnAgenteSinDepartamentoNiRolNoSeGuarda() {
        $errors = StaffCheck::missing(array(
            'firstname' => 'Ana', 'lastname' => 'Pérez'));

        $this->assertSame(array('dept_id', 'role_id'), array_keys($errors));
    }

    /** Un agente completo no genera ningún error. */
    function testUnAgenteCompletoNoDaErrores() {
        $this->assertSame(array(), StaffCheck::missing(array(
            'firstname' => 'Ana', 'lastname' => 'Pérez',
            'dept_id' => 3, 'role_id' => 1)));
    }

    /* ------------------------------------------------------------------ */
    /* El último administrador                                             */
    /* ------------------------------------------------------------------ */

    /** Quitarse el permiso siendo el único administrador activo: no. */
    function testNoSePuedeDejarLaInstalacionSinAdministradores() {
        $this->assertSame(
            'Cowardly refusing to remove or lock out the only active administrator',
            StaffCheck::lastAdminError(array(7), 7, 0, 0));
    }

    /** Y bloquearse, tampoco. */
    function testNoSePuedeBloquearElUnicoAdministrador() {
        $this->assertTrue(StaffCheck::lastAdminError(array(7), 7, '1', '1') !== null);
    }

    /** Si hay otro administrador activo, se puede. */
    function testConOtroAdministradorSePuedeQuitarElPermiso() {
        $this->assertSame(null, StaffCheck::lastAdminError(array(7, 9), 7, 0, 0));
    }

    /** Si el único administrador es otro, este agente puede dejar de serlo. */
    function testSiElAdministradorEsOtroNoHayProblema() {
        $this->assertSame(null, StaffCheck::lastAdminError(array(9), 7, 0, 0));
    }

    /**
     * El defecto que tenía: con el agente como único administrador, un
     * guardado que lo deja como administrador activo no se rechaza.
     */
    function testUnAdministradorActivoNoSeBloqueaASiMismoPorError() {
        $this->assertSame(null, StaffCheck::lastAdminError(array(7), 7, '1', 0));
    }

    /* ------------------------------------------------------------------ */
    /* Accesos y equipos                                                   */
    /* ------------------------------------------------------------------ */

    function testElAccesoADepartamentosLlevaRolYAvisos() {
        $access = StaffCheck::accessPairs(array(2, 5),
            array(2 => 3, 5 => 1), array(2 => 1));

        $this->assertSame(array(array(2, 3, 1), array(5, 1, null)), $access);
    }

    /** Sin departamentos marcados, la lista va vacía (no `null`). */
    function testSinDepartamentosMarcadosNoHayAccesos() {
        $this->assertSame(array(), StaffCheck::accessPairs(null, null, null));
        $this->assertSame(array(), StaffCheck::accessPairs(array(), array(), array()));
    }

    function testLaPertenenciaAEquiposLlevaSusAvisos() {
        $teams = StaffCheck::teamPairs(array(4, 6), array(6 => 1));

        $this->assertSame(array(array(4, null), array(6, 1)), $teams);
    }
}
