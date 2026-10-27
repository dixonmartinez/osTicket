<?php
/**
 * tests/TicketsAjaxSplitTest.php
 *
 * Cambiar el estado y marcar tickets viven en `include/ajax.tickets.status.php`
 * (rasgo `TicketsAjaxStatus`) desde la tanda del frente 6 del 2026-10-27.
 *
 * `include/ajax.tickets.php` era el archivo más grande del panel (1.677
 * líneas). Los cinco métodos que comparten el formulario de estado se
 * movieron enteros, sin tocar su código, a un rasgo de la misma clase: la API
 * los sigue llamando por su nombre, así que las rutas del panel no cambian.
 *
 * Aquí se fija lo que un movimiento así puede romper:
 *
 *   - que el rasgo se cargue **antes** de la clase y se use (sin el `use`,
 *     `setTicketStatus()` desaparece y la pantalla da 404);
 *   - que estén **todos** los métodos movidos y **no** queden copias en el
 *     archivo original (PHP no avisa: una copia vieja taparía la del rasgo);
 *   - que `_changeTicketStatus()`, que el rasgo llama con `self::`, siga en la
 *     clase —es privada, así que sólo se alcanza desde dentro;
 *   - que el archivo original siga con margen bajo el tope del frente 6.
 *
 * No hay base de datos: se revisa el código fuente.
 */
class TicketsAjaxSplitTest extends UnitTestCase {

    /** Los métodos que viven en el rasgo. */
    const METODOS = array(
        'changeTicketStatus', 'setTicketStatus', 'changeSelectedTicketsStatus',
        'setSelectedTicketsStatus', 'markAs',
    );

    private function api(): string {
        $path = ROOT_DIR . '/include/ajax.tickets.php';
        $this->assertTrue(is_file($path), 'falta include/ajax.tickets.php');

        return (string) file_get_contents($path);
    }

    private function trait(): string {
        $path = ROOT_DIR . '/include/ajax.tickets.status.php';
        $this->assertTrue(is_file($path),
            'falta include/ajax.tickets.status.php');

        return (string) file_get_contents($path);
    }

    /** Cuántas veces se define `function $name(` en un texto. */
    private function definiciones(string $source, string $name): int {
        return preg_match_all('/function\s+' . preg_quote($name, '/') . '\s*\(/',
            $source);
    }

    /** El rasgo existe, se carga antes de la clase y se usa. */
    public function testElRasgoSeCargaYSeUsa(): void {
        $api = $this->api();

        $this->assertStringContainsString('trait TicketsAjaxStatus {',
            $this->trait());
        $this->assertStringContainsString('use TicketsAjaxStatus;', $api);

        // El `include_once` tiene que estar antes de `class TicketsAjaxAPI`
        $carga = strpos($api, 'ajax.tickets.status.php');
        $this->assertTrue($carga !== false, 'no se carga el rasgo');
        $this->assertTrue($carga < strpos($api, 'class TicketsAjaxAPI'),
            'el rasgo se carga después de la clase');
    }

    /** Los cinco métodos están en el rasgo, y sólo ahí. */
    public function testLosMetodosSeMovieronYNoEstanDuplicados(): void {
        $trait = $this->trait();
        $api = $this->api();

        foreach (self::METODOS as $metodo) {
            $this->assertSame(1, $this->definiciones($trait, $metodo),
                sprintf('«%s» no está en el rasgo (o está dos veces)', $metodo));
            $this->assertSame(0, $this->definiciones($api, $metodo),
                sprintf('«%s» sigue en ajax.tickets.php', $metodo));
        }
    }

    /**
     * Los dos ayudantes privados se quedan en la clase: son privados y el
     * rasgo los llama con `self::`, que apunta a la clase que usa el rasgo.
     */
    public function testLosAyudantesPrivadosSiguenEnLaClase(): void {
        $api = $this->api();

        foreach (array('_changeTicketStatus', '_changeSelectedTicketsStatus')
                as $ayudante) {
            $this->assertSame(1, $this->definiciones($api, $ayudante));
            $this->assertSame(0, $this->definiciones($this->trait(), $ayudante));
        }
        $this->assertStringContainsString('private static function _changeTicketStatus',
            $api);
        $this->assertStringContainsString('private static function _changeSelectedTicketsStatus',
            $api);
        // Tres llamadas: setTicketStatus, setSelectedTicketsStatus y su uso
        // interno desde el propio ayudante
        $this->assertTrue(substr_count($this->trait(),
            'self::_changeTicketStatus') >= 2,
            'el rasgo ya no llama al ayudante de la clase');
    }

    /** Los métodos conservan el código: se reconoce lo que hacían. */
    public function testElCodigoSeMovioEntero(): void {
        $trait = $this->trait();

        // Cambiar el estado usa `TicketStatus` (y avisa si ya estaba así)
        $this->assertStringContainsString('TicketStatus::lookup', $trait);
        $this->assertStringContainsString('Ticket already set to %s status',
            $trait);
        // Marcar usa su propio formulario
        $this->assertStringContainsString('MarkAsForm', $trait);
        // «Ticket marked as %s successfully» era el mensaje de marcar
        $this->assertStringContainsString('Ticket marked as %s successfully',
            $trait);
        // La confirmación de borrado vive en el ayudante de la clase
        $this->assertStringContainsString('Deleted tickets CANNOT be recovered',
            $this->api());
    }

    /** El archivo original se queda con margen bajo el tope. */
    public function testElArchivoOriginalBaja(): void {
        $lineas = count(file(ROOT_DIR . '/include/ajax.tickets.php'));

        $this->assertTrue($lineas < 1400,
            sprintf('ajax.tickets.php tiene %d líneas (tope 1.400)', $lineas));
    }
}
