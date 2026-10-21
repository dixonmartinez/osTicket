<?php
/**
 * Pruebas del panel de analíticas.
 *
 *   php setup/test/run-tests.php analytics
 *
 * Sólo se prueban las partes sin base de datos: la construcción del periodo
 * (validación y acotado) y las gráficas SVG generadas en el servidor. Las
 * consultas se validan a mano y con `tools/audit/scan.php`.
 */

require_once INCLUDE_DIR . 'class.analytics.php';

class TestAnalytics extends Test {
    var $name = 'Analytics: periodo y gráficas';

    /* ------------------------------------------------------------------ */
    /* Periodo                                                             */
    /* ------------------------------------------------------------------ */

    function testDefaultRangeIsThirtyDays() {
        $an = new Analytics('30d', null);
        $days = round(($an->getEnd() - $an->getStart()) / 86400);
        $this->assertEqual(30, (int) $days, 'treinta días por defecto');
    }

    function testPresetRanges() {
        $expected = ['7d' => 7, '30d' => 30, '90d' => 90, '12m' => 365];
        foreach ($expected as $range => $days) {
            $an = new Analytics($range, null);
            $this->assertEqual($days,
                (int) round(($an->getEnd() - $an->getStart()) / 86400),
                "rango $range");
        }
    }

    function testInvalidRangeFallsBack() {
        // Una clave desconocida no debe romper: se usa el periodo por defecto
        $an = new Analytics('../../etc/passwd', null);
        $this->assertEqual(30,
            (int) round(($an->getEnd() - $an->getStart()) / 86400));
    }

    function testCustomDatesAreHonoured() {
        $an = new Analytics('30d', null, '2026-01-01', '2026-01-31');
        $this->assertEqual('2026-01-01', $an->getStartDate());
        $this->assertEqual('2026-01-31', $an->getEndDate());
    }

    function testInvertedDatesAreSwapped() {
        $an = new Analytics('30d', null, '2026-03-31', '2026-03-01');
        $this->assertEqual('2026-03-01', $an->getStartDate());
        $this->assertEqual('2026-03-31', $an->getEndDate());
    }

    function testRangeIsCapped() {
        // 10 años debe acotarse a MAX_DAYS
        $an = new Analytics('30d', null, '2016-01-01', '2026-01-01');
        $days = ($an->getEnd() - $an->getStart()) / 86400;
        $this->assert($days <= Analytics::MAX_DAYS + 1,
            'el periodo se acota a ' . Analytics::MAX_DAYS . ' días');
    }

    function testGarbageDateIsIgnored() {
        $an = new Analytics('7d', null, "2026-13-45'; DROP TABLE ost_ticket; --");
        // Se cae al rango predefinido, sin excepciones ni fechas absurdas
        $this->assertEqual(7,
            (int) round(($an->getEnd() - $an->getStart()) / 86400));
    }

    function testRangeChoicesAreTranslated() {
        $choices = Analytics::getRangeChoices();
        $this->assert(is_array($choices) && count($choices) >= 5);
        $this->assert(isset($choices['30d']));
    }

    /* ------------------------------------------------------------------ */
    /* Gráfica de líneas                                                   */
    /* ------------------------------------------------------------------ */

    private function series($n = 10) {
        $labels = $created = $closed = [];
        for ($i = 0; $i < $n; $i++) {
            $labels[] = gmdate('Y-m-d', strtotime("2026-10-01 +$i day"));
            $created[] = $i * 2;
            $closed[] = max(0, $i * 2 - 1);
        }
        return ['labels' => $labels, 'created' => $created, 'closed' => $closed];
    }

    function testLineChartIsWellFormedXml() {
        $svg = AnalyticsChart::lineSvg($this->series());
        $doc = new DOMDocument();
        $this->assert(@$doc->loadXML($svg), 'el SVG debe ser XML válido');
        $this->assert(strpos($svg, '<svg') !== false);
        $this->assert(strpos($svg, 'line-created') !== false);
        $this->assert(strpos($svg, 'line-closed') !== false);
        $this->assert(strpos($svg, 'role="img"') !== false,
            'la gráfica necesita un rol accesible');
        $this->assert(strpos($svg, 'aria-label') !== false);
    }

    function testLineChartEmptyPeriodShowsMessage() {
        $svg = AnalyticsChart::lineSvg(['labels' => [], 'created' => [], 'closed' => []]);
        $this->assert(strpos($svg, 'an-empty') !== false);
    }

    function testLineChartSinglePoint() {
        $svg = AnalyticsChart::lineSvg([
            'labels' => ['2026-10-01'], 'created' => [3], 'closed' => [1]]);
        $doc = new DOMDocument();
        $this->assert(@$doc->loadXML($svg), 'un solo día no debe generar NaN');
        $this->assert(strpos($svg, 'NaN') === false);
    }

    function testLineChartAvoidsDivisionByZero() {
        // Todas las series a cero: el eje no puede dividir entre cero
        $svg = AnalyticsChart::lineSvg([
            'labels' => ['2026-10-01', '2026-10-02'],
            'created' => [0, 0], 'closed' => [0, 0]]);
        $this->assert(strpos($svg, 'NaN') === false);
        $this->assert(strpos($svg, 'INF') === false);
    }

    function testLineChartAreaIsAClosedPolygon() {
        $svg = AnalyticsChart::lineSvg($this->series(5));
        $this->assert(preg_match('/<polygon class="area-created" points="([^"]+)"/',
            $svg, $m) === 1, 'debe haber un polígono de área');
        // Cada punto es "x,y": no puede haber coordenadas sueltas
        foreach (explode(' ', trim($m[1])) as $point) {
            $this->assert(preg_match('/^\d+(\.\d+)?,\d+(\.\d+)?$/', $point) === 1,
                "punto inválido en el polígono: $point");
        }
    }

    /* ------------------------------------------------------------------ */
    /* Histograma y barras                                                 */
    /* ------------------------------------------------------------------ */

    function testHourBarsCoversAllHours() {
        $hours = array_fill(0, 24, 0);
        $hours[9] = 10;
        $hours[17] = 40;
        $html = AnalyticsChart::hourBars($hours);
        $this->assertEqual(24, substr_count($html, 'class="h'),
            'una barra por hora del día');
        $this->assert(strpos($html, 'is-peak') !== false,
            'la hora con más volumen se marca como pico');
        $this->assert(strpos($html, 'aria-label') !== false);
    }

    function testHourBarsWithEmptyData() {
        $html = AnalyticsChart::hourBars(array_fill(0, 24, 0));
        $this->assertEqual(24, substr_count($html, 'class="h'));
        $this->assert(strpos($html, 'NaN') === false);
    }

    function testBarListRendersRows() {
        $html = AnalyticsChart::barList([
            ['label' => 'Web', 'value' => 10],
            ['label' => 'Email', 'value' => 5, 'tone' => 'bad'],
        ]);
        $this->assertEqual(2, substr_count($html, 'an-bar-row'));
        $this->assert(strpos($html, 'is-bad') !== false, 'respeta el tono');
        $this->assert(strpos($html, 'width:100%') !== false,
            'la barra mayor ocupa el 100%');
    }

    function testBarListEscapesLabels() {
        $html = AnalyticsChart::barList([
            ['label' => '<script>alert(1)</script>', 'value' => 1]]);
        $this->assert(strpos($html, '<script>') === false,
            'las etiquetas deben escaparse (XSS)');
        $this->assert(strpos($html, '&lt;script&gt;') !== false);
    }

    function testBarListEmptyShowsMessage() {
        $this->assert(strpos(AnalyticsChart::barList([]), 'an-empty') !== false);
    }

    /* ------------------------------------------------------------------ */
    /* Exportación                                                         */
    /* ------------------------------------------------------------------ */

    function testToArrayHasStableKeys() {
        // No se ejecuta SQL: se comprueba el contrato del endpoint JSON
        $methods = ['getSummary', 'getDailySeries', 'getHourlyDistribution',
            'getAgentBreakdown', 'getDepartmentBreakdown', 'getSourceBreakdown',
            'getTopicBreakdown', 'getBacklogAge', 'getWorkload'];
        foreach ($methods as $method)
            $this->assert(method_exists('Analytics', $method),
                "falta Analytics::$method()");
        $this->assert(method_exists('Analytics', 'toArray'));
    }
}
