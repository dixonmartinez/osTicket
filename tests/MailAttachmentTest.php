<?php
/**
 * tests/MailAttachmentTest.php
 *
 * Pruebas de las dos decisiones que se toman antes de leer el cuerpo de un
 * correo (`MailParse::getAttachments()` → `MailAttachment`): si la parte MIME
 * es un adjunto y cómo se llama el archivo.
 *
 * Esas dos cosas estaban en 127 líneas de método y casi nunca se ejecutaban:
 * sólo cuando llegaba el cliente de correo raro. Aquí se fijan las ocho ramas
 * del nombre, con las funciones de formato inyectadas para no depender de la
 * configuración ni del azar del nombre aleatorio.
 */

require_once INCLUDE_DIR . 'class.mail.attachment.php';

class MailAttachmentTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* Utilidades                                                          */
    /* ------------------------------------------------------------------ */

    /** Herramientas de la aplicación, con `random` predecible. */
    private function tools() {
        return array(
            'mimedecode' => function($text, $charset = null) {
                return 'decoded:' . $text;
            },
            'rfc5987' => function($text) {
                return 'rfc5987:' . $text;
            },
            'random' => function($n) {
                return str_repeat('x', $n);
            },
        );
    }

    private function filename(array $part) {
        $part += array(
            'd_parameters' => array(),
            'ctype_parameters' => array(),
            'headers' => array(),
            'primary' => 'application',
            'secondary' => 'pdf',
            'parts' => array(),
        );

        return MailAttachment::filename($part, $this->tools(), 'utf-8');
    }

    /* ------------------------------------------------------------------ */
    /* ¿Es un adjunto?                                                     */
    /* ------------------------------------------------------------------ */

    function testLaDisposicionAttachmentEsUnAdjunto() {
        $this->assertTrue(MailAttachment::isAttachment('attachment', 'text'));
    }

    function testLaDisposicionInlineEsUnAdjunto() {
        $this->assertTrue(MailAttachment::isAttachment('inline', 'text'));
    }

    /** La comparación no distingue mayúsculas: los clientes tampoco. */
    function testLaDisposicionNoDistingueMayusculas() {
        $this->assertTrue(MailAttachment::isAttachment('Attachment', 'text'));
    }

    /** Sin cabecera de disposición, el tipo manda. */
    function testUnaImagenSinDisposicionEsUnAdjunto() {
        $this->assertTrue(MailAttachment::isAttachment(null, 'image'));
    }

    function testUnaAplicacionSinDisposicionEsUnAdjunto() {
        $this->assertTrue(MailAttachment::isAttachment(null, 'application'));
    }

    function testElTextoDelCuerpoNoEsUnAdjunto() {
        $this->assertFalse(MailAttachment::isAttachment(null, 'text'));
    }

    /** `form-data` (adjuntos de formulario web) no basta por sí sola. */
    function testUnaDisposicionDesconocidaNoConvierteLaParteEnAdjunto() {
        $this->assertFalse(MailAttachment::isAttachment('form-data', 'text'));
    }

    /* ------------------------------------------------------------------ */
    /* El nombre: las ocho ramas en orden                                  */
    /* ------------------------------------------------------------------ */

    function testElNombreSaleDeFilename() {
        $this->assertSame('decoded:informe.pdf', $this->filename(array(
            'd_parameters' => array('filename' => 'informe.pdf'),
        )));
    }

    /** `filename` gana a `filename*` cuando están los dos. */
    function testFilenameGanaAFilenameEstrella() {
        $this->assertSame('decoded:informe.pdf', $this->filename(array(
            'd_parameters' => array(
                'filename' => 'informe.pdf',
                'filename*' => "utf-8''informe%20raro.pdf",
            ),
        )));
    }

    function testElNombreRfc5987SeDescodifica() {
        $this->assertSame("rfc5987:utf-8''informe%20raro.pdf", $this->filename(array(
            'd_parameters' => array('filename*' => "utf-8''informe%20raro.pdf"),
        )));
    }

    /** Hay clientes que no ponen disposición y sólo `name` en el tipo. */
    function testElNombreSaleDeNameDelContentType() {
        $this->assertSame('decoded:foto.jpg', $this->filename(array(
            'ctype_parameters' => array('name' => 'foto.jpg'),
        )));
    }

    function testElNombreSaleDeNameEstrella() {
        $this->assertSame('rfc5987:utf-8%27%27foto.jpg', $this->filename(array(
            'ctype_parameters' => array('name*' => 'utf-8%27%27foto.jpg'),
        )));
    }

    /** Último recurso: la cabecera en crudo, con `filename="…"`. */
    function testElNombreSaleDeLaCabeceraEnCrudo() {
        $this->assertSame('decoded:suelto.txt', $this->filename(array(
            'headers' => array(
                'content-disposition' => 'attachment; filename="suelto.txt"',
            ),
        )));
    }

    function testUnaImagenSinNombreRecibeUnoInventado() {
        $this->assertSame('image-xxxx.png', $this->filename(array(
            'headers' => array('content-id' => '<abc@servidor>'),
            'primary' => 'image',
            'secondary' => 'PNG',
        )));
    }

    /** Una imagen con nombre no entra en la rama anterior. */
    function testUnaImagenConNombreConservaElSuyo() {
        $this->assertSame('decoded:logo.png', $this->filename(array(
            'headers' => array('content-id' => '<abc@servidor>'),
            'd_parameters' => array('filename' => 'logo.png'),
            'primary' => 'image',
            'secondary' => 'png',
        )));
    }

    /** `message/rfc822` usa el asunto del mensaje adjunto y acaba en `.eml`. */
    function testUnMensajeAdjuntoSeLlamaComoSuAsunto() {
        $this->assertSame('decoded:Re: factura.eml', $this->filename(array(
            'primary' => 'message',
            'secondary' => 'rfc822',
            'parts' => array(array(
                'headers' => array('subject' => 'Re: factura'),
            )),
        )));
    }

    function testUnMensajeAdjuntoSinAsuntoRecibeUnNombreGenerico() {
        $this->assertSame('email-message-xxxx.eml', $this->filename(array(
            'primary' => 'message',
            'secondary' => 'rfc822',
        )));
    }

    /** Forma fragmentada: se concatenan los trozos en el orden recibido. */
    function testLosTrozosFilenameNumeradosSeConcatenan() {
        $this->assertSame('rfc5987:a.jpgrfc5987:b.jpg', $this->filename(array(
            'headers' => array(
                'content-disposition' => 'attachment; filename*0="a.jpg"; filename*1="b.jpg";',
            ),
            'd_parameters' => array(
                'filename*0' => 'a.jpg',
                'filename*1' => 'b.jpg',
            ),
        )));
    }

    /** Una parte que no es adjunto devuelve `false`, no un nombre vacío. */
    function testSinNingunaPistaNoHayNombre() {
        $this->assertFalse($this->filename(array(
            'primary' => 'text',
            'secondary' => 'plain',
        )));
    }

    /** `filename*` tiene prioridad sobre la cabecera en crudo: no se mezclan. */
    function testLaCabeceraEnCrudoNoPisaUnNombreEstructurado() {
        $this->assertSame('rfc5987:utf-8%27%27b.pdf', $this->filename(array(
            'headers' => array(
                'content-disposition' => 'attachment; filename="a.pdf"',
            ),
            'd_parameters' => array('filename*' => 'utf-8%27%27b.pdf'),
        )));
    }

    /* ------------------------------------------------------------------ */
    /* Tipo y Content-Id                                                   */
    /* ------------------------------------------------------------------ */

    function testElTipoSeGuardaEnMinusculasConBarra() {
        $this->assertSame('application/pdf',
            MailAttachment::type('Application', 'PDF'));
    }

    function testElContentIdPierdeLosAngulos() {
        $this->assertSame('abc@servidor',
            MailAttachment::contentId('<abc@servidor>'));
    }

    function testSinContentIdQuedaFalse() {
        $this->assertFalse(MailAttachment::contentId(null));
        $this->assertFalse(MailAttachment::contentId(''));
    }

    /* ------------------------------------------------------------------ */
    /* La respuesta completa: describe()                                   */
    /* ------------------------------------------------------------------ */

    function testDescribeDevuelveNombreYTipo() {
        $this->assertSame(
            array('name' => 'decoded:informe.pdf', 'type' => 'application/pdf'),
            MailAttachment::describe(array(
                'd_parameters' => array('filename' => 'informe.pdf'),
                'primary' => 'Application',
                'secondary' => 'PDF',
            ), $this->tools()));
    }

    /** Un texto del cuerpo no es adjunto: `describe()` dice que no. */
    function testDescribeDiceQueNoParaElCuerpo() {
        $this->assertFalse(MailAttachment::describe(array(
            'primary' => 'text',
            'secondary' => 'plain',
        ), $this->tools()));
    }

    /** Adjunto declarado pero sin nombre: tampoco se puede guardar. */
    function testDescribeDiceQueNoSinNombre() {
        $this->assertFalse(MailAttachment::describe(array(
            'disposition' => 'attachment',
            'primary' => 'text',
            'secondary' => 'plain',
        ), $this->tools()));
    }

    /**
     * `part()` rellena lo que la parte no trae: quien decide no debería tener
     * que preguntar por `isset()` en cinco sitios.
     */
    function testPartRellenaLoQueFalta() {
        $part = MailAttachment::part(new stdClass());

        $this->assertSame(array(), $part['d_parameters']);
        $this->assertSame(array(), $part['ctype_parameters']);
        $this->assertSame(array(), $part['headers']);
        $this->assertSame(array(), $part['parts']);
        $this->assertSame(null, $part['disposition']);
        $this->assertSame('', $part['primary']);
        $this->assertSame('', $part['secondary']);
    }

    /** El tamaño se cuenta en bytes, no en caracteres. */
    function testSizeCuentaBytes() {
        $this->assertSame(4, MailAttachment::size('abcd'));
        // «ñ» son dos bytes en UTF-8: el tamaño que se guarda es el de los
        // datos, no el del texto que ve el usuario.
        if (extension_loaded('mbstring'))
            $this->assertSame(2, MailAttachment::size('ñ'));
    }

    /* ------------------------------------------------------------------ */
    /* El método que las usaba                                              */
    /* ------------------------------------------------------------------ */

    /**
     * `getAttachments()` tiene que haber bajado de las 127 líneas: si vuelve
     * a crecer, es que las ramas del nombre han vuelto a él.
     */
    function testGetAttachmentsYaNoEsUnMetodoGigante() {
        $source = file_get_contents(INCLUDE_DIR . 'class.mailparse.php');
        $lines = explode("\n", $source);
        $start = null;

        foreach ($lines as $i => $line)
            if (strpos($line, 'function getAttachments(') !== false) {
                $start = $i;
                break;
            }

        $this->assertTrue($start !== null, 'no se encuentra getAttachments()');

        $end = $start;
        for ($i = $start + 1; $i < count($lines); $i++)
            if ($lines[$i] === '    }') {
                $end = $i;
                break;
            }

        $this->assertTrue($end - $start + 1 < 80,
            'getAttachments() mide ' . ($end - $start + 1) . ' líneas');
    }

    /** Y las decisiones tienen que estar fuera, en la clase nueva. */
    function testLasDecisionesEstanEnLaClaseNueva() {
        $source = file_get_contents(INCLUDE_DIR . 'class.mailparse.php');

        $this->assertTrue(strpos($source, 'MailAttachment::describe(') !== false);
        $this->assertTrue(strpos($source, 'require_once(INCLUDE_DIR.\'class.mail.attachment.php\')') !== false);
        // Las ramas del nombre ya no pueden volver al método que recorre.
        $this->assertFalse(strpos($source, 'decodeRfc5987(') !== false
            && strpos($source, 'Format::decodeRfc5987(') !== false);
    }
}

/**
 * Las mismas decisiones, pero con correos de verdad y el decodificador MIME
 * de la aplicación (`Mail_Parse`). Es la red que faltaba: `getAttachments()`
 * sólo se ejercitaba cuando llegaba un correo real al buzón.
 *
 * Se cargan las clases que necesita el decodificador; `class.orm.php` entra
 * por `class.priority.php`, igual que en `main.inc.php`.
 */
require_once INCLUDE_DIR . 'class.orm.php';
require_once INCLUDE_DIR . 'class.crypto.php';
require_once INCLUDE_DIR . 'class.mailparse.php';

class MailParseAttachmentsTest extends UnitTestCase {

    /** Devuelve los adjuntos como «nombre|tipo|tamaño». */
    private function attachments($raw) {
        $parse = new Mail_Parse($raw, 'utf-8');
        $this->assertTrue($parse->decode(), 'el correo no se decodifica');

        $out = array();
        foreach ($parse->getAttachments() as $file)
            $out[] = $file['name'] . '|' . $file['type'] . '|' . strlen($file['data']);

        return $out;
    }

    private function headers($contentType, $extra = '') {
        return "From: a@b.c\r\nTo: d@e.f\r\nSubject: hola\r\nMIME-Version: 1.0\r\n"
            . "Content-Type: " . $contentType . "\r\n" . $extra . "\r\n";
    }

    function testUnCorreoDeSoloTextoNoTieneAdjuntos() {
        $this->assertSame(array(), $this->attachments(
            $this->headers('text/plain; charset=utf-8') . 'solo texto'));
    }

    function testUnPdfYUnaImagenSeReconocenPorSuNombreYTipo() {
        $raw = $this->headers('multipart/mixed; boundary=BND')
            . "--BND\r\nContent-Type: text/plain; charset=utf-8\r\n\r\ncuerpo\r\n"
            . "--BND\r\nContent-Type: application/pdf\r\n"
            . "Content-Disposition: attachment; filename=\"informe.pdf\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode('PDFDATA'), 76, "\r\n")
            . "--BND\r\nContent-Type: image/png; name=\"logo.png\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode('PNGDATA'), 76, "\r\n")
            . "--BND--\r\n";

        $this->assertSame(array(
            'informe.pdf|application/pdf|7',
            'logo.png|image/png|7',
        ), $this->attachments($raw));
    }

    /** Una imagen incrustada sin nombre recibe uno con su extensión. */
    function testUnaImagenIncrustadaSinNombreRecibeUno() {
        $raw = $this->headers('multipart/related; boundary=BND')
            . "--BND\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>x</p>\r\n"
            . "--BND\r\nContent-Type: image/gif\r\nContent-Disposition: inline\r\n"
            . "Content-ID: <cid1>\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode('GIFDATA'), 76, "\r\n")
            . "--BND--\r\n";

        $files = (new Mail_Parse($raw, 'utf-8'));
        $this->assertTrue($files->decode());

        foreach ($files->getAttachments() as $file) {
            $this->assertSame(1, preg_match('/^image-[a-z0-9_=]{4}\.gif$/i',
                $file['name']), 'nombre: ' . $file['name']);
            $this->assertSame('image/gif', $file['type']);
            $this->assertSame('GIFDATA', $file['data']);
            // El Content-Id se guarda sin los ángulos.
            $this->assertSame('cid1', $file['cid']);

            return;
        }

        $this->fail('no se encontró la imagen incrustada');
    }

    /** RFC 5987: `name*=utf-8''informe%20raro.pdf` llega descodificado. */
    function testElNombreRfc5987SeDescodificaEnUnCorreoReal() {
        $raw = $this->headers(
            "application/pdf; name*=utf-8''informe%20raro.pdf",
            "Content-Disposition: attachment\r\n") . 'PDF';

        $this->assertSame(array('informe raro.pdf|application/pdf|3'),
            $this->attachments($raw));
    }

    /**
     * El lector de TNEF declara los tipos de retorno de `Iterator`.
     *
     * Sin ellos, PHP 8.1+ deja cinco avisos de obsolescencia en el log por
     * cada `winmail.dat` que entra —y `class.mailparse.php` lo carga siempre—.
     */
    function testElLectorTnefDeclaraSusTiposDeIterador() {
        $expected = array(
            'next' => 'void',
            'rewind' => 'void',
            'valid' => 'bool',
            'current' => 'mixed',
            'key' => 'mixed',
        );

        foreach (array('TnefStreamReader', 'TnefAttributeStreamReader')
                as $class) {
            foreach ($expected as $method => $type) {
                $return = (new ReflectionMethod($class, $method))
                    ->getReturnType();
                $this->assertTrue($return !== null,
                    "$class::$method() no declara tipo de retorno");
                $this->assertSame($type, (string) $return,
                    "$class::$method() devuelve $type");
            }
        }
    }

    /** Un `message/rfc822` adjunto se llama como el asunto que lleva dentro. */
    function testUnMensajeAdjuntoSeLlamaComoSuAsunto() {
        $raw = $this->headers('multipart/mixed; boundary=BND')
            . "--BND\r\nContent-Type: text/plain\r\n\r\ncuerpo\r\n"
            . "--BND\r\nContent-Type: message/rfc822\r\n"
            . "Content-Disposition: attachment\r\n\r\n"
            . "Subject: La factura de marzo\r\nFrom: x@y.z\r\nTo: a@b.c\r\n"
            . "\r\ntexto interior\r\n"
            . "--BND--\r\n";

        $names = array();
        foreach ($this->attachments($raw) as $entry) {
            list($name, $type) = explode('|', $entry);
            $names[] = $name . '|' . $type;
        }

        $this->assertSame(array('La factura de marzo.eml|message/rfc822'), $names);
    }
}
