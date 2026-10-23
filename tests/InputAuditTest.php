<?php
/**
 * tests/InputAuditTest.php
 *
 * Pruebas de `tools/quality/input-audit.php` (frente 4 de
 * docs/CODE_QUALITY.md): el contador de accesos directos a `$_GET`, `$_POST`
 * y `$_REQUEST` y su comparación con la línea base.
 *
 * Se prueba el analizador, no la salida por pantalla: el archivo se incluye
 * con la salida capturada porque empieza por una línea `#!`.
 */
ob_start();
require_once dirname(__DIR__) . '/tools/quality/input-audit.php';
ob_end_clean();

class InputAuditTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* Qué cuenta                                                          */
    /* ------------------------------------------------------------------ */

    public function testCuentaUnAccesoDirecto(): void {
        $this->assertSame(1, InputAudit::countIn('$id = $_POST[\'id\'];'));
    }

    public function testCuentaLosTresSuperglobales(): void {
        $source = '$_GET["a"]; $_POST["b"]; $_REQUEST["c"];';

        $this->assertSame(3, InputAudit::countIn($source));
    }

    public function testCuentaDentroDeIsset(): void {
        // Sigue siendo un acceso directo aunque sólo se compruebe
        $this->assertSame(1, InputAudit::countIn('if (isset($_POST[\'x\'])) {}'));
    }

    public function testNoCuentaElArrayEntero(): void {
        // `$_POST` como valor no es una lectura por nombre: son los casos que
        // la fachada no puede sustituir sin cambiar el diseño
        $this->assertSame(0, InputAudit::countIn('$todos = $_POST;'));
        $this->assertSame(0, InputAudit::countIn('Format::htmlchars($_POST)'));
    }

    public function testNoCuentaOtrasSuperglobales(): void {
        $this->assertSame(0, InputAudit::countIn(
            '$_SERVER["x"]; $_FILES["f"]; $_COOKIE["c"]; $_SESSION["s"];'));
    }

    public function testNoCuentaUnaVariableParecida(): void {
        $this->assertSame(0, InputAudit::countIn('$POST["x"]; $my_POST["y"];'));
    }

    public function testCuentaVariasEnLaMismaLinea(): void {
        $this->assertSame(2, InputAudit::countIn(
            '$a = $_GET[\'a\']; $b = $_GET[\'b\'];'));
    }

    public function testCuentaConEspaciosAntesDelCorchete(): void {
        $this->assertSame(1, InputAudit::countIn('$_POST [\'x\']'));
    }

    public function testCuentaDentroDeCadenas(): void {
        // El patrón no distingue cadenas: se prefiere contar de más (una
        // cadena que menciona $_POST también es deuda pendiente)
        $this->assertSame(1, InputAudit::countIn('$x = "$_POST[\'a\']";'));
    }

    /* ------------------------------------------------------------------ */
    /* Qué archivos se saltan                                              */
    /* ------------------------------------------------------------------ */

    public function testSaltaLasLibreriasCopiadas(): void {
        $skip = array('vendor', 'mpdf');

        $this->assertTrue(InputAudit::skipped('include/vendor/foo.php', $skip));
        $this->assertTrue(InputAudit::skipped('include/mpdf/bar.php', $skip));
    }

    public function testNoSaltaElCodigoPropio(): void {
        $skip = array('vendor', 'lib');

        $this->assertFalse(InputAudit::skipped('include/class.ticket.php',
            $skip));
        $this->assertFalse(InputAudit::skipped('scp/tickets.php', $skip));
    }

    public function testLaFachadaEstaDeclarada(): void {
        $this->assertSame('include/class.http.php', InputAudit::FACADE);
    }

    /* ------------------------------------------------------------------ */
    /* Comparación con la línea base                                       */
    /* ------------------------------------------------------------------ */

    public function testSinCambiosEstaBien(): void {
        $result = InputAudit::compare(
            array('scp/tickets.php' => 60),
            array('scp/tickets.php' => 60));

        $this->assertTrue($result['ok']);
        $this->assertSame(array(), $result['added']);
        $this->assertSame(array(), $result['grown']);
    }

    public function testUnArchivoNuevoConAccesoNoEstaBien(): void {
        $result = InputAudit::compare(
            array('include/nuevo.php' => 2),
            array());

        $this->assertFalse($result['ok']);
        $this->assertSame(array('include/nuevo.php' => 2), $result['added']);
    }

    public function testUnaSubidaNoEstaBien(): void {
        $result = InputAudit::compare(
            array('scp/tickets.php' => 61),
            array('scp/tickets.php' => 60));

        $this->assertFalse($result['ok']);
        $this->assertSame(array('scp/tickets.php' => array(60, 61)),
            $result['grown']);
    }

    public function testUnaBajadaEstaBien(): void {
        $result = InputAudit::compare(
            array('scp/tickets.php' => 10),
            array('scp/tickets.php' => 60));

        $this->assertTrue($result['ok']);
        $this->assertSame(array('scp/tickets.php' => array(60, 10)),
            $result['shrunk']);
    }

    public function testUnArchivoQueYaNoTieneAccesosCuentaComoBajada(): void {
        $result = InputAudit::compare(
            array(),
            array('include/class.viejo.php' => 4));

        $this->assertTrue($result['ok']);
        $this->assertSame(array('include/class.viejo.php' => array(4, 0)),
            $result['shrunk']);
    }

    public function testLaLineaBaseSeGuardaYSeLee(): void {
        $path = tempnam(sys_get_temp_dir(), 'input-audit-');
        try {
            // Se guarda ordenado por ruta, para que el archivo sea estable
            $counts = array('scp/tickets.php' => 60, 'include/a.php' => 2);
            $this->assertTrue(InputAudit::save($path, $counts));
            $this->assertSame(array(
                'include/a.php' => 2,
                'scp/tickets.php' => 60,
            ), InputAudit::load($path));
        } finally {
            @unlink($path);
        }
    }

    public function testSiNoHayLineaBaseSeEmpiezaVacia(): void {
        $this->assertSame(array(), InputAudit::load('/tmp/no-existe-' .
            uniqid() . '.json'));
    }
}
