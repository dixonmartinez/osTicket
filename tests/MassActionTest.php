<?php
/**
 * tests/MassActionTest.php
 *
 * Pruebas de `MassAction` (`include/class.massaction.php`), las piezas que
 * comparten las acciones en lote de tickets y de tareas (frente 2 de
 * docs/CODE_QUALITY.md).
 *
 * Los textos esperados están copiados del código original, no calculados:
 * son pruebas de caracterización. Si alguien cambia un mensaje sin querer,
 * la prueba lo dice.
 */
class MassActionTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* Acciones                                                            */
    /* ------------------------------------------------------------------ */

    public function testLasAccionesDeTicketSonLasDeSiempre(): void {
        $actions = MassAction::actions('ticket');

        $this->assertSame(array('transfer', 'assign', 'refer', 'merge', 'link',
            'claim', 'delete', 'reopen', 'close'), array_keys($actions));
    }

    public function testLasAccionesDeTareaSonLasDeSiempre(): void {
        $actions = MassAction::actions('task');

        $this->assertSame(array('transfer', 'assign', 'claim', 'delete',
            'reopen', 'close'), array_keys($actions));
    }

    public function testClaimEsAssignedEnTickets(): void {
        // El agente «se asigna» un ticket
        $this->assertSame('assigned',
            MassAction::actions('ticket')['claim']['verbed']);
    }

    public function testClaimEsClaimedEnTareas(): void {
        // …pero «reclama» una tarea. Diferencia intencionada.
        $this->assertSame('claimed',
            MassAction::actions('task')['claim']['verbed']);
    }

    public function testReopenLlevaVerboDistintoEnCadaSitio(): void {
        // Así estaba: sin la «d» final en tickets
        $this->assertSame('reopen',
            MassAction::actions('ticket')['reopen']['verbed']);
        $this->assertSame('reopened',
            MassAction::actions('task')['reopen']['verbed']);
    }

    public function testLoQueSoloExisteEnTickets(): void {
        $this->assertTrue(MassAction::isAction('ticket', 'merge'));
        $this->assertTrue(MassAction::isAction('ticket', 'link'));
        $this->assertTrue(MassAction::isAction('ticket', 'refer'));

        $this->assertFalse(MassAction::isAction('task', 'merge'));
        $this->assertFalse(MassAction::isAction('task', 'link'));
        $this->assertFalse(MassAction::isAction('task', 'refer'));
    }

    public function testUnaAccionInventadaNoExiste(): void {
        $this->assertFalse(MassAction::isAction('ticket', 'teleport'));
        $this->assertFalse(MassAction::isAction('task', ''));
    }

    /* ------------------------------------------------------------------ */
    /* Cuántos se han seleccionado                                         */
    /* ------------------------------------------------------------------ */

    public function testConPostCuentaLaLista(): void {
        $selection = MassAction::selection(array('tids' => array(1, 2, 3)),
            array(), 'ticket');

        $this->assertSame(3, $selection['count']);
        $this->assertNull($selection['error']);
    }

    public function testConPostSinSeleccionAvisa(): void {
        $selection = MassAction::selection(array('tids' => array()),
            array(), 'ticket');

        $this->assertSame(0, $selection['count']);
        $this->assertSame('You must select at least one ticket.',
            $selection['error']);
    }

    public function testConPostSinCampoTidsAvisa(): void {
        $selection = MassAction::selection(array('other' => 'x'), array(),
            'ticket');

        $this->assertSame(0, $selection['count']);
        $this->assertNotNull($selection['error']);
    }

    public function testElAvisoCambiaDeObjeto(): void {
        $this->assertSame('You must select at least one task.',
            MassAction::selectionError('task'));
    }

    public function testSinPostSoloSeSabeElNumero(): void {
        // Primera carga del diálogo: la lista aún no se ha enviado
        $selection = MassAction::selection(array(), array('count' => 5),
            'ticket');

        $this->assertSame(5, $selection['count']);
        $this->assertNull($selection['error']);
    }

    public function testSinPostNiNumeroQuedaCero(): void {
        $this->assertSame(0, MassAction::selection(array(), array(),
            'ticket')['count']);
    }

    /* ------------------------------------------------------------------ */
    /* Identificadores                                                     */
    /* ------------------------------------------------------------------ */

    public function testLosIdsLleganPorGetSeparadosPorComas(): void {
        $this->assertSame(array('4', '7'),
            MassAction::ids(array('tids' => '4,7'), array(), array()));
    }

    public function testLosIdsLleganPorPostComoLista(): void {
        $this->assertSame(array('4', '7'),
            MassAction::ids(array(), array('tids' => array('4', '7')), array()));
    }

    public function testLosIdsLleganDeLaPeticion(): void {
        $this->assertSame(array('9'),
            MassAction::ids(array(), array(), array('tids' => '9')));
    }

    public function testSeLimpianEspaciosYVacios(): void {
        $this->assertSame(array('4', '7'),
            MassAction::ids(array('tids' => ' 4 , ,7 ,'), array(), array()));
    }

    public function testSinIdsDevuelveListaVacia(): void {
        $this->assertSame(array(), MassAction::ids(array(), array(), array()));
    }

    public function testUnaListaConVaciosSeFiltra(): void {
        $this->assertSame(array('4'),
            MassAction::splitIds(array('4', '', null)));
    }

    /* ------------------------------------------------------------------ */
    /* Combinar o enlazar                                                  */
    /* ------------------------------------------------------------------ */

    public function testLinkSaleDeLaRuta(): void {
        $action = MassAction::mergeAction('/tickets/mass/link');

        $this->assertSame('link', $action['title']);
        $this->assertSame('linked', $action['event']);
    }

    public function testMergeSaleDeLaRuta(): void {
        $action = MassAction::mergeAction('/tickets/mass/merge');

        $this->assertSame('merge', $action['title']);
        $this->assertSame('merged', $action['event']);
    }

    public function testSinRutaSeAsumeMerge(): void {
        // Antes: strpos($_SERVER['PATH_INFO'], ...) con PATH_INFO ausente
        $this->assertSame('merge', MassAction::mergeAction('')['title']);
        $this->assertSame('merge', MassAction::mergeAction(null)['title']);
    }

    /* ------------------------------------------------------------------ */
    /* El padre primero                                                     */
    /* ------------------------------------------------------------------ */

    public function testElPadreSeMueveAlPrincipio(): void {
        $this->assertSame(array(3, 1, 2),
            MassAction::moveToFront(array(1, 2, 3), 3));
    }

    public function testSinPadreSeQuedaComoEstaba(): void {
        $this->assertSame(array(1, 2, 3),
            MassAction::moveToFront(array(1, 2, 3), null));
        $this->assertSame(array(1, 2, 3),
            MassAction::moveToFront(array(1, 2, 3), ''));
        $this->assertSame(array(1, 2, 3),
            MassAction::moveToFront(array(1, 2, 3), 99));
    }

    public function testElOrdenDelRestoNoCambia(): void {
        $this->assertSame(array('b', 'c', 'a', 'd'),
            MassAction::moveToFront(array('c', 'a', 'd', 'b'), 'b'));
    }

    public function testSoloSeMueveLaPrimeraCoincidencia(): void {
        $this->assertSame(array(2, 1, 2),
            MassAction::moveToFront(array(1, 2, 2), 2));
    }

    /* ------------------------------------------------------------------ */
    /* Estado                                                              */
    /* ------------------------------------------------------------------ */

    public function testReopenLlevaAOpen(): void {
        $this->assertSame('open', MassAction::statusFor('reopen'));
    }

    public function testCloseLlevaAClosed(): void {
        $this->assertSame('closed', MassAction::statusFor('close'));
    }

    public function testOtraAccionNoCambiaEstado(): void {
        $this->assertNull(MassAction::statusFor('assign'));
        $this->assertNull(MassAction::statusFor(''));
    }

    /* ------------------------------------------------------------------ */
    /* Mensajes                                                            */
    /* ------------------------------------------------------------------ */

    public function testMensajeDeExitoConVarios(): void {
        $this->assertSame('Successfully transferred 3 selected tickets.',
            MassAction::successMessage('transferred', 3, 'ticket'));
    }

    public function testMensajeDeExitoConUno(): void {
        $this->assertSame('Successfully transferred 1 selected ticket.',
            MassAction::successMessage('transferred', 1, 'ticket'));
    }

    public function testMensajeDeExitoDeTareas(): void {
        $this->assertSame('Successfully claimed 2 selected tasks.',
            MassAction::successMessage('claimed', 2, 'task'));
    }

    public function testMensajeParcial(): void {
        $this->assertSame('2 of 3 selected tickets transferred',
            MassAction::partialMessage(2, 3, 'transferred', 'ticket'));
    }

    public function testMensajeParcialEnSingular(): void {
        $this->assertSame('1 of 1 selected task closed',
            MassAction::partialMessage(1, 1, 'closed', 'task'));
    }

    /* ------------------------------------------------------------------ */
    /* Script que copia las casillas marcadas                              */
    /* ------------------------------------------------------------------ */

    public function testElScriptApuntaAlFormularioDeLaLista(): void {
        $script = MassAction::selectedScript('tickets');

        $this->assertStringContainsString(
            "form#tickets input[name=\"tids[]\"]:checkbox:checked", $script);
        $this->assertStringContainsString("appendTo('form.mass-action')",
            $script);
        // La etiqueta lleva el nonce de la CSP: el script se inyecta con
        // jQuery y el navegador sólo lo ejecuta si el nonce cuadra
        $this->assertStringContainsString(
            '<script nonce="' . Http::nonce() . '" type="text/javascript">',
            $script);
        // El mismo hueco que antes: una línea en blanco antes de la etiqueta
        $this->assertSame("\n", substr($script, 0, 1));
    }

    public function testElScriptCambiaDeFormulario(): void {
        $this->assertStringContainsString("form#tasks",
            MassAction::selectedScript('tasks'));
        $this->assertFalse(strpos(MassAction::selectedScript('tasks'),
            'form#tickets') !== false);
    }

    public function testElScriptCopiaElValorDeLaCasilla(): void {
        // Sin esto, el formulario del diálogo no sabría qué tickets van
        $script = MassAction::selectedScript('tickets');

        $this->assertStringContainsString(".val(\$(this).val())", $script);
        $this->assertStringContainsString("'type', 'hidden'", $script);
    }
}
