<?php
/**
 * tests/AdminSettingsTest.php
 *
 * La parte de administración: Ajustes del sistema y Perfil de la empresa.
 *
 * Origen: una tanda de capturas del usuario con la nota «comencemos con la
 * parte de administración — aplica corrección del código y mejoras visuales,
 * que sea intuitiva y moderna». Al abrirlas se vio:
 *
 *   1. La banda de aviso del sistema salía como «⚠The setup directory…»: el
 *      icono que dibuja `css/ui.css` con un `::before` en `left: 10px` caía
 *      encima del texto porque la banda sólo tenía 14 px de relleno.
 *   2. «Save Changes» y «Reset Changes» eran dos botones idénticos, centrados
 *      y lejos de la tabla; en oscuro, además, el principal perdía el acento
 *      porque `css/dark.css` pinta todos los botones con la superficie del
 *      tema.
 *   3. Las casillas de verificación no se podían marcar desde su etiqueta
 *      (no había `<label for>`), y la tabla de ajustes dejaba una franja
 *      muerta a la derecha (pide `width="940"`).
 *   4. En *Logos* y *Login Backdrop*, el borrado se confirmaba con un
 *      manejador enganchado a `#save`, un formulario que no existe: marcar
 *      «Delete» y guardar borraba la imagen sin preguntar.
 *   5. Los desplegables de purga y de tamaño de adjunto tenían opciones
 *      marcadas a la vez, porque «Never Purge Logs» y «Small» llevaban
 *      `selected` fijo en el HTML.
 *
 * Todo se comprueba leyendo plantillas y hojas: en `make ci` no hay navegador.
 */

class AdminSettingsTest extends UnitTestCase {

    private function fichero($ruta): string {
        return (string) file_get_contents(ROOT_DIR . '/' . $ruta);
    }

    private function sinComentarios($css): string {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /** Las pantallas de ajustes y la de plantillas de correo. */
    private function pantallas(): array {
        return array('settings-agents', 'settings-emails', 'settings-kb',
            'settings-pages', 'settings-system', 'settings-tasks',
            'settings-tickets', 'settings-users', 'template');
    }

    /* ------------------------------------------------------------------ */
    /* 1. Avisos del sistema                                              */
    /* ------------------------------------------------------------------ */

    /**
     * El icono del aviso se dibuja con un `::before` a 10 px del borde; la
     * banda tiene que reservar ese hueco o el texto empieza debajo del icono.
     */
    public function testLaBandaDeAvisoReservaElHuecoDelIcono(): void {
        $css = $this->sinComentarios($this->fichero('css/modern.css'));

        $this->assertMatchesRegularExpression(
            '/#warning_bar,[^{]*\{[^}]*padding-left:\s*36px/s', $css,
            'la banda de aviso no reserva el hueco del icono: el texto sale '
            . 'pegado (o debajo) del triángulo de aviso');

        // El icono es de css/ui.css; si cambia de tamaño, el hueco cambia.
        $ui = $this->fichero('css/ui.css');
        $this->assertStringContainsString('left: 10px;', $ui,
            'css/ui.css movió el icono del aviso: hay que revisar el relleno');
        $this->assertStringContainsString('width: 16px;', $ui,
            'css/ui.css cambió el tamaño del icono del aviso');
    }

    /* ------------------------------------------------------------------ */
    /* 2. Barra de acciones                                               */
    /* ------------------------------------------------------------------ */

    /** Todas las pantallas de ajustes cierran con la misma barra. */
    public function testTodasLasPantallasDeAjustesCierranConLaBarraDeAcciones(): void {
        foreach ($this->pantallas() as $pantalla) {
            $php = $this->fichero("include/staff/$pantalla.inc.php");
            $this->assertStringContainsString('<p class="form-actions">', $php,
                sprintf('%s no usa la barra de acciones de los formularios',
                    $pantalla));
            $this->assertFalse(
                (bool) preg_match('/<p style="text-align:center/', $php),
                sprintf('%s conserva los botones centrados en un `<p>` con '
                    . 'estilo en línea', $pantalla));
        }
    }

    /** El reset va antes que el submit: el orden del documento es el visual. */
    public function testElResetVaAntesQueElSubmit(): void {
        foreach ($this->pantallas() as $pantalla) {
            $php = $this->fichero("include/staff/$pantalla.inc.php");
            $inicio = strpos($php, '<p class="form-actions">');
            $fin = strpos($php, '</p>', $inicio);
            $barra = substr($php, $inicio, $fin - $inicio);

            $reset = strpos($barra, 'type="reset"');
            $submit = strpos($barra, 'type="submit"');
            $this->assertTrue($reset !== false && $submit !== false,
                sprintf('%s perdió uno de los dos botones', $pantalla));
            $this->assertLessThan($submit, $reset,
                sprintf('en %s el submit tiene que quedar a la derecha del reset',
                    $pantalla));
        }
    }

    /**
     * En oscuro, «Save Changes» no puede ser otro botón gris: la hoja del
     * tema pinta todos los botones con la superficie, con más especificidad
     * que la regla de «primario» de la capa moderna.
     */
    public function testLaAccionPrincipalSeDistingueEnOscuro(): void {
        $css = $this->sinComentarios($this->fichero('css/dark.css'));

        $this->assertMatchesRegularExpression(
            '/p\.form-actions input\[type=submit\][^{]*\{[^}]*'
            . 'background:\s*var\(--dk-accent\)/s', $css,
            'el submit de un formulario de ajustes no lleva el acento en '
            . 'oscuro: es indistinguible del reset');

        // Y el estado «sin guardar» (scp.css lo marca con `.save.pending`)
        // tiene que verse también en oscuro.
        $this->assertStringContainsString('.save.pending', $css,
            'en oscuro no se ve que un formulario tenga cambios sin guardar');
    }

    /* ------------------------------------------------------------------ */
    /* 3. Formularios                                                     */
    /* ------------------------------------------------------------------ */

    /** Los ajustes ocupan el ancho de la pestaña, como las tablas de lista. */
    public function testLosFormulariosDeAjustesOcupanElAncho(): void {
        $css = $this->sinComentarios($this->fichero('css/modern.css'));

        $this->assertMatchesRegularExpression(
            '/table\.settings_table\s*\{[^}]*width:\s*100%/s', $css,
            'la tabla de ajustes conserva el `width="940"` del marcado y deja '
            . 'una franja muerta a la derecha de la pestaña');
    }

    /** La fila vacía que abre los formularios dinámicos no se pinta. */
    public function testLaFilaVaciaDeLosFormulariosNoSePinta(): void {
        $tmpl = $this->fichero('include/staff/templates/dynamic-form.tmpl.php');
        $css = $this->sinComentarios($this->fichero('css/modern.css'));

        $this->assertStringContainsString('class="ost-form-spacer"', $tmpl,
            'la fila espaciadora de los formularios no está marcada');
        $this->assertStringContainsString('tr.ost-form-spacer', $css,
            'la fila espaciadora se sigue pintando: una banda en blanco encima '
            . 'del primer bloque del formulario');
    }

    /**
     * Cada casilla se marca desde su etiqueta y el navegador puede pintarla
     * con el acento del tema.
     */
    public function testLasCasillasTienenEtiquetaYColorDelTema(): void {
        $php = $this->fichero('include/staff/settings-system.inc.php');

        foreach (array('force_https', 'enable_avatars', 'enable_richtext',
                'files_req_auth') as $campo) {
            $this->assertStringContainsString(
                'id="' . $campo . '"', $php,
                sprintf('la casilla «%s» no tiene id', $campo));
            $this->assertStringContainsString(
                '<label for="' . $campo . '">', $php,
                sprintf('la casilla «%s» no se puede marcar desde su texto',
                    $campo));
        }

        $css = $this->sinComentarios($this->fichero('css/modern.css'));
        $this->assertMatchesRegularExpression(
            '/input\[type="?checkbox"?\][^{]*\{[^}]*accent-color:\s*var\(--ui-accent\)/s',
            $css, 'las casillas y los radios no usan el acento del tema: el '
            . 'azul del sistema es el único control que no sigue la paleta');
    }

    /* ------------------------------------------------------------------ */
    /* 4. Desplegables con dos opciones marcadas                          */
    /* ------------------------------------------------------------------ */

    public function testNingunaOpcionQuedaMarcadaDosVeces(): void {
        $php = $this->fichero('include/staff/settings-system.inc.php');

        $this->assertFalse(
            (bool) preg_match('/<option value=0 selected>/', $php),
            '«Never Purge Logs» lleva `selected` fijo: con una purga '
            . 'configurada hay dos opciones marcadas');

        $this->assertMatchesRegularExpression(
            '/name="log_graceperiod">\s*<option value=0 <\?php echo !\$config\[.log_graceperiod.\]/s',
            $php, 'la opción «Never Purge Logs» no depende del ajuste guardado');

        $this->assertMatchesRegularExpression(
            '/<option value="262144" <\?php echo \(\$config\[.max_file_size.\] == 262144\)/s',
            $php, 'la opción «Small» del tamaño de adjuntos nunca queda marcada');
    }

    /* ------------------------------------------------------------------ */
    /* 5. Galería de logos y fondos                                       */
    /* ------------------------------------------------------------------ */

    public function testLaGaleriaDeImagenesEsUnaListaConSusBotones(): void {
        $php = $this->fichero('include/staff/settings-pages.inc.php');

        foreach (array('media-picker', 'media-list', 'media-item', 'media-thumb',
                'media-choice', 'media-upload', 'media-danger') as $clase) {
            $this->assertStringContainsString('class="' . $clase, $php,
                sprintf('falta «%s» en la galería: sigue siendo la tabla '
                    . 'anidada con los radios separados de la imagen', $clase));
        }

        // Cada radio lleva su etiqueta: «Client» y «Staff» se leen al lado de
        // la imagen, no en una cabecera al principio de la lista. Dos destinos
        // por logo (el de omisión y cada uno subido) y uno por fondo.
        $this->assertSame(2, substr_count($php, "<span><?php echo __('Client'); ?></span>"),
            'faltan los botones de «Client» de la galería de logos');
        $this->assertSame(4, substr_count($php, "<span><?php echo __('Staff'); ?></span>"),
            'faltan los botones de «Staff» (logos y fondo de acceso)');

        // Nada de estilos en línea para las imágenes: el tamaño lo pone la hoja.
        $this->assertFalse(
            strpos($php, 'box-shadow: 0 0 0.5em') !== false,
            'quedan sombras en línea de la tabla vieja');
    }

    /**
     * El aviso de borrado se enganchaba a `#save`, que no existe en esta
     * pantalla: marcar «Delete» y guardar borraba sin preguntar.
     */
    public function testElBorradoDeImagenesPideConfirmacion(): void {
        $php = $this->fichero('include/staff/settings-pages.inc.php');

        $this->assertFalse(strpos($php, "$('#save") !== false,
            'el manejador vuelve a apuntar a `#save`, un formulario que no '
            . 'existe en esta pantalla');
        $this->assertStringContainsString(
            'input[name^="delete-"]:checked', $php,
            'no se comprueba si hay algo marcado para borrar antes de pedir '
            . 'confirmación');

        // El formulario tiene un campo `name="submit"`, que tapa el método
        // `form.submit()`: el envío confirmado tiene que usar el nativo.
        $this->assertStringContainsString(
            'HTMLFormElement.prototype.submit.call', $php,
            'el envío confirmado no usa el método nativo: `form.submit()` es '
            . 'el campo del formulario y no envía nada');
        $this->assertStringContainsString("}, true);", $php,
            'el aviso tiene que escucharse en fase de captura: el manejador '
            . 'genérico de `form.save` deshabilita los botones al enviar');
    }

    /** La fila marcada para borrar se apaga antes de guardar. */
    public function testLaImagenMarcadaParaBorrarSeAtenua(): void {
        $css = $this->sinComentarios($this->fichero('css/modern.css'));
        $php = $this->fichero('include/staff/settings-pages.inc.php');

        $this->assertStringContainsString('.media-item.is-doomed', $css,
            'no hay estilo para la fila marcada para borrar');
        $this->assertStringContainsString("toggleClass('is-doomed'", $php,
            'la fila no se marca al activar la casilla de borrado');
    }

    /** La opción marcada de la galería se resalta en los dos temas. */
    public function testLaSeleccionDeLaGaleriaSeResalta(): void {
        $css = $this->sinComentarios($this->fichero('css/modern.css'));
        $php = $this->fichero('include/staff/settings-pages.inc.php');

        $this->assertStringContainsString('.media-choice.is-active', $css,
            'la opción marcada no se distingue del resto');
        $this->assertStringContainsString("toggleClass('is-active', this.checked)",
            $php, 'al cambiar de radio no se mueve el resaltado');

        // En oscuro la vista previa se aclara: los logos del producto son PNG
        // con partes oscuras y sobre la superficie del tema se pierden.
        $dark = $this->sinComentarios($this->fichero('css/dark.css'));
        $this->assertStringContainsString('.media-thumb img', $dark,
            'en oscuro la vista previa del logo se queda sobre la superficie '
            . 'del tema y el dibujo desaparece');
    }

    /* ------------------------------------------------------------------ */
    /* 6. Pestañas                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Las pestañas inactivas iban rellenas y con borde: en una tira de cuatro
     * o cinco se leían como botones apagados. En claro son transparentes.
     */
    public function testLasPestanasInactivasNoSonBotonesApagados(): void {
        $dark = $this->sinComentarios($this->fichero('css/dark.css'));

        $this->assertMatchesRegularExpression(
            '/\.tabs li,\s*\n?html\[data-theme="dark"\] \.tabs a\s*\{[^}]*'
            . 'background:\s*transparent/s', $dark,
            'en oscuro las pestañas inactivas siguen rellenas');
        $this->assertMatchesRegularExpression(
            '/\.tabs li\.active[^{]*\{[^}]*border-top-color:\s*var\(--ui-accent\)'
            . '|\.tabs li\.active[^{]*\{[^}]*border-left-color:\s*var\(--dk-border\)/s',
            $dark, 'la pestaña activa perdió el trazo de acento del borde superior');
    }
}
