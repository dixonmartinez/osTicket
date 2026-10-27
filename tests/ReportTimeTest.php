<?php
/**
 * tests/ReportTimeTest.php
 *
 * Un tiempo de respuesta no puede ser negativo.
 *
 * El *Statistics* nativo mide el tiempo de respuesta como
 * `timestampdiff(HOUR, parent.created, entry.created)`. Si la entrada con la
 * respuesta tiene fecha anterior al mensaje que la abrió, la resta sale por
 * debajo de cero y la tabla por departamento publicaba cifras como «-3.5 h»,
 * que no significan nada: el informe parecía roto.
 *
 * El origen estaba en los datos de demostración —la entrada de apertura se
 * quedaba en `NOW()` mientras las respuestas se fechaban días atrás— y ya se
 * corrige en `seed.php` (`backdate()`). Pero una base sembrada antes del
 * arreglo conserva esas filas, así que la consulta acota el resultado a cero.
 * Se acota **en la consulta** y no al imprimir: así el promedio tampoco se
 * contamina con los valores negativos.
 */

class ReportTimeTest extends UnitTestCase {

    private function source(): string {
        return (string) file_get_contents(
            ROOT_DIR . '/include/class.report.php');
    }

    /** El agregado del informe va envuelto en `GREATEST(..., 0)`. */
    public function testElTiempoDeRespuestaSeAcotaACero(): void {
        $source = $this->source();

        $this->assertStringContainsString("new SqlFunction('GREATEST'",
            $source, 'sin la cota, una base sembrada antes del arreglo sigue '
            . 'publicando tiempos negativos');

        // El `GREATEST` tiene que envolver a la resta del tiempo de
        // respuesta, no a cualquier otra expresión del informe.
        $start = strpos($source, "'ResponseTime'");
        $this->assertTrue($start !== false, 'no se encuentra el agregado');
        $body = substr($source, $start, 400);

        $this->assertStringContainsString("new SqlFunction('GREATEST'", $body,
            'la cota va en el propio agregado de ResponseTime');
        $this->assertStringContainsString('timestampdiff', $body);
    }

    /** Un comentario que explica el porqué, no sólo el qué. */
    public function testQuedaEscritoPorQueSeAcota(): void {
        $source = $this->source();

        $this->assertTrue(
            stripos($source, 'negativo') !== false
            && stripos($source, 'backdate') !== false,
            'el comentario debe enlazar con el origen del dato (seed.php)');
    }
}
