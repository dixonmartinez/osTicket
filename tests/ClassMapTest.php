<?php
/**
 * tests/ClassMapTest.php
 *
 * Pruebas de `tools/quality/class-map.php` (frente 2 de docs/CODE_QUALITY.md).
 *
 * La herramienta sirve para repartir archivos con muchas clases sin perder
 * ninguna y para detectar dos archivos que declaren la misma clase (error
 * fatal según el orden de carga que `make lint` no ve, porque cada archivo
 * es válido por separado).
 *
 * Se prueba el analizador, no la salida por pantalla: el archivo se incluye
 * con la salida capturada porque empieza por una línea `#!` que en CLI
 * consume el intérprete y al incluirla se imprimiría.
 */
ob_start();
require_once dirname(__DIR__) . '/tools/quality/class-map.php';
ob_end_clean();

class ClassMapTest extends UnitTestCase {

    private function names($source) {
        return array_keys(ClassMap::classesIn($source));
    }

    /* ------------------------------------------------------------------ */
    /* Qué es una declaración                                              */
    /* ------------------------------------------------------------------ */

    public function testReconoceUnaClase(): void {
        $this->assertSame(['Hola'],
            $this->names('<?php class Hola { }'));
    }

    public function testReconoceAbstractasInterfacesYRasgos(): void {
        $src = '<?php abstract class A { } interface B { } trait C { }';
        $this->assertSame(['A', 'B', 'C'], $this->names($src));
    }

    public function testIgnoraLaConstanteClass(): void {
        // Foo::class es una cadena, no una declaración
        $src = '<?php $x = Hola::class; class Hola { }';
        $this->assertSame(['Hola'], $this->names($src));
    }

    public function testIgnoraLasClasesAnonimas(): void {
        $src = '<?php $x = new class { function a() {} }; class Real { }';
        $this->assertSame(['Real'], $this->names($src));
    }

    public function testNoSeConfundeConTextoEnCadenas(): void {
        $src = '<?php $x = "class Falsa { }"; class Real { }';
        $this->assertSame(['Real'], $this->names($src));
    }

    public function testApuntaLaLinea(): void {
        $src = "<?php\n\nclass Tarde {\n}\n";
        $info = ClassMap::classesIn($src);
        $this->assertSame(3, $info['Tarde']['line']);
    }

    /* ------------------------------------------------------------------ */
    /* Namespaces                                                          */
    /* ------------------------------------------------------------------ */

    public function testElNamespaceFormaParteDelNombre(): void {
        $src = '<?php namespace osTicket\\Session { class Exception { } }';
        $this->assertSame(['osTicket\\Session\\Exception'], $this->names($src));
    }

    public function testElNamespaceConPuntoYComa(): void {
        $src = '<?php namespace osTicket\\Mail; class Message { }';
        $this->assertSame(['osTicket\\Mail\\Message'], $this->names($src));
    }

    public function testDosNamespacesEnElMismoArchivo(): void {
        $src = '<?php namespace A { class X { } } namespace B { class X { } }';
        $this->assertSame(['A\\X', 'B\\X'], $this->names($src));
    }

    /* ------------------------------------------------------------------ */
    /* Polyfills                                                           */
    /* ------------------------------------------------------------------ */

    public function testMarcaLaDeclaracionProtegida(): void {
        $src = '<?php if (!class_exists("Exception")) { class Exception { } }';
        $info = ClassMap::classesIn($src);
        $this->assertTrue($info['Exception']['conditional']);
    }

    public function testUnaDeclaracionNormalNoEsCondicional(): void {
        $info = ClassMap::classesIn('<?php class Normal { }');
        $this->assertFalse($info['Normal']['conditional']);
    }

    /* ------------------------------------------------------------------ */
    /* Duplicados                                                          */
    /* ------------------------------------------------------------------ */

    private function mapa(array $sitios) {
        $map = array();
        foreach ($sitios as $name => $lista)
            $map[$name] = $lista;
        return $map;
    }

    public function testDosDeclaracionesSueltasSonDuplicado(): void {
        $map = $this->mapa(array(
            'Ticket' => array(
                array('file' => 'a.php', 'line' => 1, 'conditional' => false),
                array('file' => 'b.php', 'line' => 2, 'conditional' => false),
            ),
        ));

        $this->assertSame(array('Ticket'), array_keys(ClassMap::duplicates($map)));
    }

    public function testUnaSolaDeclaracionNoEsDuplicado(): void {
        $map = $this->mapa(array(
            'Ticket' => array(array('file' => 'a.php', 'line' => 1,
                'conditional' => false)),
        ));

        $this->assertSame(array(), ClassMap::duplicates($map));
    }

    public function testUnPolyfillNoEsDuplicado(): void {
        // La clase real y el doble protegido con class_exists() conviven
        $map = $this->mapa(array(
            'Ticket' => array(
                array('file' => 'include/class.ticket.php', 'line' => 40,
                    'conditional' => false),
                array('file' => 'tests/ThreadEmailTest.php', 'line' => 179,
                    'conditional' => true),
            ),
        ));

        $this->assertSame(array(), ClassMap::duplicates($map));
        $this->assertSame(array('Ticket'), array_keys(ClassMap::guarded($map)));
    }

    public function testDosSueltasGananAlPolyfill(): void {
        $map = $this->mapa(array(
            'Ticket' => array(
                array('file' => 'a.php', 'line' => 1, 'conditional' => false),
                array('file' => 'b.php', 'line' => 2, 'conditional' => false),
                array('file' => 'c.php', 'line' => 3, 'conditional' => true),
            ),
        ));

        $duplicados = ClassMap::duplicates($map);
        $this->assertSame(2, count($duplicados['Ticket']));
        $this->assertSame(array(), ClassMap::guarded($map));
    }

    /* ------------------------------------------------------------------ */
    /* Reparto por archivo                                                 */
    /* ------------------------------------------------------------------ */

    public function testCuentaLasClasesPorArchivo(): void {
        $map = $this->mapa(array(
            'A' => array(array('file' => 'uno.php', 'line' => 1,
                'conditional' => false)),
            'B' => array(array('file' => 'uno.php', 'line' => 5,
                'conditional' => false)),
            'C' => array(array('file' => 'dos.php', 'line' => 1,
                'conditional' => false)),
        ));

        $porArchivo = ClassMap::byFile($map);
        $this->assertSame(2, count($porArchivo['uno.php']));
        $this->assertSame(1, count($porArchivo['dos.php']));
        // el que más clases tiene va primero
        $this->assertSame('uno.php', array_keys($porArchivo)[0]);
    }
}
