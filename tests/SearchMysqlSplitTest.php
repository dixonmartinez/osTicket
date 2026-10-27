<?php
/**
 * tests/SearchMysqlSplitTest.php
 *
 * El backend de búsqueda de MySQL (`MysqlSearchBackend` y su configuración)
 * vive en `include/class.search.mysql.php` desde la tanda del frente 6 del
 * 2026-10-27.
 *
 * `include/class.search.php` tenía 1.955 líneas y esta clase sola ocupaba 480.
 * Se movieron las dos clases enteras, sin tocar su código, y con ellas el
 * registro del backend, que tiene que seguir ocurriendo al cargar el archivo.
 *
 * Aquí se fija lo que un movimiento así puede romper:
 *
 *   - que el archivo se incluya **al final** de `class.search.php`: allí
 *     `MysqlSearchBackend` hereda de `SearchBackend`, que se queda en el
 *     original — incluirlo antes sería un error fatal;
 *   - que las dos clases estén una sola vez y no queden copias detrás (PHP no
 *     avisa: la copia vieja taparía la del archivo nuevo);
 *   - que el registro (`Signal::connect()` y `register()`) se haya movido con
 *     ellas: sin eso, el buscador deja de existir sin dar ningún error;
 *   - que el archivo original siga con margen bajo el tope del frente 6.
 *
 * No hay base de datos ni se carga la clase: se revisa el código fuente.
 */
class SearchMysqlSplitTest extends UnitTestCase {

    private function search(): string {
        $path = INCLUDE_DIR . 'class.search.php';
        $this->assertTrue(is_file($path), 'falta include/class.search.php');

        return (string) file_get_contents($path);
    }

    private function backend(): string {
        $path = INCLUDE_DIR . 'class.search.mysql.php';
        $this->assertTrue(is_file($path), 'falta include/class.search.mysql.php');

        return (string) file_get_contents($path);
    }

    /** Cuántas veces se declara `class $name` en un texto. */
    private function declaraciones(string $source, string $name): int {
        return preg_match_all('/^\s*(?:abstract\s+)?class\s+' . preg_quote($name, '/')
            . '\b/m', $source);
    }

    /** Se incluye al final del original, después de lo que hereda. */
    public function testSeIncluyeAlFinalYDespuesDeSuPadre(): void {
        $search = $this->search();

        $this->assertStringContainsString(
            "include_once(INCLUDE_DIR . 'class.search.mysql.php');", $search);
        $this->assertStringContainsString('class MysqlSearchBackend extends SearchBackend',
            $this->backend());

        // El `include_once` va después de la clase de la que hereda y después
        // del otro reparto (`class.search.fields.php`)
        $include = strpos($search, 'class.search.mysql.php');
        $this->assertTrue($include > strpos($search, 'abstract class SearchBackend'),
            'se incluye antes de existir SearchBackend');
        $this->assertTrue($include > strpos($search, 'class.search.fields.php'),
            'se incluye antes del reparto de los campos');
    }

    /** Las dos clases están en el archivo nuevo, y sólo ahí. */
    public function testLasClasesSeMovieronYNoEstanDuplicadas(): void {
        foreach (array('MysqlSearchBackend', 'MySqlSearchConfig') as $clase) {
            $this->assertSame(1, $this->declaraciones($this->backend(), $clase),
                sprintf('«%s» no está en class.search.mysql.php (o está dos veces)',
                    $clase));
            $this->assertSame(0, $this->declaraciones($this->search(), $clase),
                sprintf('«%s» sigue en class.search.php', $clase));
        }
    }

    /** El registro del backend se fue con las clases. */
    public function testElRegistroSeMovioConLasClases(): void {
        $backend = $this->backend();

        $this->assertStringContainsString(
            "Signal::connect('system.install',", $backend);
        $this->assertStringContainsString('MysqlSearchBackend::register();', $backend);
        $this->assertTrue(
            strpos($backend, 'MysqlSearchBackend::register();')
                > strpos($backend, 'class MysqlSearchBackend'),
            'se registra antes de estar declarado');

        // Y no quedaron en el original
        $this->assertTrue(strpos($this->search(),
            'MysqlSearchBackend::register();') === false,
            'el registro sigue en class.search.php');
    }

    /** El código se movió entero: se reconocen sus piezas. */
    public function testElCodigoSeMovioEntero(): void {
        $backend = $this->backend();

        // La tabla FULLTEXT y el índice por lotes
        $this->assertStringContainsString('static $BATCH_SIZE', $backend);
        $this->assertStringContainsString('CREATE TABLE', $backend);
        // Los cinco tipos del índice
        foreach (array('indexThreadEntries', 'indexTickets', 'indexUsers',
                'indexOrganizations', 'indexFaqs') as $metodo)
            $this->assertStringContainsString("function $metodo(", $backend);
    }

    /** El archivo original se queda con margen bajo el tope. */
    public function testElArchivoOriginalBaja(): void {
        $lineas = count(file(INCLUDE_DIR . 'class.search.php'));

        $this->assertTrue($lineas < 1500,
            sprintf('class.search.php tiene %d líneas (tope 1.500)', $lineas));
    }
}
