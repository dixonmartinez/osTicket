<?php
/**
 * tests/TicketSlaTest.php
 *
 * Vencimientos y planes de servicio del ticket (`include/class.ticket.sla.php`).
 *
 * Los métodos se sacaron de `include/class.ticket.php` en la tanda del frente 6
 * (archivos por debajo de las 2.000 líneas) sin tocar su código: son un rasgo
 * de `Ticket` porque trabajan sobre el propio modelo. Aquí se fija lo que un
 * movimiento así puede romper:
 *
 *   - que el rasgo se cargue y se use (si falta el `use`, `Ticket` se queda sin
 *     `getDueDate()` y todo lo que lo llama revienta en ejecución);
 *   - que **no** queden definiciones duplicadas: PHP no avisa, y una copia
 *     vieja en `class.ticket.php` taparía la del rasgo para siempre;
 *   - que estén **todos** los métodos que se movieron;
 *   - que el archivo original siga con margen bajo el tope del frente 6.
 *
 * No hay base de datos: se revisa el código fuente.
 */

class TicketSlaTest extends UnitTestCase {

    /** Los métodos que viven en el rasgo. */
    const METODOS = array(
        'isOverdue', 'getDueDate', 'getSLADueDate', 'updateEstDueDate',
        'getEstDueDate', 'setSLAId', 'selectSLAId', 'markOverdue',
        'clearOverdue', 'checkOverdue', 'duedateField',
    );

    private function ticket(): string {
        return (string) file_get_contents(ROOT_DIR . '/include/class.ticket.php');
    }

    private function trait(): string {
        $path = ROOT_DIR . '/include/class.ticket.sla.php';
        $this->assertTrue(is_file($path), 'falta include/class.ticket.sla.php');

        return (string) file_get_contents($path);
    }

    /** El rasgo existe, se carga antes de la clase y se usa. */
    public function testElRasgoSeCargaYSeUsa(): void {
        $ticket = $this->ticket();

        $this->assertStringContainsString(
            "include_once(INCLUDE_DIR.'class.ticket.sla.php');", $ticket);
        $this->assertStringContainsString('use TicketSla;', $ticket);

        // El `include_once` tiene que estar antes de `class Ticket`
        $this->assertTrue(strpos($ticket, 'class.ticket.sla.php')
            < strpos($ticket, 'class Ticket extends'), 'se carga después de la clase');

        $this->assertStringContainsString('trait TicketSla {', $this->trait());
    }

    /** Los once métodos están en el rasgo, y sólo ahí. */
    public function testLosMetodosSeMovieronYNoEstanDuplicados(): void {
        $trait = $this->trait();
        $ticket = $this->ticket();

        foreach (self::METODOS as $method) {
            $this->assertMatchesRegularExpression(
                '/\n\s*(static )?function ' . $method . '\(/', $trait,
                "$method() no está en el rasgo");
            $this->assertSame(1, substr_count($trait, "function $method("),
                "$method() está repetido en el rasgo");

            // Y no quedó la copia en el modelo: la del modelo taparía al rasgo
            $this->assertSame(0, substr_count($ticket, "function $method("),
                "$method() sigue definido en class.ticket.php");
        }
    }

    /**
     * Lo que el rasgo necesita del modelo sigue en el modelo.
     *
     * `getSLADueDate()` llama a `getSLA()`, `getDept()`, `getReopenDate()` y
     * `getCreateDate()`: si alguno se mueve también, la llamada se rompe sin
     * que PHP diga nada hasta la ejecución.
     */
    public function testElRasgoSoloUsaMetodosQueElModeloTiene(): void {
        $trait = $this->trait();
        $ticket = $this->ticket();

        preg_match_all('/\$this->(get\w+|is\w+|save|updateEstDueDate|clearOverdue)\(/',
            $trait, $m);

        foreach (array_unique($m[1]) as $method) {
            $this->assertTrue(
                strpos($trait, "function $method(") !== false
                || strpos($ticket, "function $method(") !== false
                || in_array($method, array('save')),
                "el rasgo llama a $method() y no está en el rasgo ni en el modelo");
        }
    }

    /**
     * El archivo del modelo queda con margen bajo el tope del frente 6.
     *
     * Estaba en 1.982 líneas cuando se movió esto; con el tope en 2.000, la
     * siguiente función que se añadiera lo pasaba.
     */
    public function testElModeloQuedaConMargenBajoElTope(): void {
        $lines = substr_count($this->ticket(), "\n");

        $this->assertTrue($lines < 1900,
            "class.ticket.php tiene $lines líneas: el margen bajo las 2.000 se "
            . 'ha ido (mueva otra familia de métodos a su rasgo)');
    }
}
