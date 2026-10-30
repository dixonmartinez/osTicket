<?php
/**
 * tests/AdminLayoutTest.php
 *
 * El estándar de las pantallas de administración (`scp/settings.php`).
 *
 * Después de varias tandas de capturas, el panel tenía tres lenguajes en la
 * misma sección: pantallas ya modernizadas, pantallas con tablas a medias y
 * pantallas donde una casilla de 13 px era el único sitio donde se podía
 * pulsar. Estas pruebas fijan las reglas que se aplicaron a todas:
 *
 *   1. Cada casilla y cada botón de opción va dentro de su `<label>` (o la
 *      etiqueta de la fila lleva `for`), para que el texto también active el
 *      control.
 *   2. Las bandas de sección (`<th>`) ocupan las dos columnas de la tabla.
 *   3. Los destinatarios de un aviso van en rejilla, no en una fila cada uno.
 *   4. Las pantallas largas se reparten en pestañas.
 *
 * Se comprueba leyendo plantillas y hojas: en `make ci` no hay navegador.
 */
class AdminLayoutTest extends UnitTestCase {

    private function fichero($ruta): string {
        return (string) file_get_contents(ROOT_DIR . '/' . $ruta);
    }

    private function sinComentarios($css): string {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /** Todas las pantallas que cuelgan de `scp/settings.php`. */
    private function pantallas(): array {
        $pantallas = array();
        foreach (glob(ROOT_DIR . '/include/staff/settings-*.inc.php') as $ruta)
            $pantallas[] = basename($ruta, '.inc.php');
        sort($pantallas);

        return $pantallas;
    }

    /**
     * El resto de pantallas de administración: las que se abren desde
     * *Manage* (departamentos, equipos, roles, horarios, filtros, listas…).
     * No cuelgan de `settings.php`, así que se descubren por su marcado.
     */
    private function pantallasDeGestion(): array {
        $pantallas = array();
        foreach (glob(ROOT_DIR . '/include/staff/*.inc.php') as $ruta) {
            $php = (string) file_get_contents($ruta);
            $nombre = basename($ruta, '.inc.php');
            if (strpos($nombre, 'settings-') === 0)
                continue;
            // Dos generaciones de marcado: las pantallas con `form_table` y
            // las que ya usan la tabla de dos columnas y pestañas (categorías,
            // FAQ, temas de ayuda, agentes, plantillas…). Las segundas se
            // reconocen por su botón de cancelar: sólo las fichas lo tienen.
            if (strpos($php, 'class="form_table') === false
                    && strpos($php, 'name="cancel"') === false)
                continue;
            $pantallas[] = $nombre;
        }
        sort($pantallas);

        return $pantallas;
    }

    /** Todas: las de ajustes y las de gestión. */
    private function todas(): array {
        return array_merge($this->pantallas(), $this->pantallasDeGestion());
    }

    /**
     * Etiquetas `<input …>` completas. La expresión regular de siempre
     * (`/<input[^>]*>/`) se corta en el `?>` de un `echo` dentro de un
     * atributo (`value="<?php echo $id; ?>"`) y deja la etiqueta a medias,
     * sin `name` ni `id`. Aquí se salta el bloque PHP.
     */
    private function entradas(string $php): array {
        $salida = array();
        $largo = strlen($php);
        $i = 0;
        while (($ini = strpos($php, '<input', $i)) !== false) {
            $j = $ini;
            while ($j < $largo) {
                if (substr($php, $j, 2) === '<?') {
                    $fin = strpos($php, '?>', $j);
                    $j = $fin === false ? $largo : $fin + 2;
                    continue;
                }
                if ($php[$j] === '>')
                    break;
                $j++;
            }
            $salida[] = array(substr($php, $ini, $j + 1 - $ini), $ini);
            $i = $j + 1;
        }

        return $salida;
    }

    /**
     * Rango de los `<label>` de un archivo, para saber qué controles van
     * dentro de una etiqueta.
     */
    private function etiquetas(string $php): array {
        preg_match_all('/<label\b[^>]*>.*?<\/label>/s', $php, $m,
            PREG_OFFSET_CAPTURE);

        return array_map(function ($e) {
            return array($e[1], $e[1] + strlen($e[0]));
        }, $m[0]);
    }

    /* ------------------------------------------------------------------ */
    /* 1. Etiquetas                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * `input[type=checkbox]` fuera de un `<label>` sólo se puede marcar en el
     * cuadradito. Es la queja que más se repite: «no se puede pulsar el
     * texto».
     */
    public function testCadaCasillaYRadioTieneSuEtiqueta(): void {
        $revisados = 0;

        foreach ($this->todas() as $pantalla) {
            $php = $this->fichero("include/staff/$pantalla.inc.php");
            $etiquetas = $this->etiquetas($php);

            $inputs = $this->entradas($php);
            preg_match_all('/<label\b[^>]*\bfor="([^"]+)"/s', $php, $conFor);

            foreach ($inputs as $input) {
                list($tag, $pos) = $input;
                if (!preg_match('/type="(checkbox|radio)"/', $tag))
                    continue;
                // Las de selección múltiple de una lista se marcan desde su
                // fila entera (`mass`), no desde un texto al lado.
                if (strpos($tag, 'class="mass') !== false)
                    continue;
                // Y huecos de la lista: la celda «Delete» de un campo que no
                // se puede borrar es una casilla deshabilitada y sin nombre.
                if (strpos($tag, 'disabled') !== false
                        && strpos($tag, 'name=') === false)
                    continue;

                $revisados++;

                $dentro = false;
                foreach ($etiquetas as $rango)
                    if ($rango[0] <= $pos && $pos < $rango[1])
                        $dentro = true;

                $referenciado = false;
                if (preg_match('/id="([^"]+)"/', $tag, $id))
                    $referenciado = in_array($id[1], $conFor[1], true);

                $this->assertTrue($dentro || $referenciado,
                    sprintf('%s: la casilla %s sólo se puede marcar en el '
                        . 'cuadradito (falta el `<label>`)', $pantalla,
                        preg_match('/name="([^"]+)"/', $tag, $n)
                            ? $n[1] : 'sin nombre'));
            }
        }

        $this->assertTrue($revisados > 60,
            sprintf('sólo se revisaron %d controles: ¿se perdió el listado?',
                $revisados));
    }

    /** Dos filas de agente se marcan desde el nombre del ajuste. */
    public function testLasEtiquetasDeFilaLlevanFor(): void {
        $php = $this->fichero('include/staff/settings-agents.inc.php');

        foreach (array('allow_pw_reset', 'staff_ip_binding') as $campo) {
            $this->assertStringContainsString(
                sprintf('<label for="%s">', $campo), $php,
                sprintf('la fila de %s no se puede marcar desde su nombre',
                    $campo));
            $this->assertMatchesRegularExpression(
                sprintf('/<input type="checkbox" id="%s"/s', $campo), $php,
                sprintf('el campo %s perdió el `id` que usa su etiqueta',
                    $campo));
        }
    }

    /* ------------------------------------------------------------------ */
    /* 2. Bandas de sección                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Una banda con `<th>` a secas se queda en la columna de las etiquetas y
     * el título aparece cortado a 220 px.
     */
    public function testLasBandasDeSeccionOcupanLasDosColumnas(): void {
        foreach ($this->todas() as $pantalla) {
            $php = $this->fichero("include/staff/$pantalla.inc.php");

            // `colspan="2"` en las tablas de etiqueta y valor; las de una
            // lista de campos llegan a siete columnas.
            preg_match_all('/<tr><th\b[^>]*>/s', $php, $ths);
            foreach ($ths[0] as $th) {
                $this->assertMatchesRegularExpression('/colspan="([2-9]|[1-9]\d+)"/',
                    $th, sprintf('%s: una banda de sección sin `colspan`',
                        $pantalla));
            }
        }
    }

    /**
     * El pie de los formularios de gestión: no puede quedar ninguno de los
     * `<p style="text-align:center">` con el submit primero, el reset después
     * y el cancel al final, que era el patrón de todas estas pantallas.
     */
    public function testLosBotonesVandeCancelarAResetYGuardar(): void {
        $pantallas = 0;

        foreach ($this->todas() as $pantalla) {
            $php = $this->fichero("include/staff/$pantalla.inc.php");
            if (strpos($php, '<form') === false)
                continue;

            $this->assertFalse(
                (bool) preg_match('/<p[^>]*style="text-align:center/', $php),
                sprintf('%s conserva los botones centrados con estilo en línea',
                    $pantalla));

            if (strpos($php, 'class="form-actions"') === false)
                continue;

            $pantallas++;

            // Cancelar (si lo hay) va antes que el reset, y el envío al final:
            // el orden del documento es el orden de la pantalla.
            $inicio = strpos($php, 'class="form-actions"');
            $fin = strpos($php, '</p>', $inicio);
            $barra = substr($php, $inicio, $fin - $inicio);

            $reset = strpos($barra, 'type="reset"');
            $submit = strpos($barra, 'type="submit"');
            $this->assertTrue($reset !== false && $submit !== false,
                sprintf('%s perdió el reset o el envío', $pantalla));
            $this->assertLessThan($submit, $reset,
                sprintf('en %s el envío tiene que quedar a la derecha del reset',
                    $pantalla));

            $cancel = strpos($barra, 'name="cancel"');
            if ($cancel !== false)
                $this->assertLessThan($reset, $cancel,
                    sprintf('en %s «Cancelar» tiene que ir antes que el reset',
                        $pantalla));
        }

        $this->assertTrue($pantallas >= 20,
            sprintf('sólo %d pantallas con barra de acciones', $pantallas));
    }

    /* ------------------------------------------------------------------ */
    /* 3. Avisos en rejilla                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Una fila por destinatario: siete grupos de avisos seguidos eran más de
     * dos pantallas de scroll para leer una lista de casillas.
     */
    public function testLosAvisosAgrupanSusDestinatarios(): void {
        $tickets = $this->fichero('include/staff/settings-alerts.inc.php');
        $tareas = $this->fichero('include/staff/settings-tasks.inc.php');

        $this->assertSame(7, substr_count($tickets, 'class="ost-check-grid"'),
            'los avisos de tickets tienen que agruparse en siete rejillas '
            . '(una por grupo de aviso)');
        $this->assertSame(5, substr_count($tareas, 'class="ost-check-grid"'),
            'los avisos de tareas tienen que agruparse en cinco rejillas');

        $css = $this->sinComentarios($this->fichero('css/modern.css'));
        $this->assertMatchesRegularExpression(
            '/\.ost-check-grid\s*\{[^}]*display:\s*grid[^}]*'
            . 'grid-template-columns:\s*repeat\(auto-fill,\s*minmax\(260px,\s*1fr\)\)/s',
            $css, 'la rejilla de casillas no reparte el ancho en columnas');
    }

    /* ------------------------------------------------------------------ */
    /* 4. Pestañas                                                        */
    /* ------------------------------------------------------------------ */

    /** Las pantallas largas no se recorren de arriba abajo de una tirada. */
    public function testLasPantallasLargasSeRepartenEnPestanas(): void {
        $esperadas = array(
            'settings-pages' => 4,
            'settings-system' => 4,
            'settings-tickets' => 5,
            'settings-tasks' => 2,
            'settings-agents' => 2,
        );

        foreach ($esperadas as $pantalla => $cuenta) {
            $php = $this->fichero("include/staff/$pantalla.inc.php");

            $this->assertSame($cuenta,
                preg_match_all('/class="[^"]*\btab_content\b[^"]*"/', $php),
                sprintf('%s debería tener %d pestañas con contenido', $pantalla,
                    $cuenta));
            $this->assertSame($cuenta,
                preg_match_all('/<a href="#[a-z-]+">/', $php),
                sprintf('%s no tiene un enlace por pestaña', $pantalla));
        }
    }

    /* ------------------------------------------------------------------ */
    /* 5. La hoja                                                         */
    /* ------------------------------------------------------------------ */

    /** La etiqueta se pulsa entera y el cuadro no se pega al texto. */
    public function testLaEtiquetaEsParteDelControl(): void {
        $css = $this->sinComentarios($this->fichero('css/modern.css'));

        $this->assertMatchesRegularExpression(
            '/\.form_table label,\s*\.ost-check-grid label\s*\{[^}]*'
            . 'cursor:\s*pointer/s', $css,
            'la etiqueta de una casilla no invita a pulsarla');
        $this->assertMatchesRegularExpression(
            '/\.form_table td > label\s*\{[^}]*display:\s*inline-flex/s', $css,
            'el texto de la casilla no se alinea con el cuadro');
    }
}
