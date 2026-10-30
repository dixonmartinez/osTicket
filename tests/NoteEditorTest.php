<?php
/**
 * tests/NoteEditorTest.php
 *
 * El editor de notas: los campos memo de los formularios («Internal Notes» del
 * diálogo *Add New Organization*) y la nota rápida de la ficha de una
 * organización.
 *
 * Origen: una captura del usuario con el asunto «El editor de nota no está
 * enriquecido». En las imágenes se veía:
 *
 *   1. El *Internal Notes* del diálogo como una caja clara sin barra: el campo
 *      llegaba con `richtext no-bar`, y `no-bar` monta la barra **flotante**
 *      (`air`), que no aparece hasta que se selecciona texto. A ojos de quien
 *      escribe, un textarea plano.
 *   2. La nota rápida de la ficha con una barra de siete botones: se montaba
 *      con `T.redactor({focusEnd: true})` a pelo, es decir con la barra de
 *      fábrica de Redactor, sin los botones ni los plugins de osTicket.
 *   3. Las dos cajas en «papel» claro (`--dk-editor-paper`) dentro de una
 *      pantalla oscura.
 *
 * Las tres cosas se comprueban leyendo el código y las hojas: en `make ci` no
 * hay navegador. Lo que se verifica es que el campo se marca, que el editor lo
 * monta el código común y que el tema oscuro pinta la superficie.
 */

class NoteEditorTest extends UnitTestCase {

    private function fichero($ruta): string {
        return (string) file_get_contents(ROOT_DIR . '/' . $ruta);
    }

    private function sinComentarios($css): string {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /* ------------------------------------------------------------------ */
    /* El campo memo de los formularios                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Un campo memo con HTML se dibuja con barra visible y con la marca que
     * usa el tema para saber que es un editor de nota.
     */
    public function testElCampoMemoLlevaBarraVisibleYLaMarcaDeNota(): void {
        $widget = $this->fichero('include/class.forms.widget.php');

        $this->assertStringContainsString(
            "\$class = array('richtext', 'ost-note-editor', 'ost-memo-field');",
            $widget,
            'el campo memo tiene que salir con `richtext`, con la marca '
            . '`ost-note-editor` que lee el tema oscuro y con `ost-memo-field`, '
            . 'que es la que le da la barra corta');
        $this->assertFalse(strpos($widget, "'no-bar'") !== false,
            '`no-bar` monta la barra flotante: el campo parece un textarea '
            . 'plano hasta que se selecciona texto');
    }

    /**
     * Un memo sin HTML (el `address` de la organización, por ejemplo) sigue
     * siendo un textarea normal: las clases del editor van dentro del
     * `if ($v)` de la rama `html`.
     */
    public function testElCampoSinHtmlSigueSiendoUnTextareaNormal(): void {
        $widget = $this->fichero('include/class.forms.widget.php');

        // La rama `html` va de `case 'html':` a su `break;`
        $inicio = strpos($widget, "case 'html':");
        $fin = $inicio === false ? false : strpos($widget, 'break;', $inicio);
        $rama = ($fin === false) ? '' : substr($widget, $inicio, $fin - $inicio);

        $this->assertStringContainsString("if (\$v) {", $rama,
            'las clases del editor tienen que depender de `html`: un campo sin '
            . 'HTML no puede salir como editor enriquecido');
        $this->assertStringContainsString("'ost-note-editor'", $rama,
            'la marca del editor de nota se coló fuera de la rama `html`');
        $this->assertStringContainsString("\$class[] = @\$config['size'] ?: 'small';",
            $widget, 'el tamaño del campo memo se perdió');
    }

    /* ------------------------------------------------------------------ */
    /* La nota rápida de la ficha                                         */
    /* ------------------------------------------------------------------ */

    /**
     * La nota rápida se monta con el mismo editor que el resto de la
     * aplicación: si se llama a `T.redactor()` a pelo, la barra es la de
     * fábrica (siete botones) y no la de osTicket.
     */
    public function testLaNotaRapidaUsaElEditorComun(): void {
        $js = $this->fichero('scp/js/scp.js');

        $this->assertSame(2, substr_count($js, 'T.redact({ focusEnd: true })'),
            'los dos caminos de la nota rápida (editar y crear) tienen que '
            . 'montar el editor con `redact()`');
        $this->assertFalse(
            strpos($js, 'T.redactor({ focusEnd: true })') !== false,
            'la nota rápida sigue llamando a Redactor directamente: la barra '
            . 'sale con los botones de fábrica');
        $this->assertSame(2,
            substr_count($js, '<textarea class="richtext ost-note-editor">'),
            'la nota rápida tiene que crear el textarea marcado como editor '
            . 'de nota (y con `richtext` para que lo limpien los cambios de '
            . 'página)');
    }

    /** `redact()` acepta opciones cuando se usa como plugin de jQuery. */
    public function testElEditorComunSePuedeMontarSuelto(): void {
        $js = $this->fichero('js/redactor-osticket.js');

        $this->assertStringContainsString('$.fn.redact = function(options) {', $js,
            'falta el puente para montar un editor suelto: '
            . '`$(textarea).redact({focusEnd: true})`');
        $this->assertMatchesRegularExpression(
            '/\$\.fn\.redact = function\(options\) \{\s*return this\.each\(function\(\) \{\s*redact\(this, options\);/s',
            $js, 'el puente de jQuery no llama a `redact()` con las opciones');
    }

    /** La marca viaja del `<textarea>` a la caja que dibuja Redactor. */
    public function testLaMarcaDelEditorLlegaALaCaja(): void {
        $js = $this->fichero('js/redactor-osticket.js');

        $this->assertMatchesRegularExpression(
            "/if \(el\.hasClass\('ost-note-editor'\)\)\s*\n\s*el\.closest\('\.redactor-box'\)\.addClass\('ost-note-editor'\);/",
            $js, 'la caja de Redactor no recibe la marca: el tema no puede '
            . 'distinguir un editor de nota de un editor de correo');
    }

    /* ------------------------------------------------------------------ */
    /* El editor dentro de un diálogo                                      */
    /* ------------------------------------------------------------------ */

    /**
     * La barra completa son 23 botones: en la celda de un formulario o en la
     * caja de un diálogo se parte en dos filas. Ahí se usa la lista corta, con
     * los mismos botones de formato que tenía la barra flotante.
     */
    public function testLaBarraCortaSeUsaEnCamposYDialogos(): void {
        $js = $this->fichero('js/redactor-osticket.js');

        $this->assertMatchesRegularExpression(
            "/editorButtons = \{\s*air: \[[^\]]*\],\s*short: \[[^\]]*\],/s",
            $js, 'falta la lista `short` de botones');
        $this->assertStringContainsString(
            "el.hasClass('no-bar') ? 'air'", $js,
            'el modo `air` tiene que salir de la clase `no-bar`');
        $this->assertStringContainsString("|| el.hasClass('ost-memo-field')", $js,
            'los campos memo no piden la barra corta: en una celda de 400 px la '
            . 'barra completa se parte en dos filas');
        $this->assertStringContainsString("'toolbarFixed': !inDialog", $js,
            'la barra de un editor dentro de un diálogo no se puede pegar a la '
            . 'ventana: el diálogo ya tiene su propio desplazamiento');
    }

    /** Todos los botones de la lista corta existen en el editor. */
    public function testLosBotonesDeLaBarraCortaExisten(): void {
        $js = $this->fichero('js/redactor-osticket.js');

        if (!preg_match('/editorButtons\s*=\s*\{(.*?)\n\s{8}\},/s', $js, $m))
            $this->fail('no se encontró la tabla de botones del editor');
        if (!preg_match('/\bshort:\s*\[([^\]]*)\]/s', $m[1], $corta))
            $this->fail('no se encontró la lista corta de botones');

        preg_match_all("/'([a-zA-Z|]+)'/", $corta[1], $nombres);
        $corta = $nombres[1];

        $this->assertGreaterThan(5, count($corta),
            'la barra corta se quedó sin botones');
        foreach (array('bold', 'italic', 'deleted', 'lists', 'link') as $boton)
            $this->assertTrue(in_array($boton, $corta, true),
                sprintf('la barra corta no trae «%s»: dentro del diálogo no se '
                    . 'podría dar formato', $boton));

        // Nada inventado: los botones salen de los del propio Redactor o de
        // los plugins que se cargan en esa misma variante.
        $min = $this->fichero('js/redactor.min.js');
        $plugins = $this->fichero('js/redactor-plugins.js');
        if (preg_match('/y\.buttons=\{(.*?)\},?var f=function/s', $min, $r))
            $core = $r[1];
        elseif (preg_match('/y\.buttons=\{(.*?)\};/s', $min, $r))
            $core = $r[1];
        else
            $this->fail('no se pudo leer `y.buttons` de js/redactor.min.js');

        preg_match_all('/([a-z]+):\{title:/', $core, $botonesCore);
        preg_match_all("/addButtonAuto\('([a-z]+)'/", $plugins, $botonesPlugin);
        $validos = array_merge($botonesCore[1], $botonesPlugin[1]);

        foreach ($corta as $boton) {
            if ($boton === '|')
                continue;
            $this->assertTrue(in_array($boton, $validos, true),
                sprintf('la barra corta usa «%s», que no existe en el editor',
                    $boton));
        }
    }

    /* ------------------------------------------------------------------ */
    /* El tema oscuro                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * El editor de nota se pinta sobre la superficie del tema, no sobre el
     * «papel» claro de los correos. El `!important` es necesario: la regla del
     * papel lo lleva, y Redactor escribe el fondo en línea al arrancar.
     */
    public function testElTemaOscuroPintaElEditorDeNotaComoLaFicha(): void {
        $css = $this->sinComentarios($this->fichero('css/dark.css'));

        $this->assertStringContainsString(
            '.redactor-box.ost-note-editor .redactor-in', $css,
            'el tema oscuro no distingue el editor de nota: sigue en papel claro');
        $this->assertMatchesRegularExpression(
            '/\.redactor-box\.ost-note-editor \.redactor-in[^{]*\{[^}]*'
            . 'background-color: var\(--dk-surface-2\) !important/s',
            $css, 'la superficie del editor de nota no gana a la regla del '
            . 'papel claro (necesita `--dk-surface-2` y `!important`)');
        $this->assertMatchesRegularExpression(
            '/\.redactor-box\.ost-note-editor > textarea[^{]*\{[^}]*'
            . 'background-color: var\(--dk-surface-2\)/s',
            $css, 'si el editor no llega a montarse, el textarea de la nota se '
            . 'queda en papel claro');

        // Y el texto, la ayuda y el enlace siguen leyéndose sobre esa
        // superficie.
        $this->assertMatchesRegularExpression(
            '/\.redactor-box\.ost-note-editor \.redactor-in[^{]*\{[^}]*'
            . 'color: var\(--dk-text\)/s',
            $css, 'el texto del editor de nota no tiene color del tema');
        $this->assertStringContainsString(
            '.redactor-box.ost-note-editor .redactor-in a', $css,
            'los enlaces del editor de nota se quedan con el azul de papel');
        $this->assertStringContainsString(
            '.redactor-box.ost-note-editor .redactor-in[placeholder]::before', $css,
            'el texto de ayuda del editor de nota se queda en gris de papel');
    }
}
