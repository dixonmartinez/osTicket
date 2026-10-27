<?php
/**
 * tests/CodeMeasureTest.php
 *
 * Pruebas del medidor de funciones de `make metrics`
 * (`tools/quality/measure.php`).
 *
 * Importan porque toda la radiografía de docs/CODE_QUALITY.md se apoya en
 * esta medida: si el medidor pierde una función o le suma líneas de más, el
 * plan de refactor se diseña sobre datos falsos. Ya pasó dos veces (llaves
 * dentro de cadenas y funciones anidadas).
 */
ob_start();
require_once dirname(__DIR__) . '/tools/quality/measure.php';
ob_end_clean();

class CodeMeasureTest extends UnitTestCase {

    private function medidas($source) {
        $out = array();
        foreach (CodeMeasure::functionsIn($source) as $fn)
            $out[$fn['name']] = $fn['lines'];
        return $out;
    }

    public function testMideUnaFuncionSimple(): void {
        $src = "<?php\nfunction hola() {\n    return 1;\n}\n";

        $this->assertSame(array('hola' => 3), $this->medidas($src));
    }

    public function testMideDosFuncionesSeguidas(): void {
        $src = "<?php\nfunction a() {\n}\nfunction b() {\n    return 1;\n}\n";

        $this->assertSame(array('a' => 2, 'b' => 3), $this->medidas($src));
    }

    public function testUnaLlaveEnUnaCadenaNoDescuadra(): void {
        // Esto hacía que las funciones siguientes se midieran mal
        $src = "<?php\nfunction a() {\n    return 'texto } con llave';\n}\n"
            . "function b() {\n}\n";

        $this->assertSame(array('a' => 3, 'b' => 2), $this->medidas($src));
    }

    public function testUnaInterpolacionNoDescuadra(): void {
        $src = "<?php\nfunction a(\$x) {\n    return \"valor {\$x} y \${x}\";\n}\n";

        $this->assertSame(array('a' => 3), $this->medidas($src));
    }

    public function testUnHeredocNoDescuadra(): void {
        $src = "<?php\nfunction a() {\n    return <<<TXT\nhola } mundo\nTXT;\n}\n";

        $this->assertSame(array('a' => 5), $this->medidas($src));
    }

    public function testUnaExpresionRegularNoDescuadra(): void {
        $src = "<?php\nfunction a() {\n    return preg_match('/{2,}/', 'x');\n}\n";

        $this->assertSame(array('a' => 3), $this->medidas($src));
    }

    public function testUnCierreDentroDeUnMetodoNoTapaAlMetodo(): void {
        // El método sigue midiéndose aunque su cierre se procese antes
        $src = "<?php\nclass A {\n    function grande() {\n"
            . "        \$f = function (\$x) {\n            return \$x;\n        };\n"
            . "        return \$f(1);\n    }\n}\n";

        $medidas = $this->medidas($src);

        $this->assertSame(6, $medidas['grande']);
        $this->assertSame(3, $medidas['closure']);
    }

    public function testUnMetodoAbstractoNoTapaAlSiguiente(): void {
        $src = "<?php\nabstract class A {\n    abstract function sinCuerpo();\n"
            . "    function conCuerpo() {\n    }\n}\n";

        $this->assertSame(array('conCuerpo' => 2), $this->medidas($src));
    }

    public function testCuentaUnMetodoConTipoDeRetorno(): void {
        $src = "<?php\nclass A {\n    function f(): array {\n        return array();\n    }\n}\n";

        $this->assertSame(array('f' => 3), $this->medidas($src));
    }

    public function testIgnoraUseFunction(): void {
        // `use function` importa, no declara
        $src = "<?php\nuse function Foo\\bar;\nfunction propia() {\n}\n";

        $this->assertSame(array('propia' => 2), $this->medidas($src));
    }

    public function testMideUnaFuncionConHtmlIntercalado(): void {
        $src = "<?php\nfunction render() {\n    ?><div>{nada}</div><?php\n}\n";

        $this->assertSame(array('render' => 3), $this->medidas($src));
    }

    public function testUnArchivoSinFuncionesNoDevuelveNada(): void {
        $this->assertSame(array(), $this->medidas("<?php\n\$a = 1;\n"));
    }

    /**
     * El listón del frente 6: ningún archivo propio pasa de 2.000 líneas.
     *
     * La regla se cumplió y **se volvió a romper sin que nadie lo notara**:
     * `seed.php` llegó a 2.157 y `queue.php` a 2.065 cuando se les añadieron
     * la revisión del sembrador y el informe del diagnóstico. Una regla que
     * sólo vive en la documentación no se cumple; esta prueba la mide, con el
     * mismo criterio que `make metrics` (fuera `vendor` y las librerías
     * empaquetadas).
     */
    public function testNingunArchivoPropioPasaDe2000Lineas(): void {
        $grandes = array();
        $skip = array('vendor', 'mpdf', 'pear', 'laminas-mail', 'oauth2',
            'plugins');

        foreach (array('include', 'scp', 'api', 'setup', 'tools') as $area) {
            $base = ROOT_DIR . '/' . $area;
            if (!is_dir($base))
                continue;

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base,
                    FilesystemIterator::SKIP_DOTS));

            foreach ($it as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php')
                    continue;

                $path = $file->getPathname();
                foreach ($skip as $dir)
                    if (strpos($path, DIRECTORY_SEPARATOR . $dir
                            . DIRECTORY_SEPARATOR) !== false)
                        continue 2;

                $lines = count(file($path));
                if ($lines > 2000)
                    $grandes[substr($path, strlen(ROOT_DIR) + 1)] = $lines;
            }
        }

        arsort($grandes);
        $this->assertSame(array(), $grandes,
            'el frente 6 pide partir los archivos de más de 2.000 líneas: '
            . print_r($grandes, true));
    }

    public function testApuntaLaLineaDeInicio(): void {
        $src = "<?php\n\n\nfunction tarde() {\n}\n";
        $fns = CodeMeasure::functionsIn($src);

        $this->assertSame(4, $fns[0]['line']);
    }
}
