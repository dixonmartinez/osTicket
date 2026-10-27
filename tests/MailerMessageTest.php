<?php
/**
 * tests/MailerMessageTest.php
 *
 * Pruebas de `osTicket\Mail\MailerMessage` (`include/class.mailer.message.php`),
 * las piezas de `Mailer::send()` que no envían nada: asunto, cabeceras,
 * envoltorio del identificador, cuerpo de texto y dominio de las imágenes.
 *
 * Los valores esperados están copiados del código que había dentro de
 * `Mailer::send()`. Son pruebas de caracterización: si el correo que recibe
 * el cliente cambia, la prueba lo dice.
 */
class MailerMessageTest extends UnitTestCase {

    private function message(): \osTicket\Mail\MailerMessage {
        // Sólo para que el IDE sepa qué se está probando
        return new \osTicket\Mail\MailerMessage();
    }

    /* ------------------------------------------------------------------ */
    /* Asunto                                                              */
    /* ------------------------------------------------------------------ */

    public function testElAsuntoSeRecorta(): void {
        $this->assertSame('Ticket #1234',
            \osTicket\Mail\MailerMessage::cleanSubject('  Ticket #1234  '));
    }

    public function testElAsuntoPierdeLosSaltosDeLinea(): void {
        // El salto se quita, no se cambia por un espacio (así era antes)
        $this->assertSame('Holamundo',
            \osTicket\Mail\MailerMessage::cleanSubject("Hola\nmundo"));
        $this->assertSame('Holamundo',
            \osTicket\Mail\MailerMessage::cleanSubject("Hola\r\nmundo"));
        $this->assertSame('Holamundo',
            \osTicket\Mail\MailerMessage::cleanSubject("Hola\rmundo"));
    }

    public function testUnAsuntoConSaltoNoSeConvierteEnCabecera(): void {
        // Un salto sin limpiar permitiría inyectar cabeceras
        $subject = \osTicket\Mail\MailerMessage::cleanSubject(
            "Asunto\r\nBcc: otro@example.com");

        $this->assertFalse(strpos($subject, "\n") !== false);
        $this->assertFalse(strpos($subject, "\r") !== false);
    }

    public function testElAsuntoConservaEspaciosInterioresYAcentos(): void {
        // El espacio doble de dentro se respeta; sólo se recorta fuera
        $this->assertSame('Re:  incidencia con el café',
            \osTicket\Mail\MailerMessage::cleanSubject(
                '  Re:  incidencia con el café '));
    }

    public function testSinAsuntoQuedaVacio(): void {
        $this->assertSame('', \osTicket\Mail\MailerMessage::cleanSubject(null));
    }

    /* ------------------------------------------------------------------ */
    /* Cabeceras                                                           */
    /* ------------------------------------------------------------------ */

    public function testUnCorreoNormalNoLlevaCabecerasExtra(): void {
        $this->assertSame(array(),
            \osTicket\Mail\MailerMessage::extraHeaders(array()));
    }

    public function testBoletin(): void {
        $this->assertSame(array('Precedence' => 'bulk'),
            \osTicket\Mail\MailerMessage::extraHeaders(array('bulk' => 1)));
    }

    public function testRespuestaAutomatica(): void {
        $this->assertSame(array(
            'Precedence' => 'auto_reply',
            'X-Autoreply' => 'yes',
            'X-Auto-Response-Suppress' => 'DR, RN, OOF, AutoReply',
            'Auto-Submitted' => 'auto-replied',
        ), \osTicket\Mail\MailerMessage::extraHeaders(array('autoreply' => 1)));
    }

    public function testAviso(): void {
        $this->assertSame(array(
            'X-Auto-Response-Suppress' => 'OOF, AutoReply',
            'Auto-Submitted' => 'auto-generated',
        ), \osTicket\Mail\MailerMessage::extraHeaders(array('notice' => 1)));
    }

    public function testLaRespuestaAutomaticaGanaAlAviso(): void {
        // Se aplican con +=, así que gana la primera; se conserva
        $headers = \osTicket\Mail\MailerMessage::extraHeaders(
            array('autoreply' => 1, 'notice' => 1));

        $this->assertSame('auto-replied', $headers['Auto-Submitted']);
        $this->assertSame('DR, RN, OOF, AutoReply',
            $headers['X-Auto-Response-Suppress']);
    }

    public function testBoletinYRespuestaAutomatica(): void {
        $headers = \osTicket\Mail\MailerMessage::extraHeaders(
            array('bulk' => 1, 'autoreply' => 1));

        // Gana la primera: el boletín
        $this->assertSame('bulk', $headers['Precedence']);
        $this->assertSame('yes', $headers['X-Autoreply']);
    }

    public function testLosValoresFalsosNoAñadenCabeceras(): void {
        $this->assertSame(array(),
            \osTicket\Mail\MailerMessage::extraHeaders(array(
                'bulk' => 0, 'autoreply' => false, 'notice' => null)));
    }

    /* ------------------------------------------------------------------ */
    /* Envoltorio del identificador                                        */
    /* ------------------------------------------------------------------ */

    public function testSinIdentificadorNiSeparadorElCuerpoNoCambia(): void {
        $this->assertSame('<p>Hola</p>',
            \osTicket\Mail\MailerMessage::wrapMid('<p>Hola</p>', '', ''));
        $this->assertSame('<p>Hola</p>',
            \osTicket\Mail\MailerMessage::wrapMid('<p>Hola</p>', null, null));
    }

    public function testElIdentificadorVaEnUnDivInvisible(): void {
        $body = \osTicket\Mail\MailerMessage::wrapMid('<p>Hola</p>',
            'abc123', '');

        $this->assertSame('<div style="display:none"
                        class="mid-abc123"></div><p>Hola</p>', $body);
    }

    public function testElSeparadorDeRespuestaVaDelante(): void {
        $body = \osTicket\Mail\MailerMessage::wrapMid('<p>Hola</p>', '',
            '---<br/><br/>');

        $this->assertSame('<div style="display:none"
                        class="mid-">---<br/><br/></div><p>Hola</p>', $body);
    }

    public function testIdentificadorYSeparadorJuntos(): void {
        $body = \osTicket\Mail\MailerMessage::wrapMid('cuerpo', 'mid1', 'sep');

        $this->assertStringContainsString('class="mid-mid1"', $body);
        $this->assertStringContainsString('>sep</div>cuerpo', $body);
    }

    public function testElEnvoltorioNoSeComeElCuerpo(): void {
        $body = \osTicket\Mail\MailerMessage::wrapMid('<p>Uno</p>', 'x', 'y');

        $this->assertTrue(substr($body, -16) === '</div><p>Uno</p>');
    }

    /* ------------------------------------------------------------------ */
    /* Cuerpo de texto                                                     */
    /* ------------------------------------------------------------------ */

    public function testElCuerpoDeTextoNoLlevaEtiquetas(): void {
        $text = \osTicket\Mail\MailerMessage::textBody('<p>Hola <b>mundo</b></p>');

        $this->assertStringContainsString('Hola mundo', $text);
        $this->assertFalse(strpos($text, '<p>') !== false);
        $this->assertFalse(strpos($text, '<b>') !== false);
    }

    public function testElCuerpoDeTextoTerminaSinEspacios(): void {
        $text = \osTicket\Mail\MailerMessage::textBody('<p>Hola</p>' . "\n\n");

        $this->assertSame(rtrim($text), $text);
    }

    public function testElCuerpoDeTextoAñadeLaReferencia(): void {
        $text = \osTicket\Mail\MailerMessage::textBody('<p>Hola</p>', 'abc');

        $this->assertStringContainsString("\nRef-Mid: abc\n", $text);
        $this->assertTrue(substr($text, -1) === "\n");
    }

    public function testSinIdentificadorNoHayReferencia(): void {
        $this->assertFalse(strpos(
            \osTicket\Mail\MailerMessage::textBody('<p>Hola</p>'), 'Ref-Mid') !== false);
    }

    /* ------------------------------------------------------------------ */
    /* Dominio de las imágenes incrustadas                                 */
    /* ------------------------------------------------------------------ */

    public function testElDominioSaleDeLaDireccionDeSalida(): void {
        $this->assertSame('@example.com',
            \osTicket\Mail\MailerMessage::contentIdDomain('soporte@example.com'));
    }

    public function testValeCualquierSubdominio(): void {
        $this->assertSame('@mail.example.co.uk',
            \osTicket\Mail\MailerMessage::contentIdDomain(
                'soporte@mail.example.co.uk'));
    }

    public function testSinDominioSeUsaLocalhost(): void {
        // PEAR Mail_Mime necesita un dominio con el que completar el cid:
        $this->assertSame('@localhost',
            \osTicket\Mail\MailerMessage::contentIdDomain('sin-arroba'));
        $this->assertSame('@localhost',
            \osTicket\Mail\MailerMessage::contentIdDomain(null));
    }

    /* ------------------------------------------------------------------ */
    /* Separador de la respuesta citada                                    */
    /* ------------------------------------------------------------------ */

    public function testSinLaOpcionMandaLaConfiguracion(): void {
        // Si se recorta la cita, el separador configurado delante del cuerpo
        $this->assertSame('--- fin ---<br/><br/>',
            \osTicket\Mail\MailerMessage::replyTag(array(), true, '--- fin ---'));
    }

    public function testSinLaOpcionYSinRecorteNoHaySeparador(): void {
        $this->assertSame('',
            \osTicket\Mail\MailerMessage::replyTag(array(), false, '--- fin ---'));
    }

    public function testLaOpcionEnFalseQuitaElSeparador(): void {
        // Quien llama pidió explícitamente que no lo haya
        $this->assertSame('',
            \osTicket\Mail\MailerMessage::replyTag(
                array('reply-tag' => false), true, '--- fin ---'));
    }

    public function testLaOpcionConTextoSeRespetaTalCual(): void {
        // No se le añade el <br/><br/>: el texto ya trae lo que necesite
        $this->assertSame('Mi separador',
            \osTicket\Mail\MailerMessage::replyTag(
                array('reply-tag' => 'Mi separador'), true, '--- fin ---'));
        $this->assertSame('',
            \osTicket\Mail\MailerMessage::replyTag(
                array('reply-tag' => ''), true, '--- fin ---'));
    }

    public function testElSeparadorPorDefectoNoSeInventa(): void {
        $this->assertSame('<br/><br/>',
            \osTicket\Mail\MailerMessage::replyTag(array(), true));
    }

    /* ------------------------------------------------------------------ */
    /* Reescritura de los cid:                                             */
    /* ------------------------------------------------------------------ */

    public function testUnCidConSuIdentificadorGanaElDominio(): void {
        $id = str_repeat('a', 32);

        $this->assertSame('src="cid:' . $id . '@example.com"',
            \osTicket\Mail\MailerMessage::rewriteContentIds(
                'src="cid:' . $id . '"', '@example.com',
                function ($matchId) use ($id) {
                    return $matchId === $id ? '@example.com' : null;
                }));
    }

    public function testSiLaImagenYaNoEstaElCidSeQuedaComoEsta(): void {
        // El cuerpo conserva la referencia rota en vez de perder texto
        $body = 'src="cid:' . str_repeat('b', 32) . '"';

        $this->assertSame($body,
            \osTicket\Mail\MailerMessage::rewriteContentIds(
                $body, '@example.com', function ($id) { return null; }));
    }

    public function testUnCidCortoOCursivaNoSeToca(): void {
        // El patrón pide exactamente 32 caracteres
        $corto = 'cid:' . str_repeat('c', 31);
        $largo = 'cid:' . str_repeat('d', 33);
        $id = str_repeat('e', 32);
        $body = $corto . ' ' . $largo . ' cid:' . $id . '-otro';

        $this->assertSame($body,
            \osTicket\Mail\MailerMessage::rewriteContentIds(
                $body, '@example.com', function ($id) { return null; }));
    }

    public function testSeReescribenTodosLosCid(): void {
        $uno = str_repeat('1', 32);
        $dos = str_repeat('2', 32);
        $body = 'cid:' . $uno . ' y cid:' . $dos;

        $this->assertSame('cid:' . $uno . '@example.com y cid:' . $dos . '@example.com',
            \osTicket\Mail\MailerMessage::rewriteContentIds(
                $body, '@example.com',
                function ($id) { return '@example.com'; }));
    }

    public function testElCuerpoVacioNoRompeLaReescritura(): void {
        $this->assertSame('',
            \osTicket\Mail\MailerMessage::rewriteContentIds(
                '', '@example.com', function ($id) { return '@example.com'; }));
        $this->assertSame('',
            \osTicket\Mail\MailerMessage::rewriteContentIds(
                null, '@example.com', function ($id) { return '@example.com'; }));
    }
}
