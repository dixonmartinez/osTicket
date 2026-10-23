<?php
/**
 * tests/ThreadEmailTest.php
 *
 * Pruebas de las piezas puras de `Thread::postEmail`, sacadas a
 * `ThreadEmailInfo` (frente 2 de docs/CODE_QUALITY.md).
 *
 * El caso del ticket cerrado necesita algo que sea `instanceof Ticket`. La
 * clase real arrastra el ORM entero, así que aquí se declara una mínima
 * cuando no existe (en el entorno de pruebas nunca existe). Si algún día se
 * cargara la de verdad, esa prueba se salta en lugar de dar un falso verde.
 */
class ThreadEmailTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* ¿Se acepta el correo?                                               */
    /* ------------------------------------------------------------------ */

    public function testNoAceptaUnHiloSinObjeto(): void {
        $this->assertFalse(ThreadEmailInfo::accepts(null, 0));
        $this->assertFalse(ThreadEmailInfo::accepts(false, 7));
    }

    public function testAceptaCorreoEnCualquierOtroObjeto(): void {
        // Tareas, hilos de organización, etc. no tienen la regla del cierre
        $this->assertTrue(ThreadEmailInfo::accepts(new stdClass(), 0));
    }

    public function testTicketAbiertoAceptaCorreoDelCliente(): void {
        $this->assertTrue(ThreadEmailInfo::accepts(
            $this->ticket(false, false), 0));
    }

    public function testTicketCerradoQueSeReabreAceptaCorreoDelCliente(): void {
        $this->assertTrue(ThreadEmailInfo::accepts(
            $this->ticket(true, true), 0));
    }

    public function testTicketCerradoQueNoSeReabreRechazaAlCliente(): void {
        $this->assertFalse(ThreadEmailInfo::accepts(
            $this->ticket(true, false), 0));
    }

    public function testElAgenteSiemprePuedeResponder(): void {
        // Un agente que responde reabre el ticket: nunca se descarta su correo
        $this->assertTrue(ThreadEmailInfo::accepts(
            $this->ticket(true, false), 3));
    }

    /* ------------------------------------------------------------------ */
    /* Variables de la entrada                                             */
    /* ------------------------------------------------------------------ */

    public function testVarsTraduceElCorreo(): void {
        $vars = ThreadEmailInfo::varsFromMail(array(
            'mid' => '<abc@example.com>',
            'header' => 'From: Ana <ana@example.com>',
            'name' => 'Ana',
            'recipients' => 'soporte@example.com',
            'thread_entry_recipients' => array('ana@example.com'),
            'to-email-id' => 12,
        ), 99);

        $this->assertSame('<abc@example.com>', $vars['mid']);
        $this->assertSame('Ana', $vars['poster']);
        $this->assertSame('Email', $vars['origin']);
        $this->assertSame('Email', $vars['source']);
        $this->assertSame('', $vars['ip']);
        $this->assertSame(99, $vars['reply_to']);
        $this->assertSame('soporte@example.com', $vars['recipients']);
        $this->assertSame(12, $vars['to-email-id']);
        $this->assertTrue($vars['autorespond']);
    }

    public function testVarsRespetaElCorreoPasivo(): void {
        // 'passive' marca el correo que no debe generar auto-respuesta
        $vars = ThreadEmailInfo::varsFromMail(array('passive' => true));
        $this->assertFalse($vars['autorespond']);
    }

    public function testVarsNoInventaClavesQueNoVienen(): void {
        $vars = ThreadEmailInfo::varsFromMail(array());

        $this->assertFalse(array_key_exists('attachments', $vars));
        $this->assertFalse(array_key_exists('ticketId', $vars));
        $this->assertFalse(array_key_exists('taskId', $vars));
    }

    public function testVarsCopiaLosAdjuntos(): void {
        $adjuntos = array('uno.pdf', 'dos.png');
        $vars = ThreadEmailInfo::varsFromMail(array('attachments' => $adjuntos));

        $this->assertSame($adjuntos, $vars['attachments']);
    }

    /* ------------------------------------------------------------------ */
    /* Tipo de entrada según quién escribe                                 */
    /* ------------------------------------------------------------------ */

    public function testElDuenoDelTicketEscribeUnMensaje(): void {
        $type = ThreadEmailInfo::entryTypeForUserClass('U');

        $this->assertSame('M', $type['thread-type']);
        $this->assertSame('user', $type['poster']);
        $this->assertFalse(isset($type['flags']));
    }

    public function testElColaboradorEscribeUnMensajeMarcado(): void {
        $type = ThreadEmailInfo::entryTypeForUserClass('C');

        $this->assertSame('M', $type['thread-type']);
        $this->assertSame(ThreadEmailInfo::FLAG_COLLABORATOR, $type['flags']);
    }

    public function testElAgenteEscribeUnaNota(): void {
        foreach (array('A', 'S') as $class) {
            $type = ThreadEmailInfo::entryTypeForUserClass($class);
            $this->assertSame('N', $type['thread-type']);
            $this->assertSame('staff', $type['poster']);
        }
    }

    public function testLaClaseDesconocidaSeDejaParaDespues(): void {
        // null significa «mira el ticket»; no se adivina
        $this->assertNull(ThreadEmailInfo::entryTypeForUserClass(null));
        $this->assertNull(ThreadEmailInfo::entryTypeForUserClass(''));
        $this->assertNull(ThreadEmailInfo::entryTypeForUserClass('X'));
    }

    public function testLaBanderaCoincideConLaDeThreadEntry(): void {
        if (!class_exists('ThreadEntry')) {
            // En el entorno sin ORM no se puede contrastar; se comprueba el
            // valor documentado en class.thread.php
            $this->assertSame(0x0020, ThreadEmailInfo::FLAG_COLLABORATOR);
            return;
        }

        $this->assertSame(ThreadEntry::FLAG_COLLABORATOR,
            ThreadEmailInfo::FLAG_COLLABORATOR);
    }

    /* ------------------------------------------------------------------ */
    /* Firma y aviso                                                       */
    /* ------------------------------------------------------------------ */

    public function testLaFirmaUsaElNombreDelPoster(): void {
        $this->assertSame('Agente',
            ThreadEmailInfo::poster('Agente', 'Ana', 'ana@example.com'));
    }

    public function testLaFirmaCaeAlNombreDelRemitente(): void {
        $this->assertSame('Ana',
            ThreadEmailInfo::poster('', 'Ana', 'ana@example.com'));
    }

    public function testLaFirmaCaeAlCorreoSiNoHayNombre(): void {
        $this->assertSame('ana@example.com',
            ThreadEmailInfo::poster(null, '', 'ana@example.com'));
    }

    public function testElAvisoDeRemitenteDesconocido(): void {
        $texto = ThreadEmailInfo::receivedFrom('Ana', 'ana@example.com');

        $this->assertSame("Received From: Ana <ana@example.com>\n\n", $texto);
    }

    /* ------------------------------------------------------------------ */
    /* Dobles                                                              */
    /* ------------------------------------------------------------------ */

    private function ticket($closed, $reopenable) {
        return new TicketParaPruebas($closed, $reopenable);
    }
}

/* Un `Ticket` mínimo: lo único que ThreadEmailInfo::accepts() le pide son
   isClosed() e isReopenable(). */
if (!class_exists('Ticket', false)) {
    class Ticket {
        public $closed = false;
        public $reopenable = false;

        function isClosed() { return $this->closed; }
        function isReopenable() { return $this->reopenable; }
    }

    class TicketParaPruebas extends Ticket {
        function __construct($closed, $reopenable) {
            $this->closed = $closed;
            $this->reopenable = $reopenable;
        }
    }
}
else {
    /* Si la clase real ya estaba cargada, `TicketParaPruebas` no se puede
       heredar sin constructor válido: se usa una envoltura que falla de
       forma explícita antes que dar un resultado falso. */
    class TicketParaPruebas {
        function __construct($closed, $reopenable) {
            throw new Exception('La clase Ticket real está cargada: '
                . 'estas pruebas necesitan el doble de tests/ThreadEmailTest.php');
        }
    }
}
