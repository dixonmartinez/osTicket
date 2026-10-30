<?php
/**
 * tests/ValidatorTest.php
 *
 * Pruebas de caracterización de `Validator` (frente 1 de
 * docs/CODE_QUALITY.md): correo, IP, URL, teléfono, usuario y el validador
 * de formularios.
 *
 * Son las comprobaciones que están detrás de cada alta de usuario, cada
 * respuesta a un ticket y cada ajuste guardado: si cambian sin querer, el
 * fallo aparece en producción y no aquí.
 */
class ValidatorTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* Correo                                                              */
    /* ------------------------------------------------------------------ */

    public function testAceptaCorreosNormales(): void {
        $this->assertTrue(Validator::is_email('usuario@example.com'));
        $this->assertTrue(Validator::is_email('nombre.apellido@sub.example.co.uk'));
        $this->assertTrue(Validator::is_email('a+b@example.com'));
    }

    public function testRechazaCorreosInvalidos(): void {
        $this->assertFalse(Validator::is_email('no-es-un-correo'));
        $this->assertFalse(Validator::is_email('usuario@'));
        $this->assertFalse(Validator::is_email('@example.com'));
        $this->assertFalse(Validator::is_email(''));
    }

    public function testRechazaLocalhostComoDominio(): void {
        $this->assertFalse(Validator::is_email('root@localhost'));
    }

    /**
     * `is_email()` responde sí o no, nunca un número.
     *
     * Con la comprobación DNS activada la rama del registro MX devolvía
     * `count(...)`, así que quien comparaba con `=== true` o guardaba el
     * resultado en un campo booleano recibía un entero. Un dominio que no
     * existe se comprueba sin red.
     */
    public function testLaVerificacionDnsRespondeBooleano(): void {
        $resultado = Validator::is_email(
            'nadie@dominio-que-no-existe.invalid', false, true);

        $this->assertSame(false, $resultado,
            'un dominio sin MX ni A tiene que devolver false, no un contador');
    }

    public function testModoListaAceptaVariosSeparadosPorComa(): void {
        $this->assertTrue(Validator::is_email('a@example.com, b@example.com', true));
        // Sin el modo lista, dos direcciones no son un correo
        $this->assertFalse(Validator::is_email('a@example.com, b@example.com'));
    }

    public function testIsEmailishEsSoloUnaPista(): void {
        // Nombre mal escrito: hay algo a cada lado de la arroba
        $this->assertTrue((bool) Validator::is_emailish('usuario@example'));
        $this->assertFalse((bool) Validator::is_emailish('sin arroba'));
    }

    /* ------------------------------------------------------------------ */
    /* IP y redes                                                          */
    /* ------------------------------------------------------------------ */

    public function testReconoceIPv4EIPv6(): void {
        $this->assertTrue(Validator::is_ip('192.168.1.1'));
        $this->assertTrue(Validator::is_ip('8.8.8.8'));
        $this->assertTrue(Validator::is_ip('::1'));
        $this->assertTrue(Validator::is_ip('2001:db8::1'));
        // Un espacio de más no se corrige solo
        $this->assertTrue(Validator::is_ip(' 8.8.8.8 '));
    }

    public function testRechazaLoQueNoEsUnaIP(): void {
        $this->assertFalse(Validator::is_ip('192.168.1.256'));
        $this->assertFalse(Validator::is_ip('8.8.8'));
        $this->assertFalse(Validator::is_ip('no-soy-una-ip'));
        $this->assertFalse(Validator::is_ip(''));
    }

    public function testCheckIpDentroYFueraDeLaRed(): void {
        $rango = array('192.168.1.0/24');
        $this->assertTrue(Validator::check_ip('192.168.1.5', $rango));
        $this->assertFalse(Validator::check_ip('192.168.2.5', $rango));
        // Direcciones exactas sin máscara
        $this->assertTrue(Validator::check_ip('10.0.0.1', array('10.0.0.1')));
        $this->assertFalse(Validator::check_ip('10.0.0.2', array('10.0.0.1')));
    }

    public function testCheckIpConIPv6(): void {
        $this->assertTrue(Validator::check_ip('2001:db8::1', array('2001:db8::/32')));
        $this->assertFalse(Validator::check_ip('2001:db9::1', array('2001:db8::/32')));
    }

    public function testCheckIpIgnoraLoQueNoEsUnaIp(): void {
        $this->assertFalse(Validator::check_ip('no-soy-una-ip', array('0.0.0.0/0')));
    }

    /* ------------------------------------------------------------------ */
    /* URL                                                                 */
    /* ------------------------------------------------------------------ */

    public function testAceptaUrlsConEsquemaYHost(): void {
        $this->assertTrue(Validator::is_url('https://example.com'));
        $this->assertTrue(Validator::is_url('http://example.com/ruta?x=1'));
        $this->assertTrue(Validator::is_url('https://usuario:clave@example.com:8443/x'));
    }

    public function testRechazaUrlsSinHost(): void {
        $this->assertFalse(Validator::is_url('example.com'));
        $this->assertFalse(Validator::is_url('/solo/ruta'));
        $this->assertFalse(Validator::is_url(''));
    }

    /* ------------------------------------------------------------------ */
    /* Teléfono                                                            */
    /* ------------------------------------------------------------------ */

    public function testAceptaTelefonosDeLongitudRazonable(): void {
        $this->assertTrue(Validator::is_phone('5551234'));
        $this->assertTrue(Validator::is_phone('(555) 123-4567'));
        $this->assertTrue(Validator::is_phone('+1 555 123 4567'));
    }

    public function testRechazaTelefonosCortosOLargos(): void {
        $this->assertFalse(Validator::is_phone('123'));
        $this->assertFalse(Validator::is_phone('12345678901234567'));
        $this->assertFalse(Validator::is_phone('no-es-telefono'));
    }

    /* ------------------------------------------------------------------ */
    /* Usuario y números                                                   */
    /* ------------------------------------------------------------------ */

    public function testAceptaUsuariosValidos(): void {
        $this->assertTrue(Validator::is_username('dixon'));
        $this->assertTrue(Validator::is_username('dixon.martinez'));
        $this->assertTrue(Validator::is_username('jose_perez-2'));
        $this->assertTrue(Validator::is_username('josé'));
    }

    public function testRechazaUsuariosInvalidos(): void {
        $error = '';
        $this->assertFalse(Validator::is_username('a', $error));
        $this->assertSame('Username must have at least two (2) characters', $error);

        $error = '';
        $this->assertFalse(Validator::is_username('12345', $error));
        $this->assertSame('Username contains invalid characters', $error);

        $error = '';
        $this->assertFalse(Validator::is_username('con espacio', $error));
        $this->assertSame('Username contains invalid characters', $error);
    }

    public function testIsNumericDejaMensaje(): void {
        $error = '';
        $this->assertTrue(Validator::is_numeric('12.5', $error));
        $this->assertSame('', $error);

        $error = '';
        $this->assertFalse(Validator::is_numeric('doce', $error));
        $this->assertSame('Enter a Number', $error);
    }

    public function testFormulaNoPuedeEmpezarPorSignosPeligrosos(): void {
        $error = '';
        $this->assertTrue(Validator::is_formula('hola mundo', $error));

        foreach (array('=SUM(A1)', '-1+1', '@usuario') as $formula) {
            $error = '';
            $this->assertFalse(Validator::is_formula($formula, $error),
                "'$formula' debería rechazarse");
            $this->assertStringContainsString('Content cannot start', $error);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Validador de formularios                                            */
    /* ------------------------------------------------------------------ */

    private function campos() {
        return array(
            'nombre' => array('type' => 'text', 'required' => true,
                'error' => 'Falta el nombre'),
            'edad' => array('type' => 'int', 'required' => false,
                'error' => 'Edad no válida', 'min' => 18),
            'correo' => array('type' => 'email', 'required' => false,
                'error' => 'Correo no válido'),
            'clave' => array('type' => 'password', 'required' => false,
                'error' => 'Contraseña no válida'),
        );
    }

    public function testValidateAceptaEntradaCorrecta(): void {
        $v = new Validator($this->campos());

        $this->assertTrue($v->validate(array('nombre' => 'Ana', 'edad' => 30)));
        $this->assertFalse($v->iserror());
        $this->assertSame(array(), $v->errors());
    }

    public function testValidateExigeLosCamposObligatorios(): void {
        $v = new Validator($this->campos());

        $this->assertFalse($v->validate(array('edad' => 30)));
        $this->assertTrue($v->iserror());
        $this->assertSame('Falta el nombre', $v->errors()['nombre']);
    }

    public function testValidateCompruebaElMinimoDelEntero(): void {
        $v = new Validator($this->campos());

        $this->assertFalse($v->validate(array('nombre' => 'Ana', 'edad' => 10)));
        $this->assertSame('Edad no válida', $v->errors()['edad']);
    }

    public function testValidateCompruebaCorreoYContrasena(): void {
        $v = new Validator($this->campos());

        $this->assertFalse($v->validate(array(
            'nombre' => 'Ana',
            'correo' => 'no-es-correo',
            'clave' => '123',
        )));

        $errors = $v->errors();
        $this->assertSame('Correo no válido', $errors['correo']);
        $this->assertStringContainsString('Contraseña no válida', $errors['clave']);
        $this->assertStringContainsString('Six characters min', $errors['clave']);
    }

    public function testValidateSinCamposConfiguradosAvisa(): void {
        $v = new Validator();

        $this->assertFalse($v->validate(array('a' => 1)));
        $this->assertSame('No fields set up', $v->errors()['err']);
    }

    public function testValidateSinEntradaAvisa(): void {
        $v = new Validator($this->campos());

        $this->assertFalse($v->validate(null));
        $this->assertSame('Invalid input', $v->errors()['err']);
    }

    public function testValidateIgnoraLosOpcionalesVacios(): void {
        $v = new Validator($this->campos());

        // correo y clave vacíos no se quejan: no son obligatorios
        $this->assertTrue($v->validate(array('nombre' => 'Ana', 'correo' => '', 'clave' => '')));
    }
}
