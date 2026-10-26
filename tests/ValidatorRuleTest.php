<?php
/**
 * tests/ValidatorRuleTest.php
 *
 * Pruebas de `ValidatorRule` (`include/class.validator.rule.php`), la tabla de
 * reglas de tipo que usaba `Validator::validate()` (frente 2 de
 * docs/CODE_QUALITY.md).
 *
 * Antes de sacarlas a su archivo, de estos catorce tipos sólo se probaban tres
 * (correo, contraseña e entero) y siempre a través de `validate()`. Aquí se
 * comprueba cada regla por separado, incluido lo que **no** debe cambiar: qué
 * mensaje sale, qué tipos aceptan cualquier cosa y cuál es el error cuando el
 * tipo no existe.
 */

class ValidatorRuleTest extends UnitTestCase {

    /** Definición de campo como la que llega desde un formulario. */
    private function field(array $extra = array()): array {
        return $extra + array('error' => 'no vale', 'type' => '*');
    }

    /** ¿Pasa el valor? Devuelve también el mensaje, si no pasa. */
    private function check($type, $value, array $extra = array()): array {
        $message = ValidatorRule::check($type, $value, $this->field($extra));

        return array($message === null, $message);
    }

    /* ------------------------------------------------------------------ */
    /* Números                                                             */
    /* ------------------------------------------------------------------ */

    public function testEntero(): void {
        $this->assertSame(array(true, null), $this->check('int', '42'));
        $this->assertSame(array(true, null), $this->check('integer', 42));
        $this->assertSame(array(true, null), $this->check('int', -7));
        $this->assertSame(array(false, 'no vale'), $this->check('int', 'cuarenta'));
        $this->assertSame(array(false, 'no vale'), $this->check('int', ''));
    }

    public function testEnteroConMinimo(): void {
        // `min` sólo se mira cuando está definido y es distinto de cero
        $this->assertSame(array(true, null), $this->check('int', 5, array('min' => 5)));
        $this->assertSame(array(false, 'no vale'), $this->check('int', 4, array('min' => 5)));
        $this->assertSame(array(true, null), $this->check('int', 0, array('min' => 0)));
    }

    public function testDoble(): void {
        $this->assertSame(array(true, null), $this->check('double', '3.14'));
        $this->assertSame(array(true, null), $this->check('double', 3));
        $this->assertSame(array(false, 'no vale'), $this->check('double', 'tres'));
    }

    public function testZipcode(): void {
        $this->assertSame(array(true, null), $this->check('zipcode', '28001'));
        // Cinco cifras exactas: ni cuatro ni seis
        $this->assertSame(array(false, 'no vale'), $this->check('zipcode', '2800'));
        $this->assertSame(array(false, 'no vale'), $this->check('zipcode', '280011'));
        $this->assertSame(array(false, 'no vale'), $this->check('zipcode', 'ABCDE'));
    }

    /* ------------------------------------------------------------------ */
    /* Texto                                                               */
    /* ------------------------------------------------------------------ */

    public function testTextoYArreglo(): void {
        $this->assertSame(array(true, null), $this->check('text', 'hola'));
        $this->assertSame(array(false, 'no vale'), $this->check('text', 42));
        $this->assertSame(array(true, null), $this->check('string', 'hola'));

        $this->assertSame(array(true, null), $this->check('array', array('a')));
        // Un arreglo vacío no cuenta como valor
        $this->assertSame(array(false, 'no vale'), $this->check('array', array()));
        $this->assertSame(array(false, 'no vale'), $this->check('array', 'no soy un arreglo'));
    }

    public function testRadio(): void {
        // `radio` sólo comprueba que el campo venga en la petición
        $this->assertSame(array(true, null), $this->check('radio', 'si'));
        $this->assertSame(array(true, null), $this->check('radio', ''));
        $this->assertSame(array(false, 'no vale'), $this->check('radio', null));
    }

    public function testHoraAceptaCualquierCosa(): void {
        // Documentado como pendiente en el original: no valida formato
        $this->assertSame(array(true, null), $this->check('time', 'lo que sea'));
    }

    public function testFecha(): void {
        $this->assertSame(array(true, null), $this->check('date', '2026-10-26'));
        $this->assertSame(array(true, null), $this->check('date', '26 October 2026'));
        $this->assertSame(array(false, 'no vale'), $this->check('date', 'mañana'));
    }

    /* ------------------------------------------------------------------ */
    /* Cadenas con reglas propias                                          */
    /* ------------------------------------------------------------------ */

    public function testCorreoTelefonoYUrl(): void {
        $this->assertSame(array(true, null), $this->check('email', 'ana@example.com'));
        $this->assertSame(array(false, 'no vale'), $this->check('email', 'ana@'));

        $this->assertSame(array(true, null), $this->check('phone', '555 123 4567'));
        $this->assertSame(array(false, 'no vale'), $this->check('phone', 'llámame'));

        $this->assertSame(array(true, null), $this->check('url', 'https://example.com/x'));
        $this->assertSame(array(false, 'no vale'), $this->check('url', 'no es una url'));
    }

    public function testContrasenaExigeSeisCaracteres(): void {
        $this->assertSame(array(true, null), $this->check('password', 'secreta'));
        list($ok, $message) = $this->check('password', 'corta');
        $this->assertFalse($ok);
        // El mensaje conserva el sufijo que ve el usuario
        $this->assertStringContainsString('no vale', $message);
        $this->assertStringContainsString('(Six characters min)', $message);
    }

    public function testUsuarioAnexaElMotivo(): void {
        $this->assertSame(array(true, null), $this->check('username', 'ana.lopez'));

        list($ok, $message) = $this->check('username', 'a');
        $this->assertFalse($ok);
        // El mensaje del campo va delante y el motivo detrás, tras dos puntos
        $this->assertStringContainsString('no vale:', $message);
    }

    /* ------------------------------------------------------------------ */
    /* Listas separadas por comas                                          */
    /* ------------------------------------------------------------------ */

    public function testListaDeDominios(): void {
        $this->assertSame(array(true, null), $this->check('cs-domain', 'example.com'));
        $this->assertSame(array(true, null),
            $this->check('cs-domain', 'example.com, sub.example.co.uk'));
        // Un elemento malo invalida el campo entero
        $this->assertSame(array(false, 'no vale'),
            $this->check('cs-domain', 'example.com, no-es-un-dominio'));
        // Sólo se recorta el espacio de la izquierda (`ltrim`), como antes:
        // un espacio al final del último elemento lo invalida. Se deja igual
        // a propósito —es un refactor, no un cambio de validación— y queda
        // anotado en docs/CODE_QUALITY.md.
        $this->assertSame(array(true, null),
            $this->check('cs-domain', ' example.com,  example.org'));
        $this->assertSame(array(false, 'no vale'),
            $this->check('cs-domain', 'example.com '));
    }

    public function testListaDeUrls(): void {
        $this->assertSame(array(true, null), $this->check('cs-url', 'https://example.com'));
        $this->assertSame(array(true, null), $this->check('cs-url', 'example.com:8080'));
        $this->assertSame(array(false, 'no vale'),
            $this->check('cs-url', 'https://example.com, con espacios'));
    }

    public function testListaDeDireccionesIp(): void {
        $this->assertSame(array(true, null), $this->check('ipaddr', '192.168.1.1'));
        $this->assertSame(array(true, null),
            $this->check('ipaddr', '10.0.0.1, 10.0.0.2'));
        $this->assertSame(array(false, 'no vale'), $this->check('ipaddr', '10.0.0'));
        $this->assertSame(array(false, 'no vale'), $this->check('ipaddr', '::1'));
    }

    /* ------------------------------------------------------------------ */
    /* Tipos desconocidos                                                  */
    /* ------------------------------------------------------------------ */

    public function testTipoDesconocidoLoDice(): void {
        list($ok, $message) = $this->check('inventado', 'x');

        $this->assertFalse($ok);
        $this->assertStringContainsString('no vale', $message);
        $this->assertStringContainsString('(type not set)', $message);
    }

    public function testUnCampoSinMensajePropioNoAvisa(): void {
        // `error` puede no venir en la definición: no se puede leer sin más
        $this->assertSame('no vale',
            ValidatorRule::check('int', 'x', array('error' => 'no vale')));
        $this->assertSame(' (type not set)',
            ValidatorRule::check('inventado', 'x', array()));
    }
}
