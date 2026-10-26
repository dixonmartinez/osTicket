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
}
