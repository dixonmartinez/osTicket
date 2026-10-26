<?php
/**
 * tests/MailTestScriptTest.php
 *
 * Pruebas de `tools/dev/mail-test.php`, el script de `make test-mail`.
 *
 * No se puede hablar SMTP de verdad desde las pruebas: no hay servidor. Lo
 * que sí se puede comprobar es el **diálogo**, que es donde falló:
 *
 *   Conectando a mailpit:1025 …
 *   Fallo en «EHLO osticket.local»
 *     esperado 250, recibido: 220 f8338edcf14b Mailpit ESMTP Service ready
 *
 * El servidor saluda primero con `220`. Si el script no lee ese saludo, la
 * respuesta al `EHLO` que llega es el `220`, y el envío se aborta aunque el
 * buzón esté perfectamente. Es un fallo que parece «Mailpit roto» y es una
 * lectura de menos.
 *
 * También se comprueba el nombre del servicio: en `compose.override.yaml` se
 * llama `mail`, y `mailpit` es un alias de red. Sin el alias, el script
 * muere con «getaddrinfo for mailpit failed».
 */

class MailTestScriptTest extends UnitTestCase {

    private function script() {
        return file_get_contents(ROOT_DIR . '/tools/dev/mail-test.php');
    }

    private function compose() {
        return file_get_contents(ROOT_DIR . '/compose.override.yaml');
    }

    /** El diálogo tiene que empezar leyendo el saludo, no mandando EHLO. */
    function testLeeElSaludoAntesDeMandarEhlo() {
        $script = $this->script();

        $saludo = strpos($script, "smtp_cmd(\$socket, '', '220'");
        $ehlo = strpos($script, "smtp_cmd(\$socket, 'EHLO");

        $this->assertTrue($saludo !== false,
            'El script no lee el saludo 220 del servidor');
        $this->assertTrue($ehlo !== false, 'El script no manda EHLO');
        $this->assertTrue($saludo < $ehlo,
            'El saludo se lee después del EHLO: el 220 se tomaría como respuesta');
    }

    /** Leer sin mandar nada es una opción explícita de `smtp_cmd`. */
    function testSmtpCmdPermiteSoloLeer() {
        $script = $this->script();

        $this->assertTrue(
            strpos($script, 'function smtp_cmd($socket, $command, $expected = \'250\', $send = true)')
                !== false,
            'smtp_cmd no distingue entre mandar un comando y sólo leer');
        $this->assertTrue(strpos($script, 'if ($send)') !== false,
            'smtp_cmd escribe aunque no haya que mandar nada');
    }

    /**
     * La secuencia completa de un envío: saludo, EHLO, remitente,
     * destinatario, DATA y punto final. Si falta un paso, el buzón rechaza.
     */
    function testLaSecuenciaSmtpEstaCompleta() {
        $script = $this->script();

        foreach (array(
            "'220'" => 'el saludo inicial',
            "'EHLO osticket.local', '250'" => 'la identificación',
            "'MAIL FROM:<'" => 'el remitente',
            "'RCPT TO:<'" => 'el destinatario',
            "'DATA', '354'" => 'el cuerpo',
            '"QUIT\\r\\n"' => 'la despedida',
        ) as $needle => $what)
            $this->assertTrue(strpos($script, $needle) !== false,
                'Falta ' . $what . ' en el diálogo SMTP');
    }

    /** El servicio real se llama `mail`; `mailpit` tiene que ser un alias. */
    function testElServicioSeLlamaMailYSigueRespondiendoAMailpit() {
        $compose = $this->compose();

        $this->assertTrue(preg_match('/^  mail:/m', $compose) === 1,
            'El servicio de correo ya no se llama `mail`');
        $this->assertTrue(strpos($compose, 'aliases:') !== false
            && strpos($compose, '- mailpit') !== false,
            'Falta el alias `mailpit`: el nombre de la imagen no resuelve en la red');
    }

    /**
     * El script reintenta con el nombre del servicio si el alias todavía no
     * existe (contenedor creado antes de añadirlo). Sin eso, el mensaje de
     * error manda a arreglar algo que ya está bien.
     */
    function testReintentaConElNombreDelServicio() {
        $script = $this->script();

        $this->assertTrue(strpos($script, "\$fallback = (\$host === 'mailpit')") !== false,
            'No hay reintento con el nombre del servicio');
        $this->assertTrue(strpos($script, 'No se pudo conectar a') !== false,
            'No hay mensaje cuando fallan los dos nombres');
    }

    /** `make test-mail` tiene que ejecutarse dentro del contenedor. */
    function testElObjetivoDelMakefileCorreEnElContenedor() {
        $makefile = file_get_contents(ROOT_DIR . '/Makefile');

        $this->assertTrue(preg_match(
            '/^test-mail:.*\n\t@docker compose exec -T php php tools\/dev\/mail-test\.php/m',
            $makefile) === 1,
            'make test-mail ya no se ejecuta dentro del contenedor de PHP');
    }
}
