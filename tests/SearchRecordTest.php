<?php
/**
 * tests/SearchRecordTest.php
 *
 * Pruebas de cómo se arma un registro del índice de búsqueda
 * (`Search::IndexOldStuff()` → `SearchRecord`).
 *
 * Cada tipo de contenido tenía su variante y estaban todas escritas a mano
 * dentro del mismo método de 137 líneas: el hilo se salta las entradas vacías,
 * el ticket no recorta el contenido, el usuario y la organización sí, y la
 * base de conocimiento pone las palabras clave delante de la pregunta. Aquí se
 * fijan esas diferencias para que el refactor no las borre.
 */

require_once INCLUDE_DIR . 'class.search.record.php';

class SearchRecordTest extends UnitTestCase {

    /** Normalizador de mentira: marca el texto para ver que se aplicó. */
    private function marker() {
        return function($text) { return 'buscable(' . $text . ')'; };
    }

    /* ------------------------------------------------------------------ */
    /* Piezas comunes                                                      */
    /* ------------------------------------------------------------------ */

    function testLosTrozosVaciosFuera() {
        $this->assertSame(array('uno', 'dos'),
            SearchRecord::parts(array('uno', '', null, 'dos', array('x'))));
    }

    function testElContenidoUneConSaltosDeLinea() {
        $this->assertSame("uno\ndos",
            SearchRecord::content(array('uno', 'dos')));
    }

    /** Usuarios y organizaciones recortan; los tickets no. */
    function testSoloAlgunosRecortanElContenido() {
        $this->assertSame('uno', SearchRecord::content(array(' uno '), true));
        $this->assertSame(' uno ', SearchRecord::content(array(' uno ')));
    }

    function testSinContenidoQuedaCadenaVacia() {
        $this->assertSame('', SearchRecord::content(array('', null)));
        $this->assertSame('', SearchRecord::content(array(), true));
    }

    /* ------------------------------------------------------------------ */
    /* Entradas del hilo                                                   */
    /* ------------------------------------------------------------------ */

    function testUnaEntradaSeIndexaConSuTipo() {
        $this->assertSame(array('H', 7, 'Asunto', 'Cuerpo'),
            SearchRecord::entry(7, 'Asunto', 'Cuerpo'));
    }

    /** Una entrada sin título ni cuerpo no aporta nada al índice. */
    function testUnaEntradaVaciaNoSeIndexa() {
        $this->assertNull(SearchRecord::entry(7, '', ''));
        $this->assertNull(SearchRecord::entry(7, null, null));
    }

    /** Con sólo uno de los dos sí se indexa. */
    function testBastaConTituloOBody() {
        $this->assertSame(array('H', 7, 'Asunto', ''),
            SearchRecord::entry(7, 'Asunto', ''));
        $this->assertSame(array('H', 7, '', 'Cuerpo'),
            SearchRecord::entry(7, '', 'Cuerpo'));
    }

    /* ------------------------------------------------------------------ */
    /* Tickets                                                             */
    /* ------------------------------------------------------------------ */

    /** El número y el asunto van juntos en el título, y se normalizan. */
    function testElTicketLlevaNumeroYAsuntoEnElTitulo() {
        $record = SearchRecord::ticket(12, '806347', 'No imprime',
            array('campo uno'), $this->marker());

        $this->assertSame(array('T', 12, 'buscable(806347 No imprime)',
            'campo uno'), $record);
    }

    /** Su contenido no se recorta, a diferencia del de usuarios. */
    function testElContenidoDelTicketNoSeRecorta() {
        $record = SearchRecord::ticket(1, '1', 'x', array(' con espacios '));

        $this->assertSame(' con espacios ', $record[3]);
    }

    function testUnTicketSinCamposQuedaConContenidoVacio() {
        $record = SearchRecord::ticket(1, '1', 'x', array());

        $this->assertSame('', $record[3]);
    }

    /* ------------------------------------------------------------------ */
    /* Usuarios y organizaciones                                           */
    /* ------------------------------------------------------------------ */

    /** El correo y los campos del formulario forman el contenido. */
    function testElUsuarioIndexaCorreosYCampos() {
        $record = SearchRecord::user(3, 'Ana Pérez',
            array('ana@example.com'), array('Gerente'), $this->marker());

        $this->assertSame(array('U', 3, 'buscable(Ana Pérez)',
            "ana@example.com\nGerente"), $record);
    }

    /** Y se recorta: una respuesta que acaba en espacios no deja hueco. */
    function testElContenidoDelUsuarioSeRecorta() {
        $record = SearchRecord::user(3, 'Ana', array(), array(' Gerente '));

        $this->assertSame('Gerente', $record[3]);
    }

    /** Un usuario sin correo ni campos sigue teniendo registro (con vacío). */
    function testUnUsuarioSinDatosSeIndexaVacio() {
        $record = SearchRecord::user(3, 'Ana', array(), array());

        $this->assertSame(array('U', 3, 'Ana', ''), $record);
    }

    function testLaOrganizacionSeIndexaConSuNombreYRecorta() {
        $record = SearchRecord::organization(4, 'Acme',
            array(' Fabricación '), $this->marker());

        $this->assertSame(array('O', 4, 'buscable(Acme)', 'Fabricación'), $record);
    }

    /* ------------------------------------------------------------------ */
    /* Base de conocimiento                                                */
    /* ------------------------------------------------------------------ */

    /** Las palabras clave van delante de la pregunta si las hay. */
    function testLasPalabrasClaveVanDelanteDeLaPregunta() {
        $record = SearchRecord::faq(9, '¿Cómo reinicio?', 'reinicio consola',
            'Pulsa el botón.', $this->marker());

        $this->assertSame(array('K', 9,
            'buscable(reinicio consola ¿Cómo reinicio?)', 'Pulsa el botón.'),
            $record);
    }

    /** Sin palabras clave, el título es la pregunta tal cual. */
    function testSinPalabrasClaveElTituloEsLaPregunta() {
        $record = SearchRecord::faq(9, '¿Cómo reinicio?', '', 'Pulsa.');

        $this->assertSame('¿Cómo reinicio?', $record[2]);
    }

    /* ------------------------------------------------------------------ */
    /* Los tipos son los que entiende el índice                            */
    /* ------------------------------------------------------------------ */

    function testLosTiposSonLosDelIndice() {
        $source = file_get_contents(INCLUDE_DIR . 'class.search.php');

        // La consulta de cada tipo filtra por su `object_type`.
        $this->assertStringContainsString("A2.`object_type`='H'", $source);
        $this->assertStringContainsString("A2.`object_type`='T'", $source);
        $this->assertStringContainsString("A2.`object_type`='U'", $source);
        $this->assertStringContainsString("A2.`object_type`='O'", $source);
        $this->assertStringContainsString("A2.`object_type`='K'", $source);
    }

    /** `IndexOldStuff()` pasó de 137 líneas a menos de 40. */
    function testElMetodoLargoQuedoPartido() {
        $source = file_get_contents(INCLUDE_DIR . 'class.search.php');
        $start = strpos($source, 'function IndexOldStuff()');
        $end = strpos($source, 'private function indexThreadEntries', $start);

        $this->assertTrue($start !== false && $end !== false);
        $block = substr($source, $start, $end - $start);
        $this->assertLessThan(45, substr_count($block, "\n"));

        // Y los cinco tipos tienen su método
        foreach (array('indexThreadEntries', 'indexTickets', 'indexUsers',
                'indexOrganizations', 'indexFaqs') as $method)
            $this->assertTrue(strpos($source, "function $method(") !== false,
                "Falta $method");
    }
}
