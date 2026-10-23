<?php
/**
 * tests/TicketCreateTest.php
 *
 * Pruebas de las piezas puras de `Ticket::create`, sacadas a `TicketCreate`
 * (frente 2 de docs/CODE_QUALITY.md).
 *
 * Se prueba justo lo que más se rompe al tocar los orígenes: qué campos
 * exige cada uno, qué se valida según quién abre el ticket, y cuándo una
 * fecha límite es aceptable.
 */
class TicketCreateTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* Campos obligatorios por origen                                      */
    /* ------------------------------------------------------------------ */

    public function testElPortalPideTema(): void {
        $campos = TicketCreate::requiredFields('web');

        $this->assertSame(array('topicId'), array_keys($campos));
        $this->assertSame(1, $campos['topicId']['required']);
    }

    public function testElAgentePideTambienOrigen(): void {
        // 'staff' cae al bloque de 'api': ademas del tema, exige source
        $campos = TicketCreate::requiredFields('staff');

        $this->assertSame(array('deptId', 'topicId', 'duedate', 'source'),
            array_keys($campos));
        $this->assertSame(0, $campos['deptId']['required']);
        $this->assertSame(1, $campos['source']['required']);
    }

    public function testLaApiSoloPideOrigen(): void {
        $campos = TicketCreate::requiredFields('api');

        $this->assertSame(array('source'), array_keys($campos));
    }

    public function testElCorreoPideBuzonDelSistema(): void {
        $campos = TicketCreate::requiredFields('email');

        $this->assertSame(array('emailId'), array_keys($campos));
        $this->assertSame(1, $campos['emailId']['required']);
    }

    public function testElOrigenNoDistingueMayusculas(): void {
        $this->assertSame(TicketCreate::requiredFields('web'),
            TicketCreate::requiredFields('WEB'));
    }

    public function testUnOrigenInventadoNoTieneCampos(): void {
        // El llamador lo convierte en «Invalid ticket origin given»
        $this->assertFalse(TicketCreate::requiredFields('telepatia'));
        $this->assertFalse(TicketCreate::requiredFields(''));
    }

    /* ------------------------------------------------------------------ */
    /* Qué se valida según quién abre                                      */
    /* ------------------------------------------------------------------ */

    public function testPorCorreoNoSeValidaNada(): void {
        $filtro = TicketCreate::fieldFilter('email');

        $this->assertFalse($filtro('ticket')(new TicketCreateFakeField()));
    }

    public function testElAgenteValidaSoloLoQueVeElAgente(): void {
        $filtro = TicketCreate::fieldFilter('staff');
        $visible = new TicketCreateFakeField(array('staff' => true));
        $soloCliente = new TicketCreateFakeField(array('user' => true));

        $this->assertTrue($filtro('ticket')($visible));
        $this->assertFalse($filtro('ticket')($soloCliente));
    }

    public function testElPortalValidaSoloLoQueVeElCliente(): void {
        $filtro = TicketCreate::fieldFilter('web');

        $this->assertTrue($filtro('ticket')(new TicketCreateFakeField(
            array('user' => true))));
        $this->assertFalse($filtro('ticket')(new TicketCreateFakeField(
            array('staff' => true))));
    }

    public function testLaApiValidaTodo(): void {
        $filtro = TicketCreate::fieldFilter('api');

        $this->assertTrue($filtro('ticket')(new TicketCreateFakeField()));
    }

    public function testElFiltroAceptaElNombreDelFormularioSinUsarlo(): void {
        // create() lo llama con 'user', 'ticket' y 'topic'
        $filtro = TicketCreate::fieldFilter('web');
        $campo = new TicketCreateFakeField(array('user' => true));

        $this->assertTrue($filtro('user')($campo));
        $this->assertTrue($filtro('topic')($campo));
    }

    /* ------------------------------------------------------------------ */
    /* Fecha límite                                                        */
    /* ------------------------------------------------------------------ */

    public function testSinFechaNoHayError(): void {
        $this->assertNull(TicketCreate::dueDateError('', $this->gmt()));
        $this->assertNull(TicketCreate::dueDateError(null, $this->gmt()));
    }

    public function testUnaFechaIlegibleEsUnError(): void {
        $this->assertSame('Invalid due date',
            TicketCreate::dueDateError('mañana', $this->gmt()));
    }

    public function testUnaFechaPasadaEsUnError(): void {
        $this->assertSame('Due date must be in the future',
            TicketCreate::dueDateError('2001-01-01 10:00', $this->gmt()));
    }

    public function testUnaFechaFuturaSeAcepta(): void {
        // Un año por delante: vale igual en PHP de 32 y de 64 bits
        $futuro = date('Y-m-d H:i', time() + 365 * 86400);

        $this->assertNull(TicketCreate::dueDateError($futuro, $this->gmt()));
    }

    public function testLaFechaDeHoyNoVale(): void {
        // El límite se compara en el mismo instante: «ahora» no es futuro
        $ahora = $this->gmt();
        $this->assertSame('Due date must be in the future',
            TicketCreate::dueDateError(date('Y-m-d H:i', time() + 1), $ahora));
    }

    /* ------------------------------------------------------------------ */
    /* Origen guardado                                                     */
    /* ------------------------------------------------------------------ */

    public function testElOrigenSeGuardaConMayuscula(): void {
        $this->assertSame('Web', TicketCreate::source('web'));
        $this->assertSame('Api', TicketCreate::source('api'));
        $this->assertSame('Email', TicketCreate::source('email'));
        $this->assertSame('', TicketCreate::source(null));
    }

    /* ------------------------------------------------------------------ */
    /* Dobles                                                              */
    /* ------------------------------------------------------------------ */

    /** Conversión a GMT como la de Misc::user2gmtime(), en UTC. */
    private function gmt() {
        return function ($timestamp = null) {
            if ($timestamp === null)
                return time();
            $t = strtotime($timestamp);
            return $t === false ? 0 : $t;
        };
    }
}

/** Campo de formulario mínimo: sólo lo que consulta el filtro. */
class TicketCreateFakeField {
    private $visibilidad;

    function __construct(array $visibilidad = array()) {
        $this->visibilidad = $visibilidad;
    }

    function isVisibleToStaff() {
        return !empty($this->visibilidad['staff']);
    }

    function isVisibleToUsers() {
        return !empty($this->visibilidad['user']);
    }
}
