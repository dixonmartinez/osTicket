<?php
/**
 * tests/TextScaleTest.php
 *
 * La escala tipográfica de la capa moderna y la etiqueta de prioridad.
 *
 * Origen: una captura del usuario en `/scp/tickets.php` con la nota «el texto
 * se ve pequeño». El cuerpo estaba en 14 px y, peor, los campos de formulario
 * no fijaban tamaño: un `<input>`, `<select>` o `<button>` **no hereda** la
 * tipografía del `body`, así que el navegador les pone su `13.3px Arial`. El
 * buscador y los botones salían más pequeños que el texto de al lado.
 *
 * En la misma captura había otro defecto: la prioridad se pintaba como un
 * rectángulo de todo el ancho de la columna, porque
 * `PriorityField::display()` mete el `background-color` en el `<td>` **y** en
 * el `<div>`.
 *
 * Esto se prueba leyendo las hojas: son reglas, no lógica. Una prueba con
 * navegador (make contrast-audit) mide el resultado; esta evita que el
 * siguiente cambio vuelva a dejar los campos sin tamaño.
 */

class TextScaleTest extends UnitTestCase {

    private function hoja($name): string {
        return file_get_contents(ROOT_DIR . '/css/' . $name);
    }

    /**
     * El valor declarado para una propiedad dentro de un bloque, o null.
     *
     * Los bloques de la hoja van a menudo con varios selectores a la vez
     * (`a > div,\nb > div { ... }`), así que se recorren las reglas y se
     * compara el selector entero, no el texto literal que precede a `{`.
     */
    private function declaracion($css, $bloque, $propiedad) {
        // Fuera los comentarios: si no, el selector de una regla llega con el
        // comentario que lo precede pegado delante.
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        if (!preg_match_all('/([^{}]+)\{([^}]*)\}/s', $css, $reglas, PREG_SET_ORDER))
            return null;
        foreach ($reglas as $regla) {
            $selectores = array_map('trim', explode(',', $regla[1]));
            if (!in_array($bloque, $selectores, true))
                continue;
            if (preg_match('/' . preg_quote($propiedad, '/') . '\s*:\s*([^;]+);/',
                    $regla[2], $v))
                return trim($v[1]);
        }
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* La escala                                                          */
    /* ------------------------------------------------------------------ */

    function testLaEscalaViveEnTresTokens() {
        $css = $this->hoja('modern.css');

        $this->assertSame('15px', $this->declaracion($css, ':root', '--mod-text'),
            'el cuerpo de la aplicación va en 15px');
        $this->assertSame('14px',
            $this->declaracion($css, ':root', '--mod-text-sm'),
            'navegación, botones y textos de apoyo un paso por debajo');
        $this->assertSame('12px',
            $this->declaracion($css, ':root', '--mod-text-xs'),
            'cabeceras de tabla y etiquetas; por debajo de 12px no se lee');
    }

    function testElCuerpoUsaLaEscala() {
        $css = $this->hoja('modern.css');

        $this->assertSame('var(--mod-text)',
            $this->declaracion($css, 'body', 'font-size'),
            'body tiene que usar el token; si no, la escala y el texto se'
            . ' separan y cambiar --mod-text no sirve de nada');
    }

    /**
     * Los controles de formulario no heredan la tipografía del cuerpo. Si no
     * fijan `font-size`, el navegador les pone el suyo (~13.3px Arial) y el
     * defecto vuelve.
     */
    function testLosCamposFijanSuTamano() {
        $css = $this->hoja('modern.css');

        // El bloque agrupado de campos: input[type="text"], textarea, select…
        $this->assertTrue(
            (bool) preg_match('/input\[type="text"\][^{]*\{[^}]*'
                . 'font-size:\s*var\(--mod-text\)/s', $css),
            'los campos de texto no fijan font-size');
        $this->assertTrue(
            (bool) preg_match('/textarea,\s*select\s*\{[^}]*'
                . 'font-family:\s*var\(--mod-font\)/s', $css),
            'textarea y select no fijan ni la familia: el navegador usa Arial');
    }

    /**
     * Ningún tamaño suelto por debajo de 12 px, y ninguno escrito a mano: la
     * escala se toca en un sitio, no en veinte.
     */
    function testNoHayTamanosSueltosPequeños() {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->hoja('modern.css'));

        $chicos = array();
        if (preg_match_all('/font-size:\s*(\d+(?:\.\d+)?)px/', $css, $m))
            foreach ($m[1] as $valor)
                if ((float) $valor < 12)
                    $chicos[] = $valor;

        $this->assertSame(array(), $chicos,
            'hay tamaños por debajo de 12 px: ' . implode(', ', $chicos));
    }

    /** El modo compacto es de espaciado: no puede deshacer la escala. */
    function testElModoCompactoNoTocaLaEscala() {
        $this->assertSame(0, substr_count($this->hoja('density.css'),
            'font-size'),
            'css/density.css no debe fijar tamaños de fuente (solo espaciado)');
    }

    /* ------------------------------------------------------------------ */
    /* La etiqueta de prioridad                                           */
    /* ------------------------------------------------------------------ */

    /**
     * `PriorityField::display()` escribe el mismo `background-color` en el
     * `<td>` y en el `<div>` de dentro. Pintar el `<td>` de punta a punta
     * convierte la columna en un rectángulo de color con el texto pegado a la
     * izquierda. El color se queda en la etiqueta.
     */
    function testElColorDePrioridadNoPintaLaCeldaEntera() {
        $css = $this->hoja('modern.css');

        $valor = $this->declaracion($css,
            'table.list tbody td[style*="background"]', 'background');
        $this->assertSame('transparent !important', $valor,
            'hace falta !important: el color viene en un atributo style y le'
            . ' gana a cualquier regla de la hoja');

        $this->assertSame('inline-block',
            $this->declaracion($css,
                'table.list tbody td[style*="background-color"] > div',
                'display'),
            'la etiqueta tiene que medir lo que mide su texto, no la columna');
    }

    /** El contraste en oscuro se resuelve en dark.css, que va después. */
    function testElTemaOscuroDevuelveTextoOscuroALaEtiqueta() {
        $dark = $this->hoja('dark.css');

        $this->assertTrue(
            strpos($dark, 'td[style*="background-color"]') !== false
            && strpos($dark, 'color: #10202e !important') !== false,
            'sin esto, la etiqueta (color claro que elige el administrador)'
            . ' se queda con el texto claro del tema oscuro');
    }
}
