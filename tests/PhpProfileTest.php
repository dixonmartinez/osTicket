<?php
/**
 * tests/PhpProfileTest.php
 *
 * Pruebas del lector de perfiles de Xdebug (tools/quality/php-profile.php).
 *
 * Existe por una razón concreta: el perfilador por pasos ya sabe decir «este
 * paso es PHP, no SQL», pero no qué función se lleva el tiempo. Sin una prueba
 * del formato, la herramienta que contesta eso se rompe en silencio y el
 * informe miente.
 */

ob_start();
require_once dirname(__DIR__) . '/tools/quality/php-profile.php';
ob_end_clean();

class PhpProfileTest extends UnitTestCase {

    /** Un perfil mínimo, con la forma que escribe Xdebug 3. */
    private function profile() {
        return <<<CACHEGRIND
version: 1
creator: xdebug 3.3.0 (PHP 8.3.11)
cmd: php manage.php perf --ticket=428 --json
part: 1
positions: line
events: Time Memory

fl=(1) /app/include/class.thread.php
fn=(2) Thread::render
5 1000 0
9 500 0
cfl=(3) /app/include/class.format.php
cfn=(4) Format::display
calls=4 0 0
12 4000 0

fl=(3) /app/include/class.format.php
fn=(4) Format::display
40 4000 0
44 2500 0

fl=(5) /app/include/class.orm.php
fn=(6) QuerySet::compileSelect
100 250 0
CACHEGRIND;
    }

    function testLeeElTiempoPropioYNoLaLlamada() {
        $functions = PhpProfile::parse($this->profile());

        // 1000 + 500 de sus propias líneas; 4000 es la llamada, no suyo
        $render = PhpProfile::grep($functions, 'Thread::render');
        $this->assertSame(1, count($render));
        $info = reset($render);
        $this->assertEquals(1500, $info['time']);
    }

    function testLeeLaFuncionLlamadaPorSuNombre() {
        $functions = PhpProfile::parse($this->profile());
        $found = PhpProfile::grep($functions, 'Format::display');
        $display = reset($found);

        $this->assertEquals(6500, $display['time']);
        $this->assertSame('/app/include/class.format.php', $display['file']);
    }

    function testCuentaTodasLasFuncionesDelPerfil() {
        $this->assertSame(3, count(PhpProfile::parse($this->profile())));
    }

    function testOrdenaPorTiempoPropio() {
        $top = PhpProfile::top(PhpProfile::parse($this->profile()), 2);
        $this->assertTrue(strpos(key($top), 'Format::display') !== false);
    }

    function testElTotalSumaTodasLasFunciones() {
        $this->assertEquals(8250,
            PhpProfile::total(PhpProfile::parse($this->profile())));
    }

    function testElInformeMuestraPorcentajeYFuncion() {
        $text = PhpProfile::report(PhpProfile::parse($this->profile()), 3,
            '/app/');

        $this->assertTrue(strpos($text, 'Format::display') !== false);
        $this->assertTrue(strpos($text, '%') !== false);
        // La ruta del proyecto se recorta
        $this->assertTrue(strpos($text, '/app/include/class.format.php') === false);
        $this->assertTrue(strpos($text, 'include/class.format.php') !== false);
    }

    /** Los nombres comprimidos `(1)` se resuelven con la tabla del perfil. */
    function testResuelveLosNombresComprimidos() {
        $functions = PhpProfile::parse($this->profile());

        foreach ($functions as $key => $info)
            $this->assertFalse(strpos($key, '(1)') !== false,
                'Quedó un nombre sin resolver: ' . $key);
    }

    /** Un perfil sin datos no revienta: devuelve una lista vacía. */
    function testUnPerfilVacioNoRompeNada() {
        $this->assertSame(array(), PhpProfile::parse("version: 1\nevents: Time\n"));
        $this->assertSame(0.0, PhpProfile::total(array()));
    }

    /** El filtro sirve para quedarse con lo que se está investigando. */
    function testElFiltroBuscaEnFuncionYArchivo() {
        $functions = PhpProfile::parse($this->profile());

        $this->assertSame(1, count(PhpProfile::grep($functions, 'orm.php')));
        $this->assertSame(3, count(PhpProfile::grep($functions, 'include/')));
    }

    /**
     * Un patrón que no encuentra nada tiene que decirlo: «0 funciones» en
     * silencio hacía pensar que el perfil estaba vacío cuando el problema era
     * que Xdebug nunca escribió el archivo (o el glob no apuntaba a él).
     */
    function testAvisaDeLosPatronesQueNoEncuentranNada() {
        list($functions, $missing) = PhpProfile::load(
            array('/tmp/perfil-que-no-existe-*'));

        $this->assertSame(array(), $functions);
        $this->assertSame(array('/tmp/perfil-que-no-existe-*'), $missing);
    }

    /** Varios perfiles (o un comodín que expande a varios) se suman. */
    function testSumaVariosPerfiles() {
        $one = tempnam(sys_get_temp_dir(), 'cg') . '.out';
        $two = tempnam(sys_get_temp_dir(), 'cg') . '.out';
        file_put_contents($one, "fl=(1) /app/a.php\nfn=(2) A::b\n1 100 0\n");
        file_put_contents($two, "fl=(1) /app/a.php\nfn=(2) A::b\n1 50 0\n");

        list($functions, $missing) = PhpProfile::load(array($one, $two));

        $this->assertSame(array(), $missing);
        $found = reset($functions);
        $this->assertEquals(150, $found['time']);

        unlink($one);
        unlink($two);
    }

    /** Las duraciones se leen sin calculadora. */
    function testFormateaDuraciones() {
        $this->assertSame('500 µs', PhpProfile::duration(500));
        $this->assertSame('1.5 ms', PhpProfile::duration(1500));
        $this->assertSame('2.00 s', PhpProfile::duration(2000000));
    }
}
