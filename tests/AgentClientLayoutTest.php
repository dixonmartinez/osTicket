<?php
/**
 * tests/AgentClientLayoutTest.php
 *
 * Las otras dos caras del producto: la ficha del ticket en el panel del agente
 * (`include/staff/ticket-view.inc.php`) y el portal del cliente
 * (`include/client/`).
 *
 * Las pantallas de administración ya tenían su estándar (§3.18 y §3.19 del
 * `docs/UI_GUIDE.md`). Estas dos se quedaron fuera y seguían con el pie de
 * botones centrado a mano:
 *
 *   - `ticket-view.inc.php`: «Post Reply» y «Post Note» en un
 *     `<p style="text-align:center;">`, con el envío delante del reset.
 *   - `open.inc.php`, `view.inc.php`, `edit.inc.php`, `profile.inc.php` y
 *     `register.inc.php`: lo mismo, y en el portal un `<p class="buttons">`
 *     que ninguna hoja pinta: la única forma de alinear los botones era el
 *     estilo en línea del párrafo.
 *
 * La regla es la misma en los tres sitios: `p.form-actions`, con cancelar
 * antes del reset y el envío al final, porque el orden del documento es el
 * orden del teclado. La separación de «Cancelar» la pone la hoja.
 *
 * Se comprueba leyendo plantillas: en `make ci` no hay navegador.
 */
class AgentClientLayoutTest extends UnitTestCase {

    private function fichero($ruta): string {
        return (string) file_get_contents(ROOT_DIR . '/' . $ruta);
    }

    /**
     * Pantallas del panel del agente que dibujan un formulario.
     *
     * Incluye `include/staff/templates/`, que es donde viven la ficha del
     * ticket y la de la tarea: la segunda se quedó con el pie centrado cuando
     * se arregló la primera, porque el glob sólo miraba el primer nivel.
     */
    private function plantillasDelAgente(): array {
        $salida = array();
        foreach (array('include/staff/*.inc.php',
                'include/staff/templates/*.tmpl.php') as $patron) {
            foreach (glob(ROOT_DIR . '/' . $patron) as $ruta) {
                $php = (string) file_get_contents($ruta);
                if (strpos($php, '<form') === false)
                    continue;
                $salida[] = substr($ruta, strlen(ROOT_DIR) + 1);
            }
        }
        sort($salida);

        return $salida;
    }

    /** Plantillas del portal del cliente, incluidas las de acceso. */
    private function plantillasDelCliente(): array {
        $salida = array();
        foreach (array('include/client/*.inc.php', 'include/client/*.php')
                as $patron) {
            foreach (glob(ROOT_DIR . '/' . $patron) as $ruta) {
                $php = (string) file_get_contents($ruta);
                if (strpos($php, '<form') === false)
                    continue;
                $salida[] = 'include/client/' . basename($ruta);
            }
        }
        sort($salida);

        return array_values(array_unique($salida));
    }

    /**
     * Etiquetas `<input …>` completas. Una expresión regular se corta en el
     * `?>` de un `echo` dentro de un atributo y deja la etiqueta sin `name`.
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

    private function etiquetas(string $php): array {
        preg_match_all('/<label\b[^>]*>.*?<\/label>/s', $php, $m,
            PREG_OFFSET_CAPTURE);

        return array_map(function ($e) {
            return array($e[1], $e[1] + strlen($e[0]));
        }, $m[0]);
    }

    /** Los elementos del pie, en el orden en que aparecen. */
    private function pie(string $php, int $desde = 0): array {
        $inicio = strpos($php, 'class="form-actions"', $desde);
        if ($inicio === false)
            return array();

        $fin = strpos($php, '</p>', $inicio);
        $barra = substr($php, $inicio, $fin - $inicio);

        preg_match_all('/<input\b[^>]*>/', $barra, $m);
        $tipos = array();
        foreach ($m[0] as $tag) {
            if (strpos($tag, 'type="reset"') !== false)
                $tipos[] = 'reset';
            elseif (strpos($tag, 'name="cancel"') !== false
                    || strpos($tag, 'type="button"') !== false)
                $tipos[] = 'cancel';
            elseif (strpos($tag, 'type="submit"') !== false)
                $tipos[] = 'submit';
        }

        return $tipos;
    }

    /* ------------------------------------------------------------------ */
    /* 1. Nada de centrar los botones con un estilo en línea              */
    /* ------------------------------------------------------------------ */

    public function testNingunPieSeCentraConEstiloEnLinea(): void {
        // `scp/emailtest.php` es la única pantalla del panel que no vive en
        // `include/staff/` y también tenía el pie centrado a mano.
        $pantallas = array_merge($this->plantillasDelAgente(),
            $this->plantillasDelCliente(), array('scp/emailtest.php'));

        $this->assertTrue(count($pantallas) > 30,
            sprintf('sólo se revisaron %d plantillas', count($pantallas)));

        foreach ($pantallas as $pantalla) {
            $php = $this->fichero($pantalla);

            $this->assertFalse(
                (bool) preg_match('/<p\b[^>]*text-align\s*:\s*center/', $php),
                sprintf('%s sigue centrando los botones con un estilo en línea',
                    $pantalla));
        }
    }

    /* ------------------------------------------------------------------ */
    /* 2. El pie del portal del cliente                                   */
    /* ------------------------------------------------------------------ */

    public function testElPortalDelClienteUsaElMismoPie(): void {
        $esperadas = array(
            'include/client/open.inc.php',
            'include/client/view.inc.php',
            'include/client/edit.inc.php',
            'include/client/profile.inc.php',
            'include/client/register.inc.php',
        );

        foreach ($esperadas as $plantilla) {
            $php = $this->fichero($plantilla);

            $this->assertStringContainsString('class="form-actions"', $php,
                sprintf('%s no usa el pie común', $plantilla));
            $this->assertFalse(strpos($php, 'class="buttons"') !== false,
                sprintf('%s conserva el contenedor `buttons`, que ninguna hoja '
                    . 'pinta', $plantilla));

            $pie = $this->pie($php);
            $this->assertTrue(in_array('submit', $pie, true),
                sprintf('%s perdió el envío', $plantilla));
            if (in_array('reset', $pie, true) && in_array('submit', $pie, true))
                $this->assertLessThan(array_search('submit', $pie, true),
                    array_search('reset', $pie, true),
                    sprintf('en %s el envío tiene que quedar a la derecha del '
                        . 'reset', $plantilla));
            if (in_array('cancel', $pie, true) && in_array('reset', $pie, true))
                $this->assertLessThan(array_search('reset', $pie, true),
                    array_search('cancel', $pie, true),
                    sprintf('en %s «Cancelar» tiene que ir antes que el reset',
                        $plantilla));

            // La hoja ya dibuja el borde superior del pie: un `<hr>` delante
            // dejaría dos rayas seguidas.
            $this->assertFalse(
                (bool) preg_match('/<hr[^>]*>\s*<p class="form-actions">/', $php),
                sprintf('%s deja un `<hr>` delante del pie y salen dos líneas',
                    $plantilla));
        }
    }

    /* ------------------------------------------------------------------ */
    /* 3. La ficha del ticket del agente                                  */
    /* ------------------------------------------------------------------ */

    public function testLaFichaDelTicketTieneSusDosPies(): void {
        $php = $this->fichero('include/staff/ticket-view.inc.php');

        $this->assertSame(2, substr_count($php, 'class="form-actions"'),
            'la ficha del ticket tiene dos pies: responder y nota interna');

        $desde = 0;
        for ($i = 1; $i <= 2; $i++) {
            $pie = $this->pie($php, $desde);
            $this->assertTrue(in_array('submit', $pie, true)
                    && in_array('reset', $pie, true),
                sprintf('el pie %d de la ficha perdió el envío o el reset', $i));
            $this->assertLessThan(array_search('submit', $pie, true),
                array_search('reset', $pie, true),
                sprintf('en el pie %d el envío va delante del reset', $i));
            $desde = strpos($php, 'class="form-actions"', $desde) + 1;
        }
    }

    /* ------------------------------------------------------------------ */
    /* 4. Las casillas de esas pantallas                                  */
    /* ------------------------------------------------------------------ */

    public function testLasCasillasDeAgenteYClienteTienenEtiqueta(): void {
        $revisados = 0;

        foreach (array_merge($this->plantillasDelAgente(),
                $this->plantillasDelCliente()) as $pantalla) {
            $php = $this->fichero($pantalla);
            $etiquetas = $this->etiquetas($php);

            preg_match_all('/<label\b[^>]*\bfor="([^"]+)"/s', $php, $conFor);

            foreach ($this->entradas($php) as $entrada) {
                list($tag, $pos) = $entrada;
                if (!preg_match('/type="(checkbox|radio)"/', $tag))
                    continue;
                // Las casillas de una lista se marcan desde la fila entera
                // (`class="ckb"`/`mass`), no desde un texto al lado.
                if (strpos($tag, 'class="ckb') !== false
                        || strpos($tag, 'class="mass') !== false)
                    continue;
                // Hueco de la lista de campos: la celda «Delete» de un campo
                // que no se puede borrar es una casilla deshabilitada y sin
                // nombre.
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

        $this->assertTrue($revisados > 3,
            sprintf('sólo se revisaron %d controles: ¿se perdió el listado?',
                $revisados));
    }
}
