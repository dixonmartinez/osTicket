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

    /* ------------------------------------------------------------------ */
    /* A quién le llega el aviso de mensaje nuevo                          */
    /* ------------------------------------------------------------------ */

    private function config(array $opciones = array()) {
        return new TicketPostFakeConfig($opciones);
    }

    private function agente($direccion, $disponible = true) {
        return new TicketPostFakeStaff($direccion, $disponible);
    }

    private function ticket($opciones = array()) {
        return new TicketPostFakeTicket($opciones);
    }

    private function equipo(array $miembros) {
        return new TicketPostFakeTeam($miembros);
    }

    /** Todo apagado: no hay a quién avisar. */
    public function testSinOpcionesActivasNoHayDestinatarios(): void {
        $this->assertSame(array(), TicketPost::alertRecipients(
            $this->config(), $this->ticket(), null));
    }

    public function testElUltimoEnResponderEntra(): void {
        $lr = $this->agente('lr@example.com');
        $ticket = $this->ticket(array('lastRespondent' => $lr));

        $this->assertSame(array($lr), TicketPost::alertRecipients(
            $this->config(array('lastRespondent' => true)), $ticket, null));
    }

    public function testElAsignadoEntraYNoHaceFaltaSuEquipo(): void {
        $staff = $this->agente('ana@example.com');
        $equipo = $this->equipo(array($this->agente('equipo@example.com')));
        $ticket = $this->ticket(array('staff' => $staff, 'team' => $equipo));

        $this->assertSame(array($staff), TicketPost::alertRecipients(
            $this->config(array('assigned' => true)), $ticket, null));
    }

    public function testSinAsignadoAvisaAlEquipo(): void {
        $equipo = $this->equipo(array($this->agente('uno@example.com'),
                                     $this->agente('dos@example.com')));
        $ticket = $this->ticket(array('team' => $equipo));

        $this->assertCount(2, TicketPost::alertRecipients(
            $this->config(array('assigned' => true)), $ticket, null));
    }

    public function testElJefeDelDepartamentoEntra(): void {
        $jefe = $this->agente('jefe@example.com');
        $dept = new TicketPostFakeDept($jefe);

        $this->assertSame(array($jefe), TicketPost::alertRecipients(
            $this->config(array('deptManager' => true)), $this->ticket(), $dept));
    }

    public function testElGestorDeLaCuentaEntra(): void {
        $gestor = $this->agente('gestor@example.com');
        $ticket = $this->ticket(array('accountManager' => $gestor));

        $this->assertSame(array($gestor), TicketPost::alertRecipients(
            $this->config(array('acctManager' => true)), $ticket, null));
    }

    public function testElOrdenEsElDelCodigoOriginal(): void {
        // Último, asignado, jefe y gestor: el mismo orden de antes
        $lr = $this->agente('lr@example.com');
        $staff = $this->agente('ana@example.com');
        $jefe = $this->agente('jefe@example.com');
        $gestor = $this->agente('gestor@example.com');

        $ticket = $this->ticket(array('lastRespondent' => $lr, 'staff' => $staff,
            'accountManager' => $gestor));
        $config = $this->config(array('lastRespondent' => true,
            'assigned' => true, 'deptManager' => true, 'acctManager' => true));

        $this->assertSame(array($lr, $staff, $jefe, $gestor),
            TicketPost::alertRecipients($config, $ticket,
                new TicketPostFakeDept($jefe)));
    }

    public function testLaOrganizacionSeLeeDelCuartoArgumento(): void {
        // En un hijo combinado el mensaje va al padre, pero la organización se
        // leía del ticket donde se escribió: así se sigue haciendo
        $gestorHijo = $this->agente('gestor@example.com');
        $hijo = $this->ticket(array('accountManager' => $gestorHijo));
        $padre = $this->ticket();

        $this->assertSame(array($gestorHijo), TicketPost::alertRecipients(
            $this->config(array('acctManager' => true)), $padre, null, $hijo));
    }

    /* ------------------------------------------------------------------ */
    /* Quién puede recibir el aviso de verdad                              */
    /* ------------------------------------------------------------------ */

    public function testFueraLosAgentesSinCorreoYLosNoDisponibles(): void {
        $ok = $this->agente('ok@example.com');
        $lista = array(
            null,
            $this->agente(null),
            $this->agente('vacaciones@example.com', false),
            $ok,
        );

        $this->assertSame(array($ok),
            TicketPost::alertableRecipients($lista));
    }

    public function testLosRepetidosSeComparanPorDireccion(): void {
        $ana = $this->agente('ana@example.com');
        $otraVezAna = $this->agente('ana@example.com');
        $beto = $this->agente('beto@example.com');

        $this->assertSame(array($ana, $beto),
            TicketPost::alertableRecipients(array($ana, $otraVezAna, $beto)));
    }

    public function testSinListaNoQuedaNadie(): void {
        $this->assertSame(array(), TicketPost::alertableRecipients(array()));
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

class TicketPostFakeConfig {
    private $opciones;

    function __construct(array $opciones) {
        $this->opciones = $opciones;
    }

    private function activa($clave) {
        return !empty($this->opciones[$clave]);
    }

    function alertLastRespondentONNewMessage() {
        return $this->activa('lastRespondent');
    }

    function alertAssignedONNewMessage() {
        return $this->activa('assigned');
    }

    function alertDeptManagerONNewMessage() {
        return $this->activa('deptManager');
    }

    function alertAcctManagerONNewMessage() {
        return $this->activa('acctManager');
    }
}

class TicketPostFakeStaff {
    private $direccion;
    private $disponible;

    function __construct($direccion, $disponible = true) {
        $this->direccion = $direccion;
        $this->disponible = $disponible;
    }

    function getEmail() {
        return $this->direccion;
    }

    function isAvailable() {
        return $this->disponible;
    }
}

class TicketPostFakeTeam {
    private $miembros;

    function __construct(array $miembros) {
        $this->miembros = $miembros;
    }

    function getMembersForAlerts() {
        return $this->miembros;
    }
}

class TicketPostFakeOrg {
    private $gestor;

    function __construct($gestor) {
        $this->gestor = $gestor;
    }

    function getAccountManager() {
        return $this->gestor;
    }
}

class TicketPostFakeUser {
    private $org;

    function __construct($org) {
        $this->org = $org;
    }

    function getOrganization() {
        return $this->org;
    }
}

class TicketPostFakeTicket {
    private $opciones;

    function __construct(array $opciones = array()) {
        $this->opciones = $opciones;
    }

    function getLastRespondent() {
        return $this->opciones['lastRespondent'] ?? null;
    }

    function isAssigned() {
        return !empty($this->opciones['staff']) || !empty($this->opciones['team']);
    }

    function getStaff() {
        return $this->opciones['staff'] ?? null;
    }

    function getTeam() {
        return $this->opciones['team'] ?? null;
    }

    function getOwner() {
        return new TicketPostFakeUser(
            new TicketPostFakeOrg($this->opciones['accountManager'] ?? null));
    }
}

class TicketPostFakeDept {
    private $jefe;

    function __construct($jefe) {
        $this->jefe = $jefe;
    }

    function getManager() {
        return $this->jefe;
    }
}
