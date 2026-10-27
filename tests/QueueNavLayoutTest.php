<?php
/**
 * tests/QueueNavLayoutTest.php
 *
 * Dos regresiones de la capa moderna, con nombre y apellidos.
 *
 * 1. **La barra de colas no puede pasar a flex.** `#sub_nav` es la fila de
 *    colas guardadas (Open, My Tickets, Closed…) y la maneja el plugin
 *    `jb.overflowmenu` (`scp/js/jb.overflow.menu.js`): mide la fila con
 *    `innerWidth()` y los `offset()` de cada pestaña para decidir cuáles
 *    manda al menú de desbordamiento. Con `display:flex` y `gap`, la medida
 *    sale mal y esconde todas menos dos. Se vio en una captura de
 *    `/scp/tickets.php`: la barra quedaba casi vacía, con «Search» y «New
 *    Ticket» y nada más — el «filtro de tickets» que faltaba.
 *
 * 2. **La columna ordenada no puede ser un bloque de color.** `scp.css` pinta
 *    la cabecera activa con `background: … #cfe6ff` de punta a punta de la
 *    celda y `dark.css` la repetía en gris. El resultado parecía una columna
 *    rota. Ahora el indicador es el color de acento con fondo suave, en los
 *    dos temas.
 */

class QueueNavLayoutTest extends UnitTestCase {

    /**
     * Bloques `selector { … }` del CSS, con la lista de selectores separada.
     *
     * Se saltan las reglas de `@media`/`@supports` a propósito: lo que se
     * comprueba son las declaraciones base.
     *
     * @return array  lista de array('selectors' => string[], 'body' => string)
     */
    static function blocks(string $css): array {
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        $blocks = array();
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $selectors = array_map('trim', explode(',', $match[1]));
            // Descartar at-rules y lo que queda raro tras quitar comentarios
            $selectors = array_values(array_filter($selectors,
                function ($s) { return $s !== '' && $s[0] !== '@'; }));
            if (!$selectors)
                continue;
            $blocks[] = array('selectors' => $selectors,
                'body' => $match[2]);
        }

        return $blocks;
    }

    /** Los bloques cuyo selector incluye exactamente uno de los dados. */
    static function blocksFor(array $blocks, array $selectors): array {
        $found = array();
        foreach ($blocks as $block) {
            // Un bloque puede coincidir por 'a' y por 'b': se cuenta una vez
            foreach ($block['selectors'] as $selector) {
                if (in_array($selector, $selectors, true)) {
                    $found[] = $block;
                    break;
                }
            }
        }

        return $found;
    }

    private function modern(): string {
        return (string) file_get_contents(ROOT_DIR . '/css/modern.css');
    }

    private function dark(): string {
        return (string) file_get_contents(ROOT_DIR . '/css/dark.css');
    }

    /* ------------------------------------------------------------------ */
    /* El analizador                                                       */
    /* ------------------------------------------------------------------ */

    public function testElAnalizadorSeparaSelectoresYDeclaraciones(): void {
        $blocks = self::blocks("#a, .b {\n  color: red;\n}\n.c { display: flex; }");

        $this->assertCount(2, $blocks);
        $this->assertSame(array('#a', '.b'), $blocks[0]['selectors']);
        $this->assertStringContainsString('color: red', $blocks[0]['body']);
    }

    /* ------------------------------------------------------------------ */
    /* 1. El flujo de la barra de colas                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Ni `#nav`, ni `#sub_nav`, ni la barra de búsqueda pueden ser flex.
     *
     * Es la comprobación que habría cazado el fallo antes de la captura: si
     * alguien vuelve a poner `display:flex` ahí, las colas desaparecen.
     */
    public function testLaBarraDeColasNoPasaAFlex(): void {
        $vigilados = array(
            '#nav', 'ul#nav', '#sub_nav', 'ul#sub_nav',
            '#nav li', '#sub_nav > li', '#basic_search', '#basic_search form',
        );

        $problemas = array();
        foreach (self::blocksFor(self::blocks($this->modern()), $vigilados)
                as $block) {
            if (preg_match('/display\s*:\s*flex/i', $block['body']))
                $problemas[] = implode(', ', $block['selectors'])
                    . ' → display: flex';
            if (preg_match('/(^|[^-])gap\s*:/i', $block['body']))
                $problemas[] = implode(', ', $block['selectors']) . ' → gap';
        }

        $this->assertSame(array(), $problemas,
            "estos bloques rompen la medida que hace jb.overflowmenu y esconden "
            . "las colas:\n  " . implode("\n  ", $problemas));
    }

    /** La explicación tiene que seguir en el CSS: es lo que evita repetirlo. */
    public function testElMotivoEstaDocumentadoEnElCss(): void {
        $css = $this->modern();

        $this->assertStringContainsString('jb.overflowmenu', $css);
        $this->assertStringContainsString('offset()', $css);
    }

    /** Y el aspecto de pastilla se mantiene, que es lo que se buscaba. */
    public function testLasPestanasSiguenSiendoPastillas(): void {
        $css = $this->modern();

        $this->assertMatchesRegularExpression(
            '/#sub_nav > li > a[^{]*\{[^}]*border-radius:\s*var\(--mod-radius-pill\)/s',
            $css);
        $this->assertStringContainsString('--mod-radius-pill: 999px', $css);
    }

    /* ------------------------------------------------------------------ */
    /* 2. La cabecera ordenada                                             */
    /* ------------------------------------------------------------------ */

    /** El indicador de orden usa el acento, no un bloque de color plano. */
    public function testLaCabeceraOrdenadaUsaElAcento(): void {
        $bloques = self::blocksFor(self::blocks($this->modern()),
            array('table.list thead th a.asc', 'table.list thead th a.desc'));

        $this->assertTrue((bool) $bloques,
            'falta la regla del indicador de orden');
        $this->assertStringContainsString('var(--ui-accent-soft)',
            $bloques[0]['body']);
        $this->assertStringContainsString('var(--ui-accent)',
            $bloques[0]['body']);
    }

    /** Y en el tema oscuro tampoco: era un bloque gris (`--dk-surface-4`). */
    public function testElIndicadorDeOrdenEnOscuroNoEsUnBloqueGris(): void {
        $oscuro = $this->dark();

        $inicio = strpos($oscuro, 'table.list thead th a.asc');
        $this->assertTrue($inicio !== false, 'falta la regla del tema oscuro');
        $bloque = substr($oscuro, $inicio, 320);

        $this->assertStringContainsString('var(--dk-accent-soft)', $bloque);
        $this->assertSame(false, strpos($bloque, 'var(--dk-surface-4)'),
            'el indicador de orden no debe ser un bloque de superficie');
    }

    /* ------------------------------------------------------------------ */
    /* 3. Cebras y etiquetas de prioridad                                  */
    /* ------------------------------------------------------------------ */

    /**
     * El tema oscuro no repinta las filas impares: la tabla moderna no tiene
     * cebras y esa regla las devolvía sólo en oscuro (tablas distintas según
     * el tema).
     */
    public function testElTemaOscuroNoDevuelveLasCebras(): void {
        $oscuro = $this->dark();

        $this->assertMatchesRegularExpression(
            '/table\.list tbody tr:nth-child\(2n\+1\) td\s*\{[^}]*transparent/s',
            $oscuro);
    }

    /** La prioridad se ve como etiqueta, no como rectángulo a medias. */
    public function testLaPrioridadEsUnaEtiquetaRedondeada(): void {
        $bloques = self::blocksFor(self::blocks($this->modern()),
            array('table.list tbody td[style*="background-color"] > div',
                'table.list tbody td[style*="background"] > div'));

        $this->assertTrue((bool) $bloques, 'falta la regla de la prioridad');
        $this->assertStringContainsString('border-radius',
            $bloques[0]['body']);
        $this->assertStringContainsString('inline-block', $bloques[0]['body']);
    }
}
