<?php
/**
 * tests/HttpInputTest.php
 *
 * Pruebas de la fachada de entrada de `Http` (`include/class.http.php`,
 * frente 4 de docs/CODE_QUALITY.md): `Http::get()`, `Http::post()`,
 * `Http::request()` y sus versiones enteras.
 *
 * La idea es que el código nuevo no lea `$_POST[...]` a pelo: así el valor
 * que falta tiene un comportamiento definido y no llena el log de avisos.
 */
class HttpInputTest extends UnitTestCase {

    private $get;
    private $post;
    private $request;

    public function setUp(): void {
        $this->get = $_GET;
        $this->post = $_POST;
        $this->request = $_REQUEST;

        $_GET = array(
            'q' => 'hola',
            'page' => '3',
            'vacio' => '',
            'cero' => '0',
            'lista' => array('a', 'b'),
            'negativo' => '-7',
            'texto' => '12abc',
        );
        $_POST = array(
            'id' => '42',
            'nombre' => 'Ana',
            'vacio' => '',
            'nulo' => null,
            'lista' => array('a', 'b'),
            'booleano' => '0',
        );
        $_REQUEST = $_GET + array('id' => '42');
    }

    public function tearDown(): void {
        $_GET = $this->get;
        $_POST = $this->post;
        $_REQUEST = $this->request;
    }

    /* ------------------------------------------------------------------ */
    /* Valores                                                             */
    /* ------------------------------------------------------------------ */

    public function testGetDevuelveElValor(): void {
        $this->assertSame('hola', Http::get('q'));
    }

    public function testGetSinLaClaveDevuelveNull(): void {
        $this->assertNull(Http::get('no-existe'));
    }

    public function testGetSinLaClaveUsaElValorPorDefecto(): void {
        $this->assertSame('defecto', Http::get('no-existe', 'defecto'));
    }

    public function testUnValorVacioNoEsUnaClaveAusente(): void {
        // ?vacio= es un valor vacío, no «no vino»
        $this->assertSame('', Http::get('vacio', 'defecto'));
    }

    public function testPostDevuelveElValor(): void {
        $this->assertSame('Ana', Http::post('nombre'));
    }

    public function testPostSinLaClaveUsaElValorPorDefecto(): void {
        $this->assertSame(0, Http::post('no-existe', 0));
    }

    public function testPostConValorNuloDevuelveNull(): void {
        // La clave existe aunque su valor sea null
        $this->assertNull(Http::post('nulo', 'defecto'));
    }

    public function testLasListasSeDevuelvenTalCual(): void {
        $this->assertSame(array('a', 'b'), Http::post('lista'));
        $this->assertSame(array('a', 'b'), Http::get('lista'));
    }

    public function testRequestMezclaGetYPost(): void {
        $this->assertSame('hola', Http::request('q'));
        $this->assertSame('42', Http::request('id'));
    }

    public function testRequestSinLaClaveUsaElValorPorDefecto(): void {
        $this->assertNull(Http::request('no-existe'));
        $this->assertSame('x', Http::request('no-existe', 'x'));
    }

    /* ------------------------------------------------------------------ */
    /* Enteros                                                             */
    /* ------------------------------------------------------------------ */

    public function testGetIntConvierte(): void {
        $this->assertSame(3, Http::getInt('page'));
    }

    public function testGetIntAceptaCero(): void {
        // Un 0 explícito no puede confundirse con «no vino»
        $this->assertSame(0, Http::getInt('cero'));
    }

    public function testGetIntConNegativos(): void {
        $this->assertSame(-7, Http::getInt('negativo'));
    }

    public function testGetIntConTextoEmpiezaPorElNumero(): void {
        $this->assertSame(12, Http::getInt('texto'));
    }

    public function testGetIntSinLaClaveDevuelveCero(): void {
        $this->assertSame(0, Http::getInt('no-existe'));
    }

    public function testGetIntConValorPorDefecto(): void {
        $this->assertSame(9, Http::getInt('no-existe', 9));
    }

    public function testGetIntConUnaListaNoAvisaYDevuelveElDefecto(): void {
        // (int) array sería un aviso de PHP; aquí no se llega a castear
        $this->assertSame(5, Http::getInt('lista', 5));
    }

    public function testPostIntConvierte(): void {
        $this->assertSame(42, Http::postInt('id'));
        $this->assertSame(0, Http::postInt('booleano'));
    }

    public function testRequestIntConvierte(): void {
        $this->assertSame(3, Http::requestInt('page'));
        $this->assertSame(7, Http::requestInt('no-existe', 7));
    }
}
