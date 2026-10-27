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

    /* ------------------------------------------------------------------ */
    /* Combinar y enlazar: qué se puede y qué no                           */
    /* ------------------------------------------------------------------ */

    /** Una fila como la que arma `massProcess()` antes de analizar. */
    private function fila($id, $mergeType = 'visual', $isParent = false, $pid = null) {
        return array('ticket_id' => $id, 'merge_type' => $mergeType,
            'is_parent' => $isParent, 'ticket_pid' => $pid);
    }

    /** Sin padres ni hijos: el primero de la lista abre el diálogo. */
    public function testCombinarSinPadresNoAvisa(): void {
        $analysis = MassAction::analyzeMerge(
            array($this->fila(1), $this->fila(2)), 'merge', 'merged', 2);

        $this->assertNull($analysis['parent']);
        $this->assertNull($analysis['parent_merge_type']);
        $this->assertNull($analysis['error']);
    }

    /** Con un padre, es él quien encabeza: se mueve al principio. */
    public function testElPrimerPadreEsElQueEncabeza(): void {
        $analysis = MassAction::analyzeMerge(
            array($this->fila(7), $this->fila(9, 'combine', true)), 'merge', 'merged', 2);

        $this->assertSame(9, $analysis['parent']['ticket_id']);
        $this->assertSame('combine', $analysis['parent_merge_type']);
        $this->assertNull($analysis['error']);
    }

    /** Dos padres: no se puede, y se dice cuál es el problema. */
    public function testDosPadresSeRechazan(): void {
        $analysis = MassAction::analyzeMerge(
            array($this->fila(9, 'combine', true), $this->fila(10, 'combine', true)),
            'merge', 'merged', 2);

        $this->assertSame('More than one Parent Ticket selected. '
            . 'The selected Tickets cannot be merged.',
            $analysis['error']);
    }

    /** Enlazar algo que ya es una combinación: no se puede. */
    public function testEnlazarUnaCombinacionSeRechaza(): void {
        $analysis = MassAction::analyzeMerge(
            array($this->fila(5, 'combine')), 'link', 'linked', 1);

        $this->assertSame('One or more Tickets selected is part of a merge. '
            . 'Merged Tickets cannot be linked.', $analysis['error']);
        // El mismo ticket como combinación normal no da problema
        $this->assertNull(MassAction::analyzeMerge(
            array($this->fila(5, 'combine')), 'merge', 'merged', 1)['error']);
    }

    /** Un hijo combinado no se puede volver a combinar. */
    public function testUnHijoCombinadoSeRechaza(): void {
        $analysis = MassAction::analyzeMerge(
            array($this->fila(5, 'combine', false, 9)), 'merge', 'merged', 1);

        $this->assertSame('One or more Tickets selected is a merged child. '
            . 'the selected Ticket cannot be merged.', $analysis['error']);
        // …pero enlazarlo sí (enlazar no cambia el tipo de combinación)
        $this->assertNull(MassAction::analyzeMerge(
            array($this->fila(5, 'visual', false, 9)), 'link', 'linked', 1)['error']);
    }

    /** Las combinaciones «visuales» no bloquean nada. */
    public function testUnaCombinacionVisualNoBloquea(): void {
        $analysis = MassAction::analyzeMerge(
            array($this->fila(5, 'visual', true, null)), 'link', 'linked', 1);

        $this->assertNull($analysis['error']);
        $this->assertNull($analysis['parent'],
            'una combinación visual no encabeza la lista');
    }

    /** Si se dan varias condiciones, gana la última (como en el original). */
    public function testElUltimoAvisoGana(): void {
        $analysis = MassAction::analyzeMerge(
            array($this->fila(9, 'combine', true), $this->fila(10, 'combine', true),
                $this->fila(11, 'combine', false, 9)),
            'merge', 'merged', 3);

        $this->assertStringContainsString('merged child', $analysis['error'],
            'el aviso del hijo combinado se comprueba después de los padres');
    }

    /* ------------------------------------------------------------------ */
    /* Textos de los diálogos en lote                                      */
    /* ------------------------------------------------------------------ */

    /**
     * La lista de «Assign to» se ordena como se llama a los agentes.
     *
     * Si el formato es «Apellido, Nombre», ordenar por nombre de pila deja
     * una lista que no se corresponde con lo que se lee. Estaba escrito dos
     * veces, en tickets y en tareas.
     */
    public function testElOrdenDeLosAgentesSigueElFormatoDeNombre(): void {
        foreach (array('last', 'lastfirst', 'legal') as $format)
            $this->assertSame(array('lastname', 'firstname'),
                MassAction::agentOrder($format), $format);

        foreach (array('first', 'firstlast', '', null) as $format)
            $this->assertSame(array('firstname', 'lastname'),
                MassAction::agentOrder($format), (string) $format);
    }

    /** El diálogo de asignación, y el de reclamo (que confirma). */
    public function testElDialogoDeAsignacion(): void {
        $assign = MassAction::assignPrompt('ticket', 'agents', 3);

        $this->assertSame('#tickets/mass/assign/agents', $assign[':action']);
        $this->assertSame('Assign 3 selected tickets', $assign[':title']);
        $this->assertFalse(isset($assign['warn']),
            'asignar no pregunta: el formulario ya es la elección');

        $claim = MassAction::assignPrompt('ticket', 'me', 1);
        $this->assertSame('#tickets/mass/claim', $claim[':action']);
        $this->assertSame('Claim 1 selected ticket', $claim[':title']);
        $this->assertSame('Are you sure you want to CLAIM 1 selected ticket?',
            $claim['warn']);

        // En tareas cambia la ruta y el sustantivo
        $task = MassAction::assignPrompt('task', 'teams', 2);
        $this->assertSame('#tasks/mass/assign/teams', $task[':action']);
        $this->assertSame('Assign 2 selected tasks', $task[':title']);
    }

    /** Transferir, borrar y cambiar de estado: los textos de cada diálogo. */
    public function testLosTextosDeConfirmacion(): void {
        $transfer = MassAction::confirm('ticket', 'transfer', 3);
        $this->assertSame('#tickets/mass/transfer', $transfer[':action']);
        $this->assertSame('Transfer 3 selected tickets', $transfer[':title']);

        $delete = MassAction::confirm('ticket', 'delete', 3);
        $this->assertSame('#tickets/mass/delete', $delete[':action']);
        $this->assertSame('Delete 3 selected tickets', $delete[':title']);
        $this->assertSame('Optional reason for deleting 3 selected tickets',
            $delete[':placeholder']);
        $this->assertSame('Are you sure you want to DELETE 3 selected tickets?',
            $delete['warn']);
        $this->assertStringContainsString('CANNOT be recovered',
            $delete[':extra']);
        $this->assertStringContainsString('tickets', $delete[':extra']);

        // En tareas el aviso dice «tasks», no «tickets» (estaba copiado)
        $this->assertStringContainsString('Deleted tasks',
            MassAction::confirm('task', 'delete', 1)[':extra']);

        $reopen = MassAction::confirm('task', 'reopen', 2);
        $this->assertSame('#tasks/mass/reopen', $reopen[':action']);
        $this->assertSame('Reopen 2 selected tasks', $reopen[':title']);
        $this->assertSame('Are you sure you want to REOPEN 2 selected tasks?',
            $reopen['warn']);

        $close = MassAction::confirm('task', 'close', 2);
        $this->assertSame('#tasks/mass/close', $close[':action']);
        $this->assertSame('Are you sure you want to CLOSE 2 selected tasks?',
            $close['warn']);

        // Lo que no tiene diálogo propio no inventa uno
        $this->assertNull(MassAction::confirm('ticket', 'merge', 2));
        $this->assertNull(MassAction::confirm('ticket', 'assign', 2));
    }

    /** El mensaje de fallo, con el verbo de la acción. */
    public function testElMensajeDeFallo(): void {
        $this->assertSame('Unable to transfer 3 selected tickets',
            MassAction::failure('ticket', 'transfer', 3));
        $this->assertSame('Unable to assign 1 selected ticket',
            MassAction::failure('ticket', 'assign', 1));
        // En tareas, cambiar de estado no dice cuál: lo elige el formulario
        $this->assertSame('Unable to change status of 2 selected tasks',
            MassAction::failure('task', 'close', 2));
        $this->assertSame('Unable to delete 2 selected tasks.',
            MassAction::failure('task', 'delete', 2));

        // Singular/plural, sin «1 selected tickets»
        $this->assertSame('Unable to delete 1 selected ticket',
            MassAction::failure('ticket', 'delete', 1));
    }

    /* ------------------------------------------------------------------ */
    /* Permisos de la combinación, departamento por departamento           */
    /* ------------------------------------------------------------------ */

    /** Un agente de mentira que sólo reparte roles por departamento. */
    private function agenteConRoles(array $permisos) {
        return new class($permisos) {
            private $permisos;
            public function __construct(array $permisos) {
                $this->permisos = $permisos;
            }
            public function getRole($dept) {
                $permisos = $this->permisos;
                return new class($permisos, $dept) {
                    private $permisos;
                    private $dept;
                    public function __construct(array $permisos, $dept) {
                        $this->permisos = $permisos;
                        $this->dept = $dept;
                    }
                    public function hasPerm($perm) {
                        // El departamento sin entrada no da el permiso
                        return !empty($this->permisos[$this->dept]);
                    }
                };
            }
        };
    }

    public function testElPermisoSePreguntaDepartamentoPorDepartamento(): void {
        $rows = array(array('ticket_id' => 1, 'dept_id' => 1),
                      array('ticket_id' => 2, 'dept_id' => 2),
                      array('ticket_id' => 3, 'dept_id' => 1));

        $has = MassAction::mergePermissions($rows,
            $this->agenteConRoles(array(1 => true)), 'merge');

        $this->assertSame(array(true, false, true), $has);
    }

    public function testSinDepartamentosConPermisoTodoEsFalso(): void {
        $rows = array(array('ticket_id' => 1, 'dept_id' => 5));

        $this->assertSame(array(false), MassAction::mergePermissions($rows,
            $this->agenteConRoles(array()), 'merge'));
    }

    public function testSinFilasNoHayPermisos(): void {
        $this->assertSame(array(), MassAction::mergePermissions(array(),
            $this->agenteConRoles(array()), 'merge'));
    }

    /* ------------------------------------------------------------------ */
    /* Desplegables del diálogo de asignación                              */
    /* ------------------------------------------------------------------ */

    /** Un agente de mentira, lo mínimo que mira `memberOptions()`. */
    private function agente($id, $nombre) {
        $agente = $this;
        return new class($id, $nombre) {
            private $id;
            private $nombre;
            public function __construct($id, $nombre) {
                $this->id = $id;
                $this->nombre = $nombre;
            }
            public function getId() { return $this->id; }
            public function getName() { return $this->nombre; }
        };
    }

    public function testLosAgentesVanConPrefijoS(): void {
        // El sufijo `s` es lo que el formulario lee como agente
        $opciones = MassAction::memberOptions(array(
            $this->agente(7, 'Ana'), $this->agente(9, 'Beto')));

        $this->assertSame(array('s7' => 'Ana', 's9' => 'Beto'),
            $opciones['assignees']);
        $this->assertSame('Select an Agent', $opciones['prompt']);
        $this->assertNull($opciones['warn'], 'con agentes no hay aviso');
    }

    public function testSinAgentesSeAvisa(): void {
        $opciones = MassAction::memberOptions(array());

        $this->assertSame(array(), $opciones['assignees']);
        $this->assertSame('No agents available for assignment',
            $opciones['warn']);
    }

    public function testLosEquiposVanConPrefijoT(): void {
        $opciones = MassAction::teamAssignees(array(3 => 'Soporte',
            5 => 'Ventas'));

        $this->assertSame(array('t3' => 'Soporte', 't5' => 'Ventas'),
            $opciones['assignees']);
        $this->assertSame('Select a Team', $opciones['prompt']);
        $this->assertNull($opciones['warn']);
        $this->assertSame('No teams available for assignment',
            MassAction::teamAssignees(array())['warn']);
    }

    /* ------------------------------------------------------------------ */
    /* Cierre común de las dos massProcess()                               */
    /* ------------------------------------------------------------------ */

    public function testSinNadaHechoSeAvisaYSeDevuelvenLosCampos(): void {
        $_POST = array('tids' => array(1, 2), 'comments' => 'motivo');

        $info = MassAction::finish('ticket', 'delete', 2, 0, array(),
            array(), 'deleted');

        $this->assertSame('Unable to process selected tickets',
            $info['error']);
        // Los campos vuelven escapados para que el formulario no se vacíe
        $this->assertSame('motivo', $info['comments']);
        $_POST = array();
    }

    public function testElAvisoSinNadaHechoVaEnSingular(): void {
        $_POST = array('tids' => array(1));

        $info = MassAction::finish('task', 'delete', 1, 0, array(),
            array(), 'deleted');

        $this->assertSame('Unable to process selected task', $info['error']);
        $_POST = array();
    }

    public function testElErrorPropioMandaSobreElGenerico(): void {
        $_POST = array('tids' => array(1));

        $info = MassAction::finish('ticket', 'delete', 1, 0, array(),
            array('err' => 'Permission denied'), 'deleted');

        $this->assertSame('Permission denied', $info['error']);
        $_POST = array();
    }

    public function testSinPostNoSeTocaNada(): void {
        $_POST = array();

        $info = MassAction::finish('ticket', 'delete', 2, 0,
            array(':title' => 'Delete 2 selected tickets'), array(), 'deleted');

        $this->assertTrue(!isset($info['error']), 'sin POST no hay aviso');
        $this->assertSame('Delete 2 selected tickets', $info[':title']);
    }

    public function testElScriptCopiaElValorDeLaCasilla(): void {
        // Sin esto, el formulario del diálogo no sabría qué tickets van
        $script = MassAction::selectedScript('tickets');

        $this->assertStringContainsString(".val(\$(this).val())", $script);
        $this->assertStringContainsString("'type', 'hidden'", $script);
    }
}
