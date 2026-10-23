<?php
/**
 * tests/TicketPostTest.php
 *
 * Pruebas de las piezas puras de `Ticket::postMessage`, sacadas a
 * `TicketPost` (frente 2 de docs/CODE_QUALITY.md).
 *
 * No tocan la base de datos: por eso se pudieron extraer. Los destinatarios
 * se simulan con objetos mínimos que sólo tienen `getContact()`.
 */
class TicketPostTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* Origen e IP                                                         */
    /* ------------------------------------------------------------------ */

    public function testNormalizeVarsUsaLaIpCorta(): void {
        $vars = TicketPost::normalizeVars(array('ip' => '10.0.0.9'));

        $this->assertSame('10.0.0.9', $vars['ip_address']);
        // El alias se conserva: otros trozos de osTicket lo leen
        $this->assertSame('10.0.0.9', $vars['ip']);
    }

    public function testNormalizeVarsUsaLaIpDeLaPeticion(): void {
        $vars = TicketPost::normalizeVars(array(), '', '192.168.1.5');

        $this->assertSame('192.168.1.5', $vars['ip_address']);
        // Sin origen explícito no se crea la clave
        $this->assertFalse(array_key_exists('origin', $vars));
    }

    public function testNormalizeVarsNoPisaUnaIpYaPuesta(): void {
        $vars = TicketPost::normalizeVars(
            array('ip_address' => '1.2.3.4'), '', '192.168.1.5');

        $this->assertSame('1.2.3.4', $vars['ip_address']);
    }

    public function testNormalizeVarsPoneElOrigenExplicito(): void {
        $vars = TicketPost::normalizeVars(array('origin' => 'antiguo'), 'email');

        $this->assertSame('email', $vars['origin']);
    }

    public function testNormalizeVarsSinOrigenNiIpNoInventa(): void {
        $vars = TicketPost::normalizeVars(array('body' => 'hola'), '', null);

        $this->assertSame(array('body' => 'hola'), $vars);
    }

    /* ------------------------------------------------------------------ */
    /* Destinatarios                                                       */
    /* ------------------------------------------------------------------ */

    private function contacto($userId, $id = 0) {
        return new TicketPostFakeContact($userId, $id);
    }

    private function destinatario($userId, $id = 0) {
        return new TicketPostFakeRecipient($this->contacto($userId, $id));
    }

    public function testWithoutPosterQuitaAlAutor(): void {
        $lista = array(
            'a' => $this->destinatario(1),
            'b' => $this->destinatario(2),
            'c' => $this->destinatario(3),
        );

        $resto = TicketPost::withoutPoster($lista, 2);

        $this->assertCount(2, $resto);
        $this->assertArrayHasKey('a', $resto);
        $this->assertArrayHasKey('c', $resto);
        // Las claves se conservan: el llamador las usa después
        $this->assertFalse(array_key_exists('b', $resto));
    }

    public function testWithoutPosterComparaSinTipo(): void {
        $lista = array($this->destinatario(7));

        // el id llega como texto desde la petición
        $this->assertCount(0, TicketPost::withoutPoster($lista, '7'));
    }

    public function testWithoutPosterUsaElIdDelContactoSiNoEsDeUsuario(): void {
        $lista = array(
            $this->destinatario(0, 15),
            $this->destinatario(0, 16),
        );

        $this->assertCount(1, TicketPost::withoutPoster($lista, 15));
    }

    public function testWithoutPosterDejaLosQueNoTienenContacto(): void {
        $lista = array(
            new TicketPostFakeRecipient(null),
            $this->destinatario(2),
        );

        $resto = TicketPost::withoutPoster($lista, 2);

        $this->assertCount(1, $resto);
        $this->assertSame(null, reset($resto)->getContact());
    }

    public function testWithoutPosterConListaVaciaONula(): void {
        $this->assertSame(array(), TicketPost::withoutPoster(array(), 1));
        $this->assertNull(TicketPost::withoutPoster(null, 1));
    }

    /* ------------------------------------------------------------------ */
    /* Rebotes y respuestas automáticas                                    */
    /* ------------------------------------------------------------------ */

    public function testAutorespondSinMailflags(): void {
        $this->assertTrue(TicketPost::shouldAutoRespond(array('body' => 'hola')));
    }

    public function testNoAutorespondeARebotes(): void {
        $this->assertFalse(TicketPost::shouldAutoRespond(
            array('mailflags' => array('bounce' => true, 'auto-reply' => false))));
    }

    public function testNoAutorespondeARespuestasAutomaticas(): void {
        $this->assertFalse(TicketPost::shouldAutoRespond(
            array('mailflags' => array('bounce' => false, 'auto-reply' => true))));
    }

    public function testAutorespondeAMensajesNormalesConMailflags(): void {
        $this->assertTrue(TicketPost::shouldAutoRespond(
            array('mailflags' => array('bounce' => false, 'auto-reply' => false))));
    }
}

/* ---------------------------------------------------------------------- */
/* Dobles de prueba                                                       */
/* ---------------------------------------------------------------------- */

class TicketPostFakeContact {
    private $userId;
    private $id;

    function __construct($userId, $id) {
        $this->userId = $userId;
        $this->id = $id;
    }

    function getUserId() {
        return $this->userId;
    }

    function getId() {
        return $this->id;
    }
}

class TicketPostFakeRecipient {
    private $contact;

    function __construct($contact) {
        $this->contact = $contact;
    }

    function getContact() {
        return $this->contact;
    }
}
