<?php
/**
 * tests/FileInputTest.php
 *
 * Pruebas de las decisiones que se toman antes de guardar un archivo
 * (`File::create()` → `FileInput`): descodificar, resolver la llamada
 * diferida, el tipo, el tamaño y cuándo deduplicar.
 *
 * `File::create()` recibe los datos de tres sitios distintos —el formulario,
 * la API y un correo entrante— y cada uno los manda a su manera. Esa
 * normalización es donde se colaban los `Undefined index` y los tamaños de
 * cero; aquí se fija sin tocar la base de datos.
 */

require_once INCLUDE_DIR . 'class.file.input.php';

class FileInputTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* Descodificar                                                        */
    /* ------------------------------------------------------------------ */

    function testLosDatosEnBase64SeDescodifican() {
        $file = FileInput::decode(array(
            'encoding' => 'base64',
            'data' => base64_encode('hola'),
        ));

        $this->assertSame('hola', $file['data']);
    }

    /** Sin `encoding` los datos viajan tal cual. */
    function testSinCodificacionLosDatosNoSeTocan() {
        $file = FileInput::decode(array('data' => 'hola'));

        $this->assertSame('hola', $file['data']);
    }

    /** Una codificación que no se conoce tampoco estropea los datos. */
    function testUnaCodificacionDesconocidaSeIgnora() {
        $file = FileInput::decode(array('encoding' => 'quoted-printable',
            'data' => 'hola'));

        $this->assertSame('hola', $file['data']);
    }

    /* ------------------------------------------------------------------ */
    /* Llamada diferida                                                    */
    /* ------------------------------------------------------------------ */

    function testLaLlamadaDiferidaSeResuelveUnaSolaVez() {
        $calls = 0;
        $file = FileInput::resolve(array('data_cbk' => function() use (&$calls) {
            $calls++;
            return 'contenido';
        }));

        $this->assertSame('contenido', $file['data']);
        $this->assertSame(1, $calls);
        // Y la llamada se quita: nadie la puede ejecutar dos veces.
        $this->assertFalse(isset($file['data_cbk']));

        $again = FileInput::resolve($file);
        $this->assertSame(1, $calls);
        $this->assertSame('contenido', $again['data']);
    }

    /** Si ya hay datos, la llamada no se ejecuta. */
    function testSiYaHayDatosLaLlamadaNoSeEjecuta() {
        $calls = 0;
        $file = FileInput::resolve(array(
            'data' => 'ya-está',
            'data_cbk' => function() use (&$calls) { $calls++; return 'no'; },
        ));

        $this->assertSame('ya-está', $file['data']);
        $this->assertSame(0, $calls);
    }

    /** Una `data_cbk` que no es llamable se ignora sin avisar. */
    function testUnaLlamadaQueNoEsLlamableSeIgnora() {
        $file = FileInput::resolve(array('data_cbk' => 'no-existe-esta-funcion'));

        $this->assertFalse(isset($file['data']));
    }

    /* ------------------------------------------------------------------ */
    /* Tipo                                                                */
    /* ------------------------------------------------------------------ */

    function testElTipoQueDiceElClienteManda() {
        $this->assertSame('image/png',
            FileInput::mimeType('image/png', function() { return 'text/plain'; }));
    }

    function testSiNoHayTipoSeDetecta() {
        $this->assertSame('text/plain',
            FileInput::mimeType('', function() { return 'text/plain'; }));
        $this->assertSame('text/plain',
            FileInput::mimeType(null, function() { return 'text/plain'; }));
    }

    /** Sin tipo y sin detector, el genérico. Es lo que se guarda hoy. */
    function testSinTipoNiDeteccionQuedaElGenerico() {
        $this->assertSame('application/octet-stream', FileInput::mimeType(''));
        $this->assertSame('application/octet-stream',
            FileInput::mimeType('', function() { return null; }));
    }

    /* ------------------------------------------------------------------ */
    /* Tamaño                                                             */
    /* ------------------------------------------------------------------ */

    /** Lo que mide el backend manda sobre el contenido. */
    function testElTamanoDelBackendTienePrioridad() {
        $this->assertSame(1024, FileInput::size('abcd', 1024));
    }

    /** Sin backend, se cuenta el contenido. */
    function testSinBackendSeCuentaElContenido() {
        $this->assertSame(4, FileInput::size('abcd', 0, false));
    }

    /** Sin datos ni backend no hay tamaño que inventar. */
    function testSinDatosNoHayTamano() {
        $this->assertNull(FileInput::size(null, 0, false));
        $this->assertNull(FileInput::size(null));
    }

    /** Con `mbstring`, la cuenta va en modo `8bit` (bytes, no caracteres). */
    function testConMbstringSeCuentaEnBytes() {
        if (!function_exists('mb_strlen'))
            throw new TestSkipped('mbstring no está cargado en este PHP');

        // «ñ» son dos bytes en UTF-8.
        $this->assertSame(2, FileInput::size('ñ', 0, true));
        $this->assertSame(1, FileInput::size('a', 0, true));
    }

    /* ------------------------------------------------------------------ */
    /* Deduplicar                                                          */
    /* ------------------------------------------------------------------ */

    function testSoloSeBuscaUnArchivoIgualSiSePideYDoneElTamano() {
        $file = array('signature' => 'abc', 'size' => 10);
        $this->assertTrue(FileInput::shouldLookup($file, true));
    }

    /** Sin deduplicar no se busca: antes se buscaba y se tiraba el resultado. */
    function testSinDeduplicarNoSeBusca() {
        $this->assertFalse(FileInput::shouldLookup(
            array('signature' => 'abc', 'size' => 10), false));
    }

    /** Un tamaño desconocido o cero no permite comparar. */
    function testSinTamanoNoSeBusca() {
        $this->assertFalse(FileInput::shouldLookup(array('signature' => 'abc'), true));
        $this->assertFalse(FileInput::shouldLookup(
            array('signature' => 'abc', 'size' => 0), true));
    }

    /* ------------------------------------------------------------------ */
    /* Atributos del modelo                                                */
    /* ------------------------------------------------------------------ */

    function testLosAtributosQueSeGuardan() {
        $attributes = FileInput::attributes(array(
            'type' => 'IMAGE/PNG',
            'name' => 'foto.png',
            'key' => 'clave',
            'signature' => 'firma',
            'size' => 2048,
        ), 'C');

        $this->assertSame(array(
            'type' => 'image/png',   // en minúsculas, como se guarda
            'name' => 'foto.png',
            'key' => 'clave',
            'ft' => 'C',
            'signature' => 'firma',
            'size' => 2048,
        ), $attributes);
    }

    /** Sin tamaño no se pone la clave: el tamaño se calcula después. */
    function testSinTamanoNoSeIncluyeLaClave() {
        $attributes = FileInput::attributes(array(
            'type' => 'text/plain', 'name' => 'a.txt', 'key' => 'k',
            'signature' => 'f',
        ));

        $this->assertFalse(array_key_exists('size', $attributes));
        $this->assertSame('T', $attributes['ft']);
    }
}
