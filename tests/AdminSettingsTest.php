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

    /* ------------------------------------------------------------------ */
    /* 7. Los formularios largos                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Sistema tenía una sola tabla con las cuatro secciones: para llegar a
     * «Login required» había que bajar más de dos pantallas. Ahora cada
     * sección es una pestaña, como en Perfil de la empresa y en Tickets.
     */
    public function testElSistemaSeReparteEnPestanas(): void {
        $php = $this->fichero('include/staff/settings-system.inc.php');

        $pestanas = array(
            'system-general' => 'name="log_graceperiod"',
            'system-datetime' => 'name="date_formats"',
            'system-languages' => 'name="system_language"',
            'system-attachments' => 'name="default_storage_bk"',
        );

        $anterior = -1;
        foreach ($pestanas as $id => $campo) {
            $enlace = strpos($php, sprintf('href="#%s"', $id));
            $this->assertTrue($enlace !== false,
                sprintf('el sistema perdió la pestaña %s', $id));

            $contenido = strpos($php, sprintf('<div class="tab_content" id="%s">', $id));
            if ($contenido === false)
                $contenido = strpos($php,
                    sprintf('<div class="hidden tab_content" id="%s">', $id));
            $this->assertTrue($contenido !== false,
                sprintf('la pestaña %s no tiene contenido', $id));

            $this->assertTrue($enlace < $contenido,
                sprintf('el enlace de %s va después de su contenido', $id));
            $this->assertTrue($contenido > $anterior,
                sprintf('la pestaña %s está fuera de orden', $id));
            $anterior = $contenido;

            $donde = strpos($php, $campo);
            $this->assertTrue($donde !== false,
                sprintf('el campo %s desapareció de los ajustes', $campo));
            $this->assertTrue($donde > $contenido,
                sprintf('el campo %s quedó en otra pestaña', $campo));
        }

        // Y sólo la primera se ve al entrar.
        $this->assertStringContainsString('<div class="tab_content" id="system-general">',
            $php, 'la primera pestaña del sistema arranca oculta');
        $this->assertFalse(
            strpos($php, '<div class="tab_content" id="system-datetime">') !== false,
            'la segunda pestaña del sistema no está oculta');
    }

    /**
     * La pestaña «Settings» de Tickets tenía las casillas de comportamiento
     * mezcladas con los desplegables de numeración. Ahora están aparte.
     */
    public function testLasOpcionesDeLosTicketsVanAparte(): void {
        $php = $this->fichero('include/staff/settings-tickets.inc.php');

        $this->assertStringContainsString('<a href="#options">', $php,
            'los tickets perdieron la pestaña de opciones');

        $opciones = strpos($php, '<div class="hidden tab_content" id="options">');
        $this->assertTrue($opciones !== false, 'la pestaña de opciones no tiene contenido');

        foreach (array('Human Verification', 'Collaborator Tickets Visibility',
            'Claim on Response', 'Auto-refer on Close',
            'Require Help Topic to Close', 'Allow External Images') as $casilla) {
            $donde = strpos($php, $casilla);
            $this->assertTrue($donde !== false,
                sprintf('la casilla %s desapareció de los ajustes de tickets', $casilla));
            $this->assertTrue($donde > $opciones,
                sprintf('la casilla %s se quedó en la pestaña de ajustes', $casilla));
        }

        // Los desplegables siguen en «Settings», antes del corte.
        $this->assertTrue(strpos($php, 'Maximum Open Tickets') < $opciones,
            'la numeración de tickets se coló en la pestaña de opciones');

        // Las otras tres pestañas siguen ahí.
        foreach (array('autoresp', 'alerts', 'queues') as $id)
            $this->assertStringContainsString(
                sprintf('id="%s"', $id), $php,
                sprintf('los tickets perdieron la pestaña %s', $id));
    }

    /**
     * Al guardar desde una pestaña se vuelve a ella: el `action` no lleva el
     * hash y la respuesta abría siempre la primera.
     */
    public function testAlGuardarSeVuelveALaMismaPestana(): void {
        $js = $this->fichero('scp/js/scp.js');

        $this->assertMatchesRegularExpression(
            '/\$\(document\)\.on\(\'submit\', \'form:has\(ul\.tabs\)\'/',
            $js, 'guardar desde una pestaña devuelve a la primera');
        $this->assertStringContainsString("action.split('#')[0] + hash", $js,
            'el hash de la pestaña no se conserva al enviar el formulario');
    }

    /* ------------------------------------------------------------------ */
    /* 8. Imágenes                                                        */
    /* ------------------------------------------------------------------ */

    /** El selector de archivos sólo ofrece imágenes, y lo dice. */
    public function testElSelectorSoloOfreceImagenes(): void {
        $php = $this->fichero('include/staff/settings-pages.inc.php');

        $accept = 'accept="image/png,image/jpeg,image/gif,image/webp"';
        $this->assertSame(2, substr_count($php, $accept),
            'los dos selectores de archivo (logo y fondo) tienen que ofrecer '
            . 'sólo imágenes');

        $this->assertSame(2,
            substr_count($php, "<span class=\"hint\"><?php echo __('PNG, JPG, GIF or WebP'); ?></span>"),
            'la pantalla no dice qué formatos se admiten');
    }

    /**
     * El navegador se salta `accept` con un POST a mano: la comprobación del
     * servidor no puede depender de que el PHP tenga GD, que era lo que la
     * desactivaba entera.
     */
    public function testElServidorCompruebaQueSeaUnaImagen(): void {
        $php = $this->fichero('include/class.file.php');

        foreach (array('uploadLogo', 'uploadBackdrop') as $metodo) {
            $inicio = strpos($php, "static function $metodo(");
            $this->assertTrue($inicio !== false,
                sprintf('%s desapareció', $metodo));
            $fin = strpos($php, "\n    }", $inicio);
            $cuerpo = substr($php, $inicio, $fin - $inicio);

            $this->assertStringContainsString('self::_validateImage(', $cuerpo,
                sprintf('%s guarda el archivo sin comprobar que sea una imagen',
                    $metodo));
            $this->assertFalse(strpos($cuerpo, "extension_loaded('gd')") !== false,
                sprintf('%s vuelve a atar la comprobación a GD', $metodo));
        }

        $this->assertStringContainsString('FileInput::isImage(', $php,
            'la comprobación de imagen no usa la lista de tipos admitidos');
        $this->assertStringContainsString("__('Invalid image file type')", $php,
            'se perdió el aviso de tipo de imagen no válido');
    }

    /* ------------------------------------------------------------------ */
    /* 9. Pestañas y papel                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Las inactivas volvían a salir rellenas: una regla vieja de `css/dark.css`
     * (`ul.tabs li`, especificidad (0,2,3)) ganaba por orden y por peso a la
     * nueva y les devolvía la superficie del tema. En la tira, además, el
     * hueco de `padding-left` se leía como una pestaña vacía.
     */
    public function testLasPestanasInactivasNoVuelvenARellenarse(): void {
        $dark = $this->sinComentarios($this->fichero('css/dark.css'));

        // Todas las reglas que tocan una ficha de la tira: si alguna pinta
        // fondo, tiene que ser `transparent`.
        preg_match_all('/([^{}]+)\{([^}]*)\}/', $dark, $reglas, PREG_SET_ORDER);
        $rellenos = 0;
        foreach ($reglas as $regla) {
            // La ficha activa sí lleva cuerpo; las demás, no.
            if (!preg_match('/\.tabs\b[^{]*\bli\b/', $regla[1])
                    || strpos($regla[1], '.active') !== false)
                continue;
            if (!preg_match('/background[^;]*:\s*([^;!]+)/', $regla[2], $valor))
                continue;

            $rellenos++;
            $this->assertSame('transparent', trim($valor[1]),
                'una regla de las pestañas vuelve a rellenar las fichas: en una '
                . 'tira de cinco se leen como cinco botones apagados (y el '
                . 'hueco de la izquierda parece una pestaña vacía)');
        }

        $this->assertTrue($rellenos > 0,
            'ninguna regla dice que las pestañas inactivas sean transparentes');
    }

    /**
     * El editor de plantillas del diálogo «Manage Content» escribía en el color
     * del tema sobre el papel claro: casi blanco sobre blanco. `css/dark.css`
     * pinta `.dialog *` entero, y el contenido del editor son párrafos, así
     * que heredar no bastaba.
     */
    public function testElEditorDePlantillasEscribeEnTinta(): void {
        $dark = $this->sinComentarios($this->fichero('css/dark.css'));

        $this->assertStringContainsString('--dk-ink:', $dark,
            'falta el token de tinta para el papel del editor');
        $this->assertMatchesRegularExpression(
            '/\.dialog \.redactor-in,\s*'
            . 'html\[data-theme="dark"\] \.dialog \.redactor-in \*\s*'
            . '\{[^}]*color:\s*var\(--dk-ink\)/s', $dark,
            'el contenido de la plantilla hereda otra vez el texto claro del '
            . 'tema sobre el papel');

        // La excepción: los editores de nota siguen la superficie del tema.
        $this->assertStringContainsString(
            '.dialog .redactor-box.ost-note-editor .redactor-in', $dark,
            'la corrección del papel se lleva por delante los editores de nota');
    }

    /**
     * La fila «Default» de la galería lleva dos imágenes: con `flex` cada una
     * pedía el ancho entero de la columna y la segunda se salía encima del
     * nombre —el logo de osTicket es transparente y el texto blanco se veía a
     * través—.
     */
    public function testLaGaleriaNoDejaQueLaImagenTapeElNombre(): void {
        $css = $this->sinComentarios($this->fichero('css/modern.css'));

        $this->assertMatchesRegularExpression(
            '/\.media-thumb\s*\{[^}]*display:\s*grid[^}]*'
            . 'grid-auto-columns:\s*minmax\(0,\s*1fr\)/s', $css,
            'la vista previa vuelve a dejar que dos imágenes se salgan de su '
            . 'columna y tapen el nombre');

        $this->assertMatchesRegularExpression(
            '/\.media-thumb img\s*\{[^}]*max-width:\s*100%/s', $css,
            'la imagen no se limita al ancho de su columna');
    }
}
