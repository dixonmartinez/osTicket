<?php
/**
 * tests/QueueEmptyStateTest.php
 *
 * La cola sin resultados tiene que explicarse.
 *
 * Antes, cuando no había filas, la plantilla dejaba una sola línea en el pie
 * de la tabla («Query returned 0 results.»). Con la tabla vacía, el agente ve
 * una página en blanco y no sabe si la cola está vacía o si algo se rompió:
 * es exactamente lo que pasó al ver el panel sin filas por un error de SQL,
 * donde nadie podía distinguir «no hay tickets» de «la consulta falló».
 *
 * Estas pruebas fijan el marcado y el estilo de ese estado, y sobre todo que
 * **el mensaje de error siga teniendo prioridad**: si la consulta falla, se
 * enseña el error y no la pista genérica.
 */

class QueueEmptyStateTest extends UnitTestCase {

    private function template(): string {
        return (string) file_get_contents(ROOT_DIR
            . '/include/staff/templates/queue-tickets.tmpl.php');
    }

    private function css(): string {
        return (string) file_get_contents(ROOT_DIR . '/css/modern.css');
    }

    /** El estado vacío tiene marcado propio, no una línea suelta. */
    public function testLaColaVaciaTieneUnEstadoPropio(): void {
        $source = $this->template();

        $this->assertStringContainsString('class="empty-state"', $source);
        $this->assertStringContainsString('<i class="icon-inbox"></i>', $source,
            'el estado vacío lleva un icono que existe en js/icons.js');
    }

    /** Si la consulta falló, se enseña el error, no la pista. */
    public function testElErrorTienePrioridadSobreLaPista(): void {
        $source = $this->template();

        $this->assertStringContainsString('$ferror', $source);
        $this->assertMatchesRegularExpression(
            '/\$ferror\s*\?\s*Format::htmlchars\(\$ferror\)/',
            $source,
            'el error de la consulta se sigue escapando con Format::htmlchars');

        // La pista genérica sólo aparece cuando no hay error
        $this->assertMatchesRegularExpression('/if\s*\(!\$ferror\)/', $source,
            'la pista no puede sustituir al error: va condicionada a !$ferror');
    }

    /** Los dos textos pasan por la traducción. */
    public function testLosTextosSeTraducen(): void {
        $source = $this->template();

        $this->assertStringContainsString("__('Query returned 0 results.')", $source);
        $this->assertStringContainsString(
            "__('Try clearing the search or picking another queue.')", $source);
    }

    /**
     * El pie no puede prometer filas que la tabla no trae.
     *
     * En la cola «Closed · This Year» se leia «Showing 1 - 23 of about 23»
     * sobre una tabla vacia: el total salia de un COUNT sobre la misma
     * consulta, pero la pagina que se pintaba volvia sin una sola fila. La
     * plantilla cuenta ahora lo que de verdad ha pintado.
     */
    public function testElPieNoPrometeFilasQueLaTablaNoTrae(): void {
        $source = $this->template();

        $this->assertStringContainsString('$rendered++', $source,
            'hay que contar las filas que se pintan');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\$rendered\s*&&\s*\$count\s*\)/',
            $source,
            'sin filas no se puede seguir mostrando el total de la cola');

        $this->assertStringContainsString('$pageNav->setTotal(0, true);', $source,
            'el paginador tiene que olvidar el total cuando la tabla queda vacia');
    }

    /** El estilo existe y no fija colores: el tema oscuro lo hereda. */
    public function testElEstiloDelEstadoVacioUsaTokens(): void {
        $css = $this->css();

        $this->assertStringContainsString('.empty-state {', $css);

        // Del selector hasta su cierre, sin un solo color literal
        $start = strpos($css, '.empty-state {');
        $block = substr($css, $start, 900);
        $this->assertSame(0,
            preg_match('/:\\s*#[0-9a-fA-F]{3,8}\\b/', substr($block, 0, strpos($block, '}'))),
            'el estado vacío no debe fijar un color: en oscuro se rompería');
    }
}
