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

        foreach ($this->pantallas() as $pantalla) {
            $php = $this->fichero("include/staff/$pantalla.inc.php");
            $etiquetas = $this->etiquetas($php);

            preg_match_all('/<input\b[^>]*>/s', $php, $inputs,
                PREG_OFFSET_CAPTURE);
            preg_match_all('/<label\b[^>]*\bfor="([^"]+)"/s', $php, $conFor);

            foreach ($inputs[0] as $input) {
                list($tag, $pos) = $input;
                if (!preg_match('/type="(checkbox|radio)"/', $tag))
                    continue;
                // Las casillas de selección múltiple de una lista no llevan
                // etiqueta: su etiqueta es la fila, y marcarlas en masa es
                // precisamente lo que se quiere.
                if (strpos($tag, 'class="mass') !== false)
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
        foreach ($this->pantallas() as $pantalla) {
            $php = $this->fichero("include/staff/$pantalla.inc.php");

            // Sólo las tablas de ajustes: las de una lista (`table.list`)
            // tienen sus propias cabeceras de columna.
            if (strpos($php, 'settings_table') === false)
                continue;

            preg_match_all('/<th\b[^>]*>/s', $php, $ths);
            foreach ($ths[0] as $th) {
                $this->assertStringContainsString('colspan="2"', $th,
                    sprintf('%s: una banda de sección sin `colspan="2"`',
                        $pantalla));
            }
        }
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
