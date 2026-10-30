<?php
/**
 * tests/integration/TicketCreateIntegrationTest.php
 *
 * El alta de un ticket, de punta a punta y contra MariaDB de verdad, por los
 * **dos caminos** que tiene la aplicación:
 *
 *   portal del cliente   `open.php`                  → `Ticket::create()`
 *   panel del agente     `scp/tickets.php?a=open`     → `Ticket::open()`
 *
 * `tests/TicketCreateTest.php` ya fija las reglas puras (qué campos exige cada
 * origen, qué se valida al abrir desde el panel). Lo que no se puede comprobar
 * sin base de datos es lo demás: que el ticket se guarde, que el tema de ayuda
 * decida el departamento, que el primer mensaje quede en el hilo, que el
 * número salga de la secuencia y que un agente sin permiso **no** cree nada.
 *
 * Cada caso se ejecuta en un proceso aparte (`tests/support/create-ticket.php`)
 * porque `Ticket::create()` necesita el entorno completo de la aplicación. El
 * ayudante monta la base desechable, siembra los datos del instalador por el
 * mismo camino que `setup/inc/class.installer.php` y devuelve un JSON con lo
 * que ha pasado. Así esta prueba no deja el traductor, la sesión ni el
 * manejador de errores de osTicket activos para el resto de la suite.
 *
 * Se ejecuta con `make integration` (o `make docker-integration`, que levanta
 * la base). Sin base de datos, se omite.
 */

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/IntegrationTestCase.php';

class TicketCreateIntegrationTest extends IntegrationTestCase {

    /** La base la deja lista el ayudante; aquí no hay nada que preparar. */
    protected function beforeEach(): void {
    }

    /**
     * Ejecuta el ayudante en su propio proceso y devuelve su respuesta.
     *
     * El JSON va en la última línea: la aplicación apaga `display_errors`, así
     * que lo que llegue antes (si llega algo) es ruido de arranque, y se
     * conserva en el mensaje de error para poder diagnosticar.
     */
    private function crear($modo) {
        $comando = sprintf('%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY ?: 'php'),
            escapeshellarg(ROOT_DIR . 'tests/support/create-ticket.php'),
            escapeshellarg($modo));

        $lineas = array();
        $codigo = 0;
        exec($comando, $lineas, $codigo);

        $salida = trim(implode("\n", $lineas));
        $this->assertSame(0, $codigo,
            sprintf("el ayudante «%s» falló (código %d):\n%s", $modo, $codigo,
                $salida));

        $datos = null;
        foreach (array_reverse($lineas) as $linea) {
            $linea = trim($linea);
            if ($linea !== '' && $linea[0] === '{') {
                $datos = json_decode($linea, true);
                break;
            }
        }

        $this->assertTrue(is_array($datos),
            sprintf("respuesta inesperada del ayudante «%s»:\n%s", $modo,
                $salida));

        if (isset($datos['motivo']))
            $this->assertTrue(false, sprintf('el ayudante «%s» no arrancó: %s',
                $modo, $datos['motivo']));

        return $datos;
    }

    /** El ticket recién creado, tal como está en la base. */
    private function ticketEnLaBase($id) {
        $filas = $this->rows(sprintf(
            'SELECT t.ticket_id, t.number, t.user_id, t.dept_id, t.topic_id,'
            . ' t.status_id, t.source, t.thread_id, t.created'
            . ' FROM `%s` t WHERE t.ticket_id = %d',
            $this->table('ticket'), (int) $id));

        $this->assertCount(1, $filas, 'el ticket no está en la tabla');

        return $filas[0];
    }

    /* ------------------------------------------------------------------ */
    /* Portal del cliente                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * `open.php` no elige departamento, estado ni prioridad: los pone el tema
     * de ayuda. Es lo que hay que ver aquí, además de que el hilo tenga el
     * mensaje del cliente como entrada original.
     */
    public function testElPortalCreaElTicketConElDepartamentoDelTema(): void {
        $r = $this->crear('portal');

        $this->assertTrue($r['ok'],
            'el portal no creó el ticket: '
            . json_encode(isset($r['err']) ? $r['err'] : $r));

        $ticket = $this->ticketEnLaBase($r['id']);

        $this->assertSame('Web', $ticket['source']);
        $this->assertSame($r['number'], $ticket['number']);
        $this->assertSame('cliente@example.test', $r['user_email']);
        $this->assertSame('Cliente De Prueba', $r['user_name']);

        // El departamento sale del tema, no de la petición (que no lo manda).
        $deptDelTema = $this->value(sprintf(
            'SELECT dept_id FROM `%s` WHERE topic_id = %d',
            $this->table('help_topic'), (int) $ticket['topic_id']));
        $this->assertSame((int) $deptDelTema, (int) $ticket['dept_id'],
            'el departamento del ticket no es el del tema de ayuda');

        $this->assertGreaterThan(0, (int) $ticket['status_id'],
            'el ticket quedó sin estado');
        $this->assertNotNull($ticket['created'],
            'el ticket quedó sin fecha de creación');

        // El mensaje del cliente es la entrada original del hilo.
        $this->assertSame(1, (int) $this->value(sprintf(
            'SELECT COUNT(*) FROM `%s` WHERE thread_id = %d AND poster = %s'
            . ' AND title = %s',
            $this->table('thread_entry'), (int) $ticket['thread_id'],
            $this->cita('Cliente De Prueba'),
            $this->cita('No puedo entrar en mi cuenta'))));
    }

    /* ------------------------------------------------------------------ */
    /* Panel del agente                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * `Ticket::open()` acepta el departamento elegido y guarda el origen de la
     * petición; después llama a `Ticket::create()` con origen «staff», que
     * relaja la validación de los campos de contacto porque el cliente lo da
     * el agente.
     */
    public function testElPanelDelAgenteCreaElTicketEnElDepartamentoElegido(): void {
        $r = $this->crear('staff');

        $this->assertTrue($r['ok'],
            'el panel no creó el ticket: '
            . json_encode(isset($r['err']) ? $r['err'] : $r));

        $ticket = $this->ticketEnLaBase($r['id']);

        $this->assertSame('Phone', $ticket['source']);
        $this->assertSame((int) $r['dept_id'], (int) $ticket['dept_id']);
        $this->assertSame($r['number'], $ticket['number']);
        $this->assertSame('cliente@example.test', $r['user_email']);

        // El alta deja el evento «created» en el historial del hilo.
        $this->assertSame(1, (int) $this->value(sprintf(
            'SELECT COUNT(*) FROM `%s` te JOIN `%s` e ON e.id = te.event_id'
            . ' WHERE te.thread_id = %d AND e.name = %s',
            $this->table('thread_event'), $this->table('event'),
            (int) $ticket['thread_id'], $this->cita('created'))),
            'el alta no dejó el evento «created» del ticket');
    }

    /**
     * El permiso se comprueba **antes** de crear nada: un agente cuyo rol no
     * incluye `ticket.create` recibe el error y la tabla queda vacía. Es el
     * caso que protegería de una escalada de privilegios por POST directo.
     */
    public function testSinPermisoDeCreacionNoSeCreaNada(): void {
        $r = $this->crear('staff-sin-permiso');

        $this->assertFalse($r['ok'], 'sin permiso se creó el ticket igualmente');
        $this->assertSame(0, (int) $r['tickets'],
            'quedó un ticket en la base después de negarse');
        $this->assertTrue(!empty($r['err']), 'no se explicó el motivo');
        $this->assertStringContainsString('permission', strtolower($r['err']));
    }

    /** Cadena entre comillas para el SQL de las comprobaciones. */
    private function cita($valor) {
        return "'" . str_replace("'", "''", (string) $valor) . "'";
    }
}
