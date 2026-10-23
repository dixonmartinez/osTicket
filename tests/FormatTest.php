<?php
/**
 * tests/FormatTest.php
 *
 * Pruebas de caracterización de `Format` (frente 1 de docs/CODE_QUALITY.md).
 *
 * Qué son: fotos del comportamiento actual. No dicen «esto es lo correcto»,
 * dicen «esto es lo que hace hoy». Si un refactor cambia el resultado, la
 * prueba falla y obliga a decidir si el cambio era intencionado.
 *
 * Sólo se prueban funciones sin base de datos ni hora del sistema: los casos
 * tienen que dar el mismo resultado en cualquier equipo y a cualquier hora.
 */
class FormatTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* Tamaños de archivo                                                  */
    /* ------------------------------------------------------------------ */

    public function testFileSizeUsaBytesHasta1023(): void {
        $this->assertSame('1023 bytes', Format::file_size(1023));
        $this->assertSame('0 bytes', Format::file_size(0));
    }

    public function testFileSizePasaAKbYMb(): void {
        $this->assertSame('1 kb', Format::file_size(1024));
        $this->assertSame('1.5 kb', Format::file_size(1536));
        $this->assertSame('1 mb', Format::file_size(1048576));
        $this->assertSame('2.5 mb', Format::file_size(2621440));
    }

    public function testFileSizeNoTocaLoQueNoEsNumero(): void {
        $this->assertSame('desconocido', Format::file_size('desconocido'));
    }

    public function testFilesize2bytesEntiendeLosSufijos(): void {
        $this->assertSame(1024, Format::filesize2bytes('1k'));
        $this->assertSame(1024, Format::filesize2bytes('1K'));
        $this->assertSame(2097152, Format::filesize2bytes('2M'));
        $this->assertSame(1073741824, Format::filesize2bytes('1G'));
    }

    /* ------------------------------------------------------------------ */
    /* Teléfonos y texto                                                   */
    /* ------------------------------------------------------------------ */

    public function testPhoneFormateaDiezDigitos(): void {
        $this->assertSame('(555) 123-4567', Format::phone('5551234567'));
        $this->assertSame('(555) 123-4567', Format::phone('555.123.4567'));
    }

    public function testPhoneFormateaSieteDigitos(): void {
        $this->assertSame('555-1234', Format::phone('5551234'));
    }

    public function testPhoneDejaIntactoLoQueNoReconoce(): void {
        $this->assertSame('12', Format::phone('12'));
        $this->assertSame('ext. 12', Format::phone('ext. 12'));
        // Con 11 dígitos (prefijo internacional) tampoco adivina el formato
        $this->assertSame('+1 (555) 123-4567', Format::phone('+1 (555) 123-4567'));
    }

    public function testTruncateCortaPorPalabra(): void {
        $this->assertSame('The quick ...',
            Format::truncate('The quick brown fox', 10));
    }

    public function testTruncateDuroCortaExacto(): void {
        $this->assertSame('The quick ',
            Format::truncate('The quick brown fox', 10, true));
    }

    public function testTruncateNoTocaLoQueYaCabe(): void {
        $this->assertSame('corto', Format::truncate('corto', 100));
        $this->assertSame('corto', Format::truncate('corto', 0));
    }

    public function testMaskTapaElRangoIndicado(): void {
        $this->assertSame('****567890', Format::mask('1234567890', 0, 4));
        $this->assertSame('123****890', Format::mask('1234567890', 3, 4));
        $this->assertSame('##########', Format::mask('1234567890', 0, null, '#'));
    }

    public function testShroudDejaSoloElPrefijo(): void {
        $this->assertSame('****', Format::shroud('1234567890', 0, 4));
    }

    public function testStripSlashesRecorreArrays(): void {
        $this->assertSame("O'Reilly", Format::strip_slashes("O\\'Reilly"));
        $this->assertSame(
            array('a' => "O'Reilly", 'b' => 'comillas "dobles"'),
            Format::strip_slashes(array('a' => "O\\'Reilly", 'b' => 'comillas "dobles"')));
    }

    public function testArrayImplodeUneClaveYValor(): void {
        $this->assertSame('a=1&b=x,y', Format::array_implode('=', '&',
            array('a' => '1', 'b' => array('x', 'y'))));
        $this->assertSame('sin array', Format::array_implode('=', '&', 'sin array'));
    }

    /* ------------------------------------------------------------------ */
    /* Escapado de HTML                                                    */
    /* ------------------------------------------------------------------ */

    public function testHtmlcharsEscapaEtiquetasYComillasDobles(): void {
        $this->assertSame('&lt;b&gt;hola&lt;/b&gt;',
            Format::htmlchars('<b>hola</b>'));
        // ENT_COMPAT: las comillas simples se dejan como están
        $this->assertSame('&quot;x&quot; y \'y\'',
            Format::htmlchars('"x" y \'y\''));
    }

    public function testHtmlcharsNoEscapaDosVeces(): void {
        $this->assertSame('&lt;b&gt;', Format::htmlchars('&lt;b&gt;'));
    }

    public function testHtmlcharsRecorreArrays(): void {
        $this->assertSame(array('a' => '&lt;x&gt;'),
            Format::htmlchars(array('a' => '<x>')));
    }

    public function testHtmldecodeDeshaceElEscapado(): void {
        $this->assertSame('<b>hola</b>',
            Format::htmldecode('&lt;b&gt;hola&lt;/b&gt;'));
    }

    public function testStriptagsQuitaEtiquetas(): void {
        $this->assertSame('hola mundo', trim(Format::striptags('<p>hola</p> mundo')));
    }

    /* ------------------------------------------------------------------ */
    /* Identificadores                                                     */
    /* ------------------------------------------------------------------ */

    public function testSlugifyQuitaAcentosYEspacios(): void {
        $this->assertSame('hola-mundo', Format::slugify('Hola mundo'));
        $this->assertSame('ano-cafe', Format::slugify('Añó café'));
        $this->assertSame('ticket-123', Format::slugify('  Ticket #123  '));
    }

    public function testSlugifyNuncaDevuelveVacio(): void {
        $this->assertSame('n-a', Format::slugify('!!!'));
        $this->assertSame('n-a', Format::slugify('   '));
    }

    /* ------------------------------------------------------------------ */
    /* Cadenas de consulta                                                 */
    /* ------------------------------------------------------------------ */

    public function testHttpQueryStringConservaYFiltra(): void {
        $this->assertSame('a=1&b=2', Format::http_query_string('a=1&b=2'));
        // El filtro indica las claves que se quitan
        $this->assertSame('b=2', Format::http_query_string('a=1&b=2', array('a')));
    }

    public function testHttpQueryStringVuelveACodificar(): void {
        $this->assertSame('a=hola+mundo',
            Format::http_query_string('a=hola mundo'));
    }

    /* ------------------------------------------------------------------ */
    /* Valores nulos y vacíos                                              */
    /* ------------------------------------------------------------------ */

    public function testLosTextosNulosSeTratanComoVacios(): void {
        // Regresión: Format::sanitize(null) llegaba a preg_replace() con null,
        // que PHP 8.1 marca como obsoleto y PHP 9 rechazará.
        $this->assertSame('', Format::sanitize(null));
        $this->assertSame('', Format::sanitize(null, true));
        $this->assertSame('', Format::strip_emoticons(null));
        $this->assertSame('', Format::striptags(null));
        $this->assertSame('', Format::htmldecode(null));
        $this->assertSame('', Format::htmlchars(null));
    }

    public function testNingunTextoNuloEmiteAvisosDePhp(): void {
        $avisos = array();

        set_error_handler(function ($no, $str) use (&$avisos) {
            $avisos[] = $str;
            return true;
        }, E_ALL);

        try {
            Format::sanitize(null);
            Format::sanitize(null, true);
            Format::strip_emoticons(null);
            Format::striptags(null);
            Format::htmldecode(null);
            Format::localizeInlineImages(null);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(array(), $avisos);
    }

    public function testElCeroNoSeConfundeConVacio(): void {
        // (string) 0 es '0', que es un texto válido y no un valor perdido
        $this->assertSame('0', Format::sanitize(0));
        $this->assertSame('0', Format::strip_emoticons(0));
    }

    public function testSanitizeSigueQuitandoLoPeligroso(): void {
        // El cambio de arriba no debe relajar el escapado
        $limpio = Format::sanitize('<script>alert(1)</script>Hola', true);

        $this->assertFalse(strpos($limpio, '<script') !== false);
        $this->assertTrue(strpos($limpio, 'Hola') !== false);
    }

    public function testLocalizeInlineImagesConTextoNormal(): void {
        // Sin imágenes de archivo.php, el texto se queda igual
        $this->assertSame('hola', Format::localizeInlineImages('hola'));
    }
}
