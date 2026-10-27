<?php
/**
 * tests/FileManagerTest.php
 *
 * `manage.php file …` (`include/cli/modules/file.php`): lista, exporta,
 * importa, comprime y migra adjuntos.
 *
 * Lo que se fija aquí:
 *   - `run()` es un despachador, no un `switch` de 400 líneas (frente 2 de
 *     docs/CODE_QUALITY.md): cada acción es un método;
 *   - **toda** acción que anuncia la ayuda tiene su método — si se añade una
 *     al listado y no al código, el comando fallaría en ejecución y no en la
 *     suite;
 *   - una acción inventada se para con un mensaje, en vez de terminar en
 *     silencio con código 0 (el `switch` no tenía `default`);
 *   - las cinco copias de «archivos que cumplen el criterio» y las dos de
 *     «el archivo único» son una sola función.
 *
 * No hay base de datos: se revisa el código fuente y la tabla de acciones.
 */

class FileManagerTest extends UnitTestCase {

    private function source(): string {
        return (string) file_get_contents(ROOT_DIR
            . '/include/cli/modules/file.php');
    }

    /** Acciones que anuncia la ayuda del módulo. */
    private function declaredActions(): array {
        $source = $this->source();
        $start = strpos($source, "'action' => array(");
        $end = strpos($source, '),', $start);
        $block = substr($source, $start, $end - $start);

        preg_match_all("/'(\w+)' => '[^']*'/", $block, $m);

        // La misma lista lleva la ayuda del argumento (`'help' => 'Action to
        // be performed'`), que no es una acción
        return array_values(array_diff($m[1], array('help')));
    }

    /**
     * `run()` no vuelve a crecer: despacha y nada más.
     *
     * La versión anterior tenía las nueve acciones en un `switch` de 408
     * líneas; mover una acción obligaba a leer las otras ocho.
     */
    public function testRunEsUnDespachadorCorto(): void {
        $source = $this->source();
        $start = strpos($source, 'function run($args, $options) {');
        $this->assertTrue($start !== false, 'falta run()');
        $end = strpos($source, "\n    function ", $start + 10);
        $body = substr($source, $start, $end - $start);

        $this->assertStringContainsString(
            "\$method = 'action' . ucfirst(\$args['action']);", $body);
        $this->assertStringContainsString('return $this->$method($options);', $body);
        $this->assertSame(false, strpos($body, 'switch ('),
            'las acciones no vuelven al switch de run()');
        $this->assertTrue(substr_count($body, "\n") < 25,
            'run() tiene que caber en una pantalla');
    }

    /** Cada acción del listado tiene su método (y no falta ninguna). */
    public function testTodasLasAccionesAnunciadasTienenSuMetodo(): void {
        $source = $this->source();
        $actions = $this->declaredActions();

        $this->assertSame(array('list', 'export', 'import', 'zip', 'dump',
            'load', 'migrate', 'backends', 'expunge'), $actions);

        foreach ($actions as $action) {
            $method = 'action' . ucfirst($action);
            $this->assertStringContainsString("function $method(",
                $source, "la acción `$action` no tiene $method()");
        }
    }

    /**
     * Una acción que no existe se dice, no se ignora.
     *
     * Antes el `switch` no tenía `default`: `manage.php file inventada`
     * terminaba con código 0 y sin una línea de salida.
     */
    public function testUnaAccionInventadaSeParaConUnMensaje(): void {
        $source = $this->source();

        $this->assertStringContainsString('method_exists($this, $method)', $source);
        $this->assertStringContainsString('unknown action', $source);
    }

    /** Criterio y archivo único: una sola función, no siete copias. */
    public function testElCriterioYElArchivoUnicoEstanEnUnSitio(): void {
        $source = $this->source();

        $this->assertSame(1, substr_count($source,
            '$this->_applyCriteria($options, $files);'),
            'el criterio se aplica en un solo sitio (matchingFiles)');
        $this->assertSame(1, substr_count($source, '->one();'),
            'el archivo único se pide en un solo sitio (oneFile)');

        // Y las acciones que lo usan pasan por ahí
        foreach (array('actionList', 'actionDump', 'actionLoad', 'actionMigrate',
            'actionExport', 'actionZip', 'actionExpunge') as $method) {
            $start = strpos($source, "function $method(");
            $this->assertTrue($start !== false, "falta $method()");
            $body = substr($source, $start, strpos($source, "\n    function ",
                $start + 10) - $start);
            $this->assertTrue(strpos($body, 'matchingFiles(') !== false
                || strpos($body, 'oneFile(') !== false,
                "$method tiene que usar matchingFiles() u oneFile()");
        }
    }
}
