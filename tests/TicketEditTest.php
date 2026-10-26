<?php
/**
 * tests/TicketEditTest.php
 *
 * Pruebas de las reglas de la edición de un ticket sin base de datos (frente 2
 * de docs/CODE_QUALITY.md): la fecha límite, el origen y qué cambios quedan en
 * el historial.
 */

require_once INCLUDE_DIR . 'class.ticket.edit.php';

class TicketEditTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* Fecha límite                                                        */
    /* ------------------------------------------------------------------ */

    function testUnaFechaLimiteFuturaEsValida() {
        $this->assertSame(null, TicketEdit::dueDateError('01/01/2030', false,
            'strtotime', strtotime('2026-10-26')));
    }

    /** Sin fecha límite no hay nada que comprobar. */
    function testSinFechaLimiteNoHayError() {
        $this->assertSame(null, TicketEdit::dueDateError('', true,
            'strtotime', time()));
        $this->assertSame(null, TicketEdit::dueDateError(null, true,
            'strtotime', time()));
    }

    function testUnTicketCerradoNoAdmiteFechaLimite() {
        $this->assertSame('Due date can NOT be set on a closed ticket',
            TicketEdit::dueDateError('01/01/2030', true, 'strtotime', time()));
    }

    function testUnaFechaLimiteIlegibleEsUnError() {
        $this->assertSame('Invalid due date',
            TicketEdit::dueDateError('mañana', false, 'strtotime', time()));
    }

    function testUnaFechaLimiteEnElPasadoEsUnError() {
        $this->assertSame('Due date must be in the future',
            TicketEdit::dueDateError('01/01/2020', false, 'strtotime',
                strtotime('2026-10-26')));
    }

    /** Justo ahora tampoco vale: tiene que ser futura. */
    function testUnaFechaLimiteIgualAAhoraNoVale() {
        $now = strtotime('2026-10-26 10:00:00');
        $this->assertSame('Due date must be in the future',
            TicketEdit::dueDateError('2026-10-26 10:00:00', false, 'strtotime',
                $now));
    }

    /** El conversor es de quien llama: aquí uno que siempre falla. */
    function testSiElConversorNoEntiendeLaFechaSeAvisa() {
        $this->assertSame('Invalid due date',
            TicketEdit::dueDateError('01/01/2030', false,
                function($date) { return false; }, time()));
    }

    /* ------------------------------------------------------------------ */
    /* Origen                                                              */
    /* ------------------------------------------------------------------ */

    function testUnOrigenConocidoEsValido() {
        $this->assertSame(null, TicketEdit::sourceError('Phone',
            array('Phone' => 'Phone', 'Email' => 'Email')));
    }

    function testSinOrigenNoHayError() {
        $this->assertSame(null, TicketEdit::sourceError(null, array('Phone' => 'Phone')));
    }

    function testUnOrigenInventadoSeRechaza() {
        $message = TicketEdit::sourceError('Paloma',
            array('Phone' => 'Phone'));

        $this->assertTrue(strpos($message, 'Invalid source given') !== false);
        $this->assertTrue(strpos($message, 'Paloma') !== false);
    }

    /* ------------------------------------------------------------------ */
    /* Historial                                                           */
    /* ------------------------------------------------------------------ */

    function testSoloSeRegistranLosCamposDelHistorial() {
        $changes = TicketEdit::trackedChanges(
            array('topic_id' => 3, 'isoverdue' => 0, 'sla_id' => 1),
            function($field) { return array('topic_id' => 9, 'sla_id' => 5)[$field]; });

        $this->assertSame(array('topic_id', 'sla_id'), array_keys($changes));
        $this->assertSame(array(3, 9), $changes['topic_id']);
        $this->assertSame(array(1, 5), $changes['sla_id']);
    }

    function testSinCambiosNoHayHistorial() {
        $this->assertSame(array(), TicketEdit::trackedChanges(array(),
            function($field) { return null; }));
    }

    /** Los cuatro campos del formulario llevan su mensaje. */
    function testLosCamposDeLaEdicionLlevanSuMensaje() {
        $fields = TicketEdit::fields();

        $this->assertSame(array('topicId', 'slaId', 'duedate', 'user_id'),
            array_keys($fields));
        $this->assertSame('Help topic selection is required',
            $fields['topicId']['error']);
        $this->assertSame(1, $fields['topicId']['required']);
        $this->assertSame(0, $fields['slaId']['required']);
    }
}
