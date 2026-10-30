<?php
/**
 * tests/EditorToolbarTest.php
 *
 * La barra del editor enriquecido y las superficies que la rodean.
 *
 * Origen: una tanda de capturas del usuario con la nota «hay cosas que se ven
 * mal» y la petición de «un editor más rico». Al abrirlas se vio que:
 *
 *   1. En el tema oscuro la barra del editor seguía siendo una franja casi
 *      blanca. La causa no era la falta de reglas, sino dos detalles:
 *      css/redactor.css pinta la barra con una regla de cuatro clases
 *      (`.redactor-box.redactor-styles-on .redactor-toolbar.redactor-toolbar`)
 *      que gana por especificidad a la del tema.
 *   2. Todas las reglas del tema apuntaban a `.redactor-editor`, una clase que
 *      **no existe** en Redactor 3: la superficie editable es `.redactor-in`.
 *      De ahí que el área de escritura no se tematizara nunca.
 *   3. El botón de pantalla completa estaba en la lista de botones pero su
 *      plugin no estaba en la lista de plugins, así que no llegaba a crearse.
 *
 * Esto se comprueba leyendo el JS y las hojas: no hay navegador en `make ci`.
 * Los botones se validan contra el propio Redactor (`y.buttons`) y contra los
 * plugins que los añaden, de modo que un nombre inventado no pasa la prueba
 * aunque el fichero sea sintácticamente correcto.
 */

class EditorToolbarTest extends UnitTestCase {

    private function fichero($ruta): string {
        return (string) file_get_contents(ROOT_DIR . '/' . $ruta);
    }

    /**
     * Una de las listas del editor (`editorButtons` / `editorPlugins`) según
     * la forma de montarlo: `air` (barra flotante), `short` (barra corta: un
     * campo memo o cualquier editor dentro de un diálogo) o `full` (barra
     * completa).
     */
    private function lista(string $constante, string $clave = 'full'): array {
        $js = $this->fichero('js/redactor-osticket.js');

        if (!preg_match('/' . preg_quote($constante, '/') . '\s*=\s*\{(.*?)\n\s{8}\},/s',
                $js, $m))
            return array();
        if (!preg_match('/\b' . preg_quote($clave, '/') . ':\s*\[([^\]]*)\]/s',
                $m[1], $n))
            return array();

        // El separador de grupo (`|`) también forma parte de la lista
        preg_match_all("/'([a-zA-Z|]+)'/", $n[1], $nombres);
        return $nombres[1];
    }

    /** Los botones de la barra completa (respuesta, artículos, correos). */
    private function botonesBarra(): array {
        return $this->lista('editorButtons', 'full');
    }

    /** Botones que trae el propio Redactor (el objeto `y.buttons`). */
    private function botonesDeRedactor(): array {
        $min = $this->fichero('js/redactor.min.js');

        if (!preg_match('/y\.buttons=\{(.*?)\},?var f=function/s', $min, $m)
                && !preg_match('/y\.buttons=\{(.*?)\};/s', $min, $m))
            return array();

        preg_match_all('/([a-z]+):\{title:/', $m[1], $nombres);
        return $nombres[1];
    }

    /** Botones que añaden los plugins (`addButtonAuto`, `addButtonAfter`…). */
    private function botonesDePlugins(): array {
        $js = $this->fichero('js/redactor-plugins.js');

        $botones = array();
        if (preg_match_all("/addButtonAuto\('([a-z]+)'/", $js, $m))
            $botones = array_merge($botones, $m[1]);
        if (preg_match_all("/addButton(?:Before|After)\(\s*'[a-z]+'\s*,\s*'([a-z]+)'/",
                $js, $m))
            $botones = array_merge($botones, $m[1]);

        return $botones;
    }

    /* ------------------------------------------------------------------ */
    /* Los botones                                                        */
    /* ------------------------------------------------------------------ */

    /** Ningún botón de la barra es un nombre inventado. */
    public function testTodosLosBotonesDeLaBarraExisten(): void {
        $barra = $this->botonesBarra();
        $this->assertGreaterThan(10, count($barra),
            'no se pudo leer la lista de botones de la barra');

        $validos = array_merge($this->botonesDeRedactor(), $this->botonesDePlugins());
        $this->assertGreaterThan(5, count($validos),
            'no se pudieron leer los botones del propio Redactor');

        foreach ($barra as $boton) {
            if ($boton === '|')
                continue;
            $this->assertTrue(in_array($boton, $validos, true),
                sprintf('el botón «%s» no existe en este Redactor: la barra lo '
                    . 'ignoraría en silencio', $boton));
        }
    }

    /** La barra tiene historia, sangría y pantalla completa. */
    public function testLaBarraTraeLosBotonesNuevos(): void {
        $barra = $this->botonesBarra();

        foreach (array('undo', 'redo', 'indent', 'outdent', 'sub', 'sup',
                'ul', 'ol', 'line', 'fullscreen') as $boton) {
            $this->assertTrue(in_array($boton, $barra, true),
                sprintf('falta el botón «%s» en la barra del editor', $boton));
        }
    }

    /**
     * Un botón que aporta un plugin solo aparece si el plugin está cargado.
     * Es lo que pasaba con `fullscreen`: estaba en `buttons` y no en
     * `plugins`, así que su `addButtonAuto` nunca se ejecutaba.
     */
    public function testLosPluginsQueAportanBotonesEstanCargados(): void {
        $plugins = $this->lista('editorPlugins', 'full');
        $this->assertGreaterThan(5, count($plugins),
            'no se encontró la lista de plugins del editor');

        foreach (array('imagemanager', 'table', 'video', 'definedlinks',
                'autolock', 'fontcolor', 'fontfamily', 'fullscreen') as $plugin) {
            $this->assertTrue(in_array($plugin, $plugins, true),
                sprintf('el plugin «%s» aporta botones y no se carga', $plugin));
        }
    }

    /**
     * El botón que añade un plugin no puede anclarse a un separador.
     *
     * `addButtonAuto()` inserta el botón detrás del que tiene delante en la
     * lista. Si ese anterior es `|`, Redactor busca `.re-|`, que no es un
     * selector CSS válido: jQuery lanza una excepción, la excepción sale del
     * `start()` del plugin y aborta el arranque del editor. El resultado que
     * vio el usuario: el área de escritura a medio montar, sin `.redactor-in`
     * y sin el papel del tema, con el texto invisible sobre el fondo oscuro.
     *
     * Pasaba con `fullscreen`, que va justo detrás de un `|`.
     */
    public function testElBotonAutomaticoNoUsaUnSeparadorComoAncla(): void {
        $js = $this->fichero('js/redactor-osticket.js');

        $this->assertMatchesRegularExpression(
            '/addButtonAuto:\s*function\s*\([^)]*\)\s*\{.*?\n\s{6}\},/s',
            $js, 'no se encontró `addButtonAuto` en el parche del editor');
        preg_match(
            '/addButtonAuto:\s*function\s*\([^)]*\)\s*\{.*?\n\s{6}\},/s',
            $js, $m);
        $cuerpo = $m[0];

        // El ancla no puede salir del índice directo de la lista: puede ser `|`
        $this->assertFalse(
            (bool) preg_match('/addButtonAfter\(\s*this\.opts\.buttons\[/', $cuerpo),
            '`addButtonAuto` ancla el botón en `this.opts.buttons[pos - 1]`, que '
            . 'puede ser el separador `|`: jQuery lanza «not a valid selector» y '
            . 'el editor se queda sin arrancar');
        $this->assertStringContainsString("=== '|'", $cuerpo,
            'falta el salto sobre los separadores al buscar el botón anterior');
        $this->assertStringContainsString('addButtonFirst', $cuerpo,
            'si no hay ningún botón real delante, el botón va al principio');

        // Y de verdad hay un botón de plugin detrás de un separador
        $barra = $this->botonesBarra();
        $detras = array();
        for ($i = 1; $i < count($barra); $i++)
            if ($barra[$i - 1] === '|')
                $detras[] = $barra[$i];
        $enRiesgo = array_intersect($detras, $this->botonesDePlugins());
        $this->assertTrue(count($enRiesgo) > 0,
            'la prueba no vale de nada si ningún botón de plugin va detrás de un '
            . 'separador');
    }

    /** La barra va por grupos y los grupos se dibujan. */
    public function testLosGruposSeSeparanConUnTrazo(): void {
        $this->assertTrue(in_array('|', $this->botonesBarra(), true),
            'la lista de botones no marca ningún grupo con `|`');

        $js = $this->fichero('js/redactor-osticket.js');
        $this->assertStringContainsString("buttons[i - 1] === '|'", $js,
            'falta el parche que convierte un `|` en un separador visible');
        $this->assertStringContainsString('redactor-separator', $js,
            'el separador no tiene marcado propio');

        // La separación es cosmética: si falla, la barra tiene que seguir
        // funcionando. Por eso va en try/catch.
        $this->assertMatchesRegularExpression('/try\s*\{[^}]*redactor-separator/s',
            $js, 'el separador tiene que poder fallar sin romper la barra');

        $css = $this->fichero('css/modern.css');
        $this->assertStringContainsString('.redactor-toolbar .redactor-separator {',
            $css, 'el separador no tiene estilo');
    }

    /* ------------------------------------------------------------------ */
    /* El tema oscuro                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * El área de escritura es `.redactor-in`. Las reglas viejas hablaban de
     * `.redactor-editor`, que no existe: eran código muerto.
     */
    public function testElTemaApuntaAlAreaDeEscrituraCorrecta(): void {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->fichero('css/dark.css'));

        $this->assertStringContainsString('.redactor-in', $css,
            'el tema oscuro no estiliza la superficie editable de Redactor');
        $this->assertFalse(strpos($css, '.redactor-editor') !== false,
            '`.redactor-editor` no existe en Redactor 3: la superficie '
            . 'editable es `.redactor-in`');
    }

    /** La barra del editor no puede quedarse blanca en oscuro. */
    public function testLaBarraGanaLaEspecificidadDeRedactor(): void {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->fichero('css/dark.css'));

        // Cuatro clases: las mismas que usa css/redactor.css para pintar la
        // barra, que se carga después que modern.css.
        $this->assertStringContainsString(
            '.redactor-box.redactor-styles-on .redactor-toolbar.redactor-toolbar',
            $css,
            'falta la regla que iguala la especificidad de css/redactor.css: '
            . 'sin ella la barra sigue saliendo clara en tema oscuro');
    }

    /** El asunto, la nota interna y el panel de respuesta, tematizados. */
    public function testLasSuperficiesDeLaFichaSeTematizan(): void {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->fichero('css/dark.css'));

        foreach (array('.tixTitle h3', '#response_options > form', '#note') as $selector) {
            $this->assertStringContainsString($selector, $css,
                sprintf('el tema oscuro no cubre «%s»', $selector));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Las tablas y la ficha                                              */
    /* ------------------------------------------------------------------ */

    /** La tabla de estadísticas pierde la rejilla doble. */
    public function testLaTablaDeEstadisticasTieneUnaSolaRejilla(): void {
        $css = $this->fichero('css/modern.css');

        $this->assertStringContainsString('table.dashboard-stats {', $css);
        $this->assertStringContainsString(
            'table.dashboard-stats td:not(:empty)', $css,
            'sin anular el blanco por celda de `.table`, la cebra no se ve');
        $this->assertStringContainsString(
            'table.dashboard-stats tbody:nth-child(2) td',
            $css, 'faltan los bordes verticales');
    }

    /** «Priority:» no puede quedarse sin valor. */
    public function testLaPrioridadSiempreSeMuestra(): void {
        $vista = $this->fichero('include/staff/ticket-view.inc.php');

        $this->assertStringContainsString('getPriorityId()', $vista,
            'no se cae a la prioridad por defecto del sistema');
        $this->assertStringContainsString('Priority::lookup(', $vista,
            'la prioridad por defecto no se resuelve');
        $this->assertStringContainsString("__('None')", $vista,
            'si no hay prioridad se dice, en vez de dejar el hueco');
        $this->assertFalse(
            strpos($vista, 'getAnswer()->display()') !== false,
            '`getAnswer()->display()` deja la fila vacía cuando el ticket no '
            . 'tiene respuesta guardada');
    }

    /** La base de conocimiento tiene buscador con pista y estado vacío. */
    public function testLaBaseDeConocimientoSeExplicaCuandoNoHayNada(): void {
        $vista = $this->fichero('include/staff/faq-categories.inc.php');

        $this->assertStringContainsString(
            'placeholder="<?php echo __(\'Search the knowledge base\'); ?>"',
            $vista, 'el buscador de la base de conocimiento no tiene pista');
        $this->assertSame(2, substr_count($vista, 'class="empty-state"'),
            'las dos salidas vacías (búsqueda sin resultados y sin categorías) '
            . 'tienen que usar el estado vacío de las colas');
    }
}
