<?php
/**
 * tests/ThemeControlsTest.php
 *
 * Reglas de la capa moderna sobre los controles, para no perder lo que ya
 * funcionaba al "modernizar".
 *
 * El caso que motivo esta prueba: `.action-button` no es un boton neutro, se
 * usa también en los «Add New …» de color (`green button action-button`). Una
 * regla genérica en `css/modern.css` habría pintado su texto de gris y les
 * habría quitado el color. La regla tiene que excluir los que llevan color
 * propio.
 */

class ThemeControlsTest extends UnitTestCase {

    private function modern(): string {
        return (string) file_get_contents(ROOT_DIR . '/css/modern.css');
    }

    /** Bloques `selector { … }` con sus selectores por separado. */
    private function blocks(): array {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->modern());
        $blocks = array();
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $selectors = array_values(array_filter(
                array_map('trim', explode(',', $match[1])),
                function ($s) { return $s !== '' && $s[0] !== '@'; }));
            if ($selectors)
                $blocks[] = array('selectors' => $selectors, 'body' => $match[2]);
        }

        return $blocks;
    }

    /** Los selectores que alcanzan a `.action-button`. */
    private function actionButtonSelectors(): array {
        $found = array();
        foreach ($this->blocks() as $block)
            foreach ($block['selectors'] as $selector)
                if (strpos($selector, '.action-button') !== false)
                    $found[] = $selector;

        return $found;
    }

    /**
     * Ningún selector que pinte el color de `.action-button` puede alcanzar a
     * los botones verdes, rojos, azules ni a los de peligro.
     */
    public function testLosBotonesDeColorNoLosAlcanzaLaReglaNeutra(): void {
        $selectores = $this->actionButtonSelectors();
        $this->assertTrue((bool) $selectores,
            'no se encontró ninguna regla de .action-button en css/modern.css');

        $problemas = array();
        foreach ($selectores as $selector) {
            // Sólo importan las que pisan color, fondo o sombra
            $pinta = false;
            foreach ($this->blocks() as $block)
                if (in_array($selector, $block['selectors'], true)
                        && preg_match('/(^|;)\s*(color|background|box-shadow)\s*:/i',
                            $block['body']))
                    $pinta = true;
            if (!$pinta)
                continue;

            foreach (array('green', 'red', 'blue', 'primary', 'danger') as $color)
                if (strpos($selector, ':not(.' . $color . ')') === false)
                    $problemas[] = "$selector no excluye .$color";
        }

        $this->assertSame(array(), $problemas,
            "estos selectores le quitarian el color a los botones de color:\n  "
            . implode("\n  ", $problemas));
    }

    /**
     * Los controles de la barra de la cola llevan alto elástico: `scp.css` los
     * fija en 22 px y el borde propio no cabía.
     */
    public function testLosControlesDeLaBarraNoTienenAltoFijo(): void {
        $alturas = array();
        foreach ($this->blocks() as $block)
            foreach ($block['selectors'] as $selector)
                if (strpos($selector, '.action-button') !== false
                        && preg_match('/height\s*:\s*([^;]+)/', $block['body'], $m))
                    $alturas[] = trim($m[1]);

        $this->assertTrue((bool) $alturas,
            'cs/modern.css no ajusta el alto de .action-button');
        foreach ($alturas as $altura)
            $this->assertSame('auto', $altura,
                'el alto de .action-button debe ser elástico, no fijo');
    }

    /* ------------------------------------------------------------------ */
    /* Pistas en el buscador                                               */
    /* ------------------------------------------------------------------ */

    /**
     * El campo de búsqueda de colas y tareas explica qué busca cuando está
     * vacío, y tiene nombre accesible aunque el placeholder no se lea.
     */
    public function testElBuscadorDeColasYTareasTienePistaYNombre(): void {
        $casos = array(
            'include/staff/templates/queue-tickets.tmpl.php' => 'Search tickets',
            'include/staff/tasks.inc.php' => 'Search tasks',
        );

        foreach ($casos as $file => $texto) {
            $source = (string) file_get_contents(ROOT_DIR . '/' . $file);

            $this->assertMatchesRegularExpression(
                '/placeholder="<\?php echo __\(\'' . $texto . '\.\.\.\'\); \?>"/',
                $source, "$file: falta el placeholder traducido");
            $this->assertMatchesRegularExpression(
                '/aria-label="<\?php echo __\(\'' . $texto . '\'\); \?>"/',
                $source, "$file: falta el nombre accesible del campo");
        }
    }
}
