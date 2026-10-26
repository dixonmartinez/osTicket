<?php
/**
 * tests/ModelRulesTest.php
 *
 * Pruebas de las reglas de modelo sin base de datos (frente 2 de
 * docs/CODE_QUALITY.md): el estado de departamentos y temas, la regla de «no
 * puedes quitar el último» y el destino de la asignación automática de un
 * tema, que venía de tres ramas con expresiones regulares.
 */

require_once INCLUDE_DIR . 'class.model.rules.php';

class ModelRulesTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* Estado                                                              */
    /* ------------------------------------------------------------------ */

    function testLosTresEstadosTienenSusBanderas() {
        $this->assertSame(array('active' => true, 'archived' => false),
            ModelRules::statusFlags('active'));
        $this->assertSame(array('active' => false, 'archived' => false),
            ModelRules::statusFlags('disabled'));
        $this->assertSame(array('active' => false, 'archived' => true),
            ModelRules::statusFlags('archived'));
    }

    /** Un estado que no es ninguno de los tres no cambia nada. */
    function testUnEstadoDesconocidoNoDevuelveBanderas() {
        $this->assertSame(null, ModelRules::statusFlags('inventado'));
        $this->assertSame(null, ModelRules::statusFlags(''));
    }

    /* ------------------------------------------------------------------ */
    /* No dejar la lista vacía                                             */
    /* ------------------------------------------------------------------ */

    function testQuitarLaMarcaAlUltimoMarcadoSeRechaza() {
        $this->assertTrue(ModelRules::wouldEmpty(array(7 => 'Ana'), 7, false));
    }

    function testSiConservaLaMarcaNoHayProblema() {
        $this->assertFalse(ModelRules::wouldEmpty(array(7 => 'Ana'), 7, true));
    }

    function testSiQuedaOtroMarcadoNoHayProblema() {
        $this->assertFalse(ModelRules::wouldEmpty(
            array(7 => 'Ana', 9 => 'Luis'), 7, false));
    }

    /** Si el único marcado es otro, este guardado no vacía nada. */
    function testSiElUnicoMarcadoEsOtroNoHayProblema() {
        $this->assertFalse(ModelRules::wouldEmpty(array(9 => 'Luis'), 7, false));
    }

    function testUnaListaVaciaNoSePuedeVaciar() {
        $this->assertFalse(ModelRules::wouldEmpty(array(), 7, false));
    }

    /* ------------------------------------------------------------------ */
    /* Asignación automática de un tema                                    */
    /* ------------------------------------------------------------------ */

    function testLaAsignacionAEmpiezaPorS() {
        $this->assertSame(array('staff_id' => 123, 'team_id' => 0),
            ModelRules::assignTarget('s123'));
    }

    function testLaAsignacionAEquipoEmpiezaPorT() {
        $this->assertSame(array('staff_id' => 0, 'team_id' => 45),
            ModelRules::assignTarget('t45'));
    }

    /** Sin asignación, el tema queda sin agente y sin equipo. */
    function testSinAsignacionNoHayDestino() {
        $this->assertSame(array('staff_id' => 0, 'team_id' => 0),
            ModelRules::assignTarget(''));
        $this->assertSame(array('staff_id' => 0, 'team_id' => 0),
            ModelRules::assignTarget(null));
    }

    /** Una letra desconocida no asigna a nadie, pero tampoco revienta. */
    function testUnaAsignacionDesconocidaNoAsignaANadie() {
        $this->assertSame(array('staff_id' => 0, 'team_id' => 0),
            ModelRules::assignTarget('x99'));
    }

    /** Los números se limpian: el formulario nunca debería traerlos sucios. */
    function testElIdentificadorSeLimpiaDeLoQueNoSeaNumero() {
        $this->assertSame(array('staff_id' => 7, 'team_id' => 0),
            ModelRules::assignTarget('s7abc'));
    }

    /* ------------------------------------------------------------------ */
    /* Referencias a otro modelo (`Dept::update`)                          */
    /* ------------------------------------------------------------------ */

    /** Un campo vacío no se comprueba: es opcional. */
    function testUnaReferenciaVaciaNoEsUnError() {
        $this->assertSame(array(), ModelRules::unknownReferences(
            array('sla_id' => 0, 'manager_id' => ''),
            array('sla_id' => array('Invalid SLA', 'no-existe'),
                'manager_id' => array('Unknown Staff', 'no-existe'))));
    }

    /** El que viene y no existe se rechaza con su mensaje. */
    function testUnaReferenciaQueNoExisteDaSuMensaje() {
        $errors = ModelRules::unknownReferences(
            array('sla_id' => 9, 'tpl_id' => 4),
            array('sla_id' => array('Invalid SLA', function($id) { return false; }),
                'tpl_id' => array('Unknown Template Set', function($id) { return $id == 4; })));

        $this->assertSame(array('sla_id' => 'Invalid SLA'), $errors);
    }

    /** Sólo se informa de los campos que estaban configurados. */
    function testSoloSeCompruebanLosCamposConfigurados() {
        $errors = ModelRules::unknownReferences(array('otro' => 1, 'sla_id' => 2),
            array('sla_id' => array('Invalid SLA', function($id) { return true; })));

        $this->assertSame(array(), $errors);
    }

    /* ------------------------------------------------------------------ */
    /* Banderas de asignación (`Dept::update`)                             */
    /* ------------------------------------------------------------------ */

    function testLasTresFormasDeAsignar() {
        $this->assertSame(array('members_only' => false, 'primary_only' => false),
            ModelRules::assignmentFlags('all'));
        $this->assertSame(array('members_only' => true, 'primary_only' => false),
            ModelRules::assignmentFlags('members'));
        $this->assertSame(array('members_only' => false, 'primary_only' => true),
            ModelRules::assignmentFlags('primary'));
    }

    /** Un valor desconocido no toca las banderas: null, no `false`. */
    function testUnValorDeAsignacionDesconocidoNoCambiaNada() {
        $this->assertNull(ModelRules::assignmentFlags('otra-cosa'));
        $this->assertNull(ModelRules::assignmentFlags(''));
    }

    /* ------------------------------------------------------------------ */
    /* Señales de «editado» (`Dept::update`, `Organization::update`)       */
    /* ------------------------------------------------------------------ */

    function testLasSenalesLlevanLosCamposQueCambian() {
        $signals = ModelRules::editedSignals(
            array('name' => 'Soporte', 'sla_id' => 2, 'members' => array(1)),
            function($key) {
                return array(true, array('name' => 'Soporte',
                    'sla_id' => 1, 'members' => array(1))[$key]);
            }, 'Active');

        $this->assertSame(array(array('type' => 'edited', 'key' => 'sla_id')),
            $signals);
    }

    /** El estado va aparte: su señal lleva el estado nuevo, no el campo. */
    function testElEstadoMandaSuPropiaSenal() {
        $signals = ModelRules::editedSignals(array('status' => 'archived'),
            function($key) { return array(false, null); }, 'Active');

        $this->assertSame(array(array('type' => 'edited', 'status' => 'Archived')),
            $signals);
    }

    /** En un modelo nuevo (sin estado) el estado no genera señal. */
    function testUnModeloNuevoNoAvisaDelEstado() {
        $this->assertSame(array(), ModelRules::editedSignals(
            array('status' => 'active'),
            function($key) { return array(false, null); }, ''));
    }

    /** Una bandera que sólo vive en memoria también avisa. */
    function testUnaBanderaCambiadaAvisaAunqueNoSeaColumna() {
        $signals = ModelRules::editedSignals(
            array('disable_auto_claim' => 1),
            function($key) { return array(true, 1); },
            'Active', array('disable_auto_claim' => true));

        $this->assertSame(array(array('type' => 'edited',
            'key' => 'disable_auto_claim')), $signals);
    }

    /** `members` se excluye: los accesos avisan por su cuenta. */
    function testLosAccesosNoAvisanComoColumna() {
        $this->assertSame(array(), ModelRules::editedSignals(
            array('members' => array(2)),
            function($key) { return array(true, array(1)); }, 'Active'));
    }

    /** De las banderas de una organización se avisa de la última que cambió. */
    function testDeLasBanderasSeAvisaDeLaUltimaQueCambia() {
        $this->assertSame('assign-am-flag', ModelRules::lastFlagAudit(array(
            'collab-all-flag' => array(false, 1),
            'collab-pc-flag' => array(false, 0),
            'assign-am-flag' => array(true, 0),
        )));
        $this->assertNull(ModelRules::lastFlagAudit(array(
            'collab-all-flag' => array(1, 1),
        )));
    }

    /** Los contactos llegan como texto y se guardan como números. */
    function testLaListaDeContactosSeComparaComoTexo() {
        $this->assertFalse(ModelRules::idListChanged(array('3', '5'), array(3, 5)));
        $this->assertTrue(ModelRules::idListChanged(array('3'), array(3, 5)));
        $this->assertTrue(ModelRules::idListChanged(array(), array(3)));
    }

    /** Una columna vacía no genera aviso: sólo las que ya tenían valor. */
    function testSoloAvisanLasColumnasQueYaTeníanValor() {
        $changed = ModelRules::editedColumns(
            array('domain' => 'nuevo.example', 'phone' => '123', 'id' => 4),
            function($key) {
                return array('domain' => 'viejo.example', 'phone' => '',
                    'id' => 3)[$key];
            }, array('id'));

        $this->assertSame(array('domain'), $changed);
    }

    /* ------------------------------------------------------------------ */
    /* Valores del formulario con su tipo (`Dept::update`)                 */
    /* ------------------------------------------------------------------ */

    function testLosEnterosSeConviertenYLosVaciosSonCero() {
        $values = ModelRules::fieldValues(
            array('ispublic' => '1', 'sla_id' => ''),
            array('ispublic' => 'int', 'sla_id' => 'int', 'tpl_id' => 'int'));

        $this->assertSame(array('ispublic' => 1, 'sla_id' => 0, 'tpl_id' => 0),
            $values);
    }

    function testLoOpcionalVacioEsNulo() {
        $this->assertSame(array('pid' => null),
            ModelRules::fieldValues(array('pid' => '0'), array('pid' => 'nullable')));
        // Tal cual llega: el modelo lo guarda y la base lo convierte.
        $this->assertSame(array('pid' => '5'),
            ModelRules::fieldValues(array('pid' => '5'), array('pid' => 'nullable')));
    }

    /** El valor por omisión sólo entra si el formulario no manda el campo. */
    function testElValorPorOmisionSoloSiNoVieneElCampo() {
        $defaults = array('ticket_auto_response' => 1);
        $this->assertSame(array('ticket_auto_response' => 1),
            ModelRules::fieldValues(array(),
                array('ticket_auto_response' => 'raw'), $defaults));
        $this->assertSame(array('ticket_auto_response' => 0),
            ModelRules::fieldValues(array('ticket_auto_response' => 0),
                array('ticket_auto_response' => 'raw'), $defaults));
    }

    /** Un campo `raw` viaja tal cual, sin conversión. */
    function testUnCampoCrudoNoSeConvierte() {
        $this->assertSame(array('group_membership' => 'grupo'),
            ModelRules::fieldValues(array('group_membership' => 'grupo'),
                array('group_membership' => 'raw')));
    }

    /* ------------------------------------------------------------------ */
    /* Estados de un ticket (`Ticket::setStatus`)                          */
    /* ------------------------------------------------------------------ */

    /** Cerrar pide `close`, borrar pide `delete`, el resto nada. */
    function testElPermisoQuePideCadaEstado() {
        $this->assertSame('close', ModelRules::statusPermission('closed'));
        $this->assertSame('delete', ModelRules::statusPermission('deleted'));
        $this->assertNull(ModelRules::statusPermission('open'));
        $this->assertNull(ModelRules::statusPermission(''));
        $this->assertNull(ModelRules::statusPermission('lo-que-sea'));
    }

    /** Al reabrir se devuelve el ticket al agente sólo si se puede. */
    function testReabrirReasignaSoloCuandoTodoEncaja() {
        $this->assertSame(7, ModelRules::reopenAssignee(true, 7, true, true));
    }

    /** Basta con que falle una condición para volver al montón. */
    function testSiAlgoFallaElTicketVuelveSinAsignar() {
        // El departamento no permite reasignar al reabrir
        $this->assertSame(0, ModelRules::reopenAssignee(false, 7, true, true));
        // No hay agente al que devolverlo
        $this->assertSame(0, ModelRules::reopenAssignee(true, 0, true, true));
        // Está de vacaciones
        $this->assertSame(0, ModelRules::reopenAssignee(true, 7, false, true));
        // No tiene acceso al departamento
        $this->assertSame(0, ModelRules::reopenAssignee(true, 7, true, false));
    }

    /** El agente vuelve como número, aunque llegue como texto. */
    function testElAgenteSeDevuelveComoNumero() {
        $this->assertSame(12, ModelRules::reopenAssignee(true, '12', true, true));
    }
}
