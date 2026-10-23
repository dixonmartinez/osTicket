<?php
/**
 * tests/ThreadEntryInfoTest.php
 *
 * Pruebas de las piezas puras de `ThreadEntry::create`, sacadas a
 * `ThreadEntryInfo` (frente 2 de docs/CODE_QUALITY.md).
 *
 * Lo que se prueba aquí es lo que decide cómo se ve una entrada en el hilo:
 * su título, su firma, si es «respuesta a todos» o nota de sistema, y qué
 * adjuntos van incrustados en el cuerpo.
 */
class ThreadEntryInfoTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* Título y firma                                                      */
    /* ------------------------------------------------------------------ */

    public function testElTituloPierdeElHtml(): void {
        // sanitize() con el segundo argumento quita las etiquetas
        $this->assertSame('Hola', ThreadEntryInfo::title('<b>Hola</b>'));
    }

    public function testElTituloAceptaNulos(): void {
        $this->assertSame('', ThreadEntryInfo::title(null));
    }

    public function testElTituloQuitaLosEmoticonos(): void {
        // osTicket convierte los emoticonos en imágenes; en el título sobran
        $this->assertFalse(strpos(ThreadEntryInfo::title('Hola :)'), '<img') !== false);
    }

    public function testLaFirmaAdmiteUnObjeto(): void {
        // Un Staff o un User se guarda como su versión en texto
        $this->assertSame('Ana', ThreadEntryInfo::poster(new ThreadEntryFakeName('Ana')));
    }

    public function testLaFirmaConservaElTexto(): void {
        // La firma se guarda como llegó (sólo se limpia en la vista)
        $this->assertSame('<i>Ana</i>', ThreadEntryInfo::poster('<i>Ana</i>'));
    }

    public function testLaFirmaVaciaSeQuedaVacia(): void {
        $this->assertSame('', ThreadEntryInfo::poster(''));
        $this->assertSame('', ThreadEntryInfo::poster(null));
    }

    /* ------------------------------------------------------------------ */
    /* Banderas                                                            */
    /* ------------------------------------------------------------------ */

    public function testSinDestinatariosNoHayBanderaDeRespuesta(): void {
        $this->assertSame(0, ThreadEntryInfo::replyFlags(null));
        $this->assertSame(0, ThreadEntryInfo::replyFlags(array()));
    }

    public function testUnDestinatarioEsRespuestaAlUsuario(): void {
        $this->assertSame(ThreadEntryInfo::FLAG_REPLY_USER,
            ThreadEntryInfo::replyFlags(array('ana@example.com')));
    }

    public function testVariosDestinatariosEsRespuestaATodos(): void {
        $this->assertSame(ThreadEntryInfo::FLAG_REPLY_ALL,
            ThreadEntryInfo::replyFlags(array('ana@example.com', 'luis@example.com')));
    }

    public function testLosDestinatariosAgrupadosSeCuentanUnoAUno(): void {
        // getEmailAddresses() devuelve listas por tipo (to, cc, bcc)
        $this->assertSame(ThreadEntryInfo::FLAG_REPLY_ALL,
            ThreadEntryInfo::replyFlags(array(
                'to' => array('ana@example.com'),
                'cc' => array('luis@example.com'),
            )));
    }

    public function testElHtmlSeDaPorEquilibrado(): void {
        $flags = ThreadEntryInfo::bodyFlags('html', 5, 0);

        $this->assertTrue(($flags & ThreadEntryInfo::FLAG_BALANCED) != 0);
        $this->assertFalse(($flags & ThreadEntryInfo::FLAG_SYSTEM) != 0);
    }

    public function testElTextoPlanoHayQueEquilibrarlo(): void {
        $flags = ThreadEntryInfo::bodyFlags('text', 5, 0);

        $this->assertFalse(($flags & ThreadEntryInfo::FLAG_BALANCED) != 0);
    }

    public function testLoQueNoTieneAutorEsNotaDelSistema(): void {
        $this->assertTrue((ThreadEntryInfo::bodyFlags('text', 0, 0)
            & ThreadEntryInfo::FLAG_SYSTEM) != 0);
        $this->assertTrue((ThreadEntryInfo::bodyFlags('text', null, null)
            & ThreadEntryInfo::FLAG_SYSTEM) != 0);
    }

    public function testUnMensajeDelUsuarioNoEsNotaDelSistema(): void {
        $this->assertSame(0, ThreadEntryInfo::bodyFlags('text', 0, 7));
        $this->assertSame(0, ThreadEntryInfo::bodyFlags('text', 3, 0));
    }

    public function testLasBanderasCoincidenConLasDeThreadEntry(): void {
        $esperadas = array(
            'FLAG_COLLABORATOR' => 0x0020,
            'FLAG_BALANCED'     => 0x0040,
            'FLAG_SYSTEM'       => 0x0080,
            'FLAG_REPLY_ALL'    => 0x0100,
            'FLAG_REPLY_USER'   => 0x0200,
        );

        foreach ($esperadas as $nombre => $valor)
            $this->assertSame($valor, constant('ThreadEntryInfo::'.$nombre));
    }

    /* ------------------------------------------------------------------ */
    /* Adjuntos                                                           */
    /* ------------------------------------------------------------------ */

    public function testSeDescartanLasImagenesQueYaNoEstan(): void {
        $adjuntos = array(
            array('name' => 'foto.png', 'cid' => 'abc123'),
            array('name' => 'informe.pdf'),
        );

        $quedan = ThreadEntryInfo::dropStrippedImages($adjuntos, array('abc123'));

        $this->assertFalse(array_key_exists(0, $quedan));
        $this->assertSame('informe.pdf', $quedan[1]['name']);
    }

    public function testSinImagenesDescartadasNoSeTocaNada(): void {
        $adjuntos = array(array('name' => 'foto.png', 'cid' => 'abc123'));

        $this->assertSame($adjuntos,
            ThreadEntryInfo::dropStrippedImages($adjuntos, array()));
        $this->assertSame($adjuntos,
            ThreadEntryInfo::dropStrippedImages($adjuntos, null));
    }

    public function testLosAdjuntosSinCidNuncaSeDescartan(): void {
        $adjuntos = array(array('name' => 'informe.pdf'));

        $this->assertSame($adjuntos,
            ThreadEntryInfo::dropStrippedImages($adjuntos, array('abc123')));
    }

    public function testUnAdjuntoIncrustadoSeMarca(): void {
        $archivos = array(array('name' => 'foto.png', 'cid' => 'abc123'));
        $cuerpo = '<p>Mira</p><img src="cid:abc123">';

        $marcados = ThreadEntryInfo::markInline($archivos, $cuerpo);

        $this->assertTrue($marcados[0]['inline']);
    }

    public function testUnAdjuntoQueElCuerpoNoMencionaNoSeMarca(): void {
        $archivos = array(array('name' => 'informe.pdf', 'cid' => 'zzz999'));

        $marcados = ThreadEntryInfo::markInline($archivos, '<p>Sin imágenes</p>');

        $this->assertFalse(isset($marcados[0]['inline']));
    }

    public function testElCidSeBuscaTalCual(): void {
        // strpos distingue mayúsculas: el analizador de correo entrega el cid
        // tal como aparece en el cuerpo, así que no se normaliza
        $archivos = array(array('name' => 'foto.png', 'cid' => 'ABC123'));

        $marcados = ThreadEntryInfo::markInline($archivos, '<img src="cid:abc123">');

        $this->assertFalse(isset($marcados[0]['inline']));
    }

    public function testMarcarNoTocaLosQueNoTienenCid(): void {
        $archivos = array(array('name' => 'informe.pdf'));

        $marcados = ThreadEntryInfo::markInline($archivos, '<img src="cid:abc">');

        $this->assertSame($archivos, $marcados);
    }
}

/* Objeto con nombre, como Staff o User: lo único que hace falta es que se
   pueda convertir a texto. */
class ThreadEntryFakeName {
    private $name;

    function __construct($name) { $this->name = $name; }
    function __toString() { return $this->name; }
}
