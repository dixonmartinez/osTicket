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

    /**
     * `?p=2` en una cola de una sola pagina no puede dejar la tabla vacia.
     *
     * `paginateSimple()` pone el OFFSET tal cual, asi que con 23 tickets y dos
     * paginas de 25 el enlace `?p=2` (historial del navegador, o un filtro que
     * acaba de reducir la cola) devolvia cero filas y el pie decia «esta cola
     * tiene tickets, pero la lista no se ha podido cargar», que es falso.
     */
    public function testUnaPaginaMasAllaDelFinalPintaLaUltima(): void {
        $source = $this->template();

        // La ventana se calcula DESPUES de conocer el total...
        $countAt = strpos($source, '$count = (int) $ticketsTotal->count();');
        $pageAt = strpos($source, '$tickets = $pageNav->paginateSimple($tickets);');
        $this->assertTrue($countAt !== false && $pageAt !== false);
        $this->assertTrue($countAt < $pageAt,
            'el total tiene que contarse antes de poner la ventana: si no, no '
            . 'hay con que recortar la pagina pedida');

        // ...y se recorta a la ultima que existe.
        $this->assertStringContainsString('$lastPage = (int) ceil($count / PAGE_LIMIT);',
            $source);
        $this->assertStringContainsString('if ($page > $lastPage)', $source);
        $this->assertStringContainsString('$page = $lastPage;', $source);

        // Con el total desconocido (`-`) no se toca: `is_int` lo distingue.
        $this->assertStringContainsString('is_int($count)', $source,
            'con el total desconocido no hay ultima pagina que calcular');
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
