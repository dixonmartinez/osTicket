<?php
/**
 * tests/AnalyticsTest.php
 *
 * Pruebas de las piezas de `include/class.analytics.php` que no necesitan
 * base de datos.
 *
 * El motivo de este archivo: al pasar las consultas de analíticas al ORM
 * (frente 3) desapareció `Analytics::deptClause()` mientras dos consultas
 * —las dos que el ORM no puede expresar— seguían llamándola. El resultado
 * era un error fatal al abrir el panel:
 *
 *     Uncaught Error: Call to undefined method Analytics::deptClause()
 *
 * Un `grep` no lo ve y la sintaxis es válida; la prueba lo ve. Por eso hay
 * dos cosas aquí: el comportamiento del fragmento SQL y una comprobación de
 * que **todos** los métodos que la clase se llama a sí misma existen.
 */

require_once INCLUDE_DIR . 'class.analytics.php';

class AnalyticsTest extends UnitTestCase {

    /** Invoca un método privado de Analytics (sólo para las pruebas). */
    private function call(Analytics $analytics, $method, array $args = array()) {
        $ref = new ReflectionMethod('Analytics', $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($analytics, $args);
    }

    /**
     * Un Analytics con el alcance de departamentos que interese.
     *
     * El constructor deduce el alcance del agente en sesión; aquí no hay
     * sesión, así que se fija la propiedad privada directamente.
     */
    private function scoped($depts) {
        $analytics = new Analytics('30d', null);
        $prop = new ReflectionProperty('Analytics', 'depts');
        $prop->setAccessible(true);
        $prop->setValue($analytics, $depts);

        return $analytics;
    }

    /* ------------------------------------------------------------------ */
    /* El fragmento SQL de departamentos                                   */
    /* ------------------------------------------------------------------ */

    public function testSinRestriccionNoAnadeCondicion(): void {
        // `null` = todas las áreas (un administrador viendo el informe)
        $this->assertSame('', $this->call($this->scoped(null), 'deptClause'));
    }

    public function testSinDepartamentosNoDevuelveNada(): void {
        // Un agente sin departamentos no debe ver métricas de nadie. `IN ()`
        // no es SQL válido, así que se usa una condición siempre falsa.
        $this->assertSame(' AND 1=0',
            $this->call($this->scoped(array()), 'deptClause'));
    }

    public function testConDepartamentosUsaLaColumnaPorOmision(): void {
        $this->assertSame(' AND t.dept_id IN (3,7)',
            $this->call($this->scoped(array(3, 7)), 'deptClause'));
    }

    public function testAceptaLaColumnaDeLaTablaDerivada(): void {
        // La subconsulta de la primera respuesta aliasa la tabla como `t0`
        $this->assertSame(' AND t0.dept_id IN (1)',
            $this->call($this->scoped(array(1)), 'deptClause',
                array('t0.dept_id')));
    }

    public function testSaneaLosIdentificadores(): void {
        $this->assertSame(' AND t.dept_id IN (3,7)',
            $this->call($this->scoped(array('3', '7abc')), 'deptClause'));
    }

    public function testIgnoraUnaColumnaQueNoEsUnaColumna(): void {
        // La columna nunca viene del usuario, pero si algún día se pasara una
        // cadena construida, no puede acabar dentro del SQL.
        $this->assertSame(' AND t.dept_id IN (2)',
            $this->call($this->scoped(array(2)), 'deptClause',
                array('t.dept_id; DROP TABLE ost_ticket')));
    }

    public function testElFiltroDelOrmCoincideConElDeSql(): void {
        // Las dos vías tienen que excluir lo mismo: si una cambia y la otra no,
        // el informe enseña números de departamentos que el agente no ve.
        $this->assertSame(array(), $this->call($this->scoped(null), 'deptFilter'));
        $this->assertSame(array('dept_id__in' => array(-1)),
            $this->call($this->scoped(array()), 'deptFilter'));
        $this->assertSame(array('dept_id__in' => array(3, 7)),
            $this->call($this->scoped(array(3, 7)), 'deptFilter'));
    }

    /* ------------------------------------------------------------------ */
    /* Que no vuelva a faltar un método                                    */
    /* ------------------------------------------------------------------ */

    public function testTodosLosMetodosQueUsaLaClaseExisten(): void {
        // Esta es la prueba que habría cazado el error fatal: se leen las
        // llamadas `$this->loQueSea(` del archivo y se comprueba que el
        // método esté declarado.
        $source = file_get_contents(INCLUDE_DIR . 'class.analytics.php');
        $this->assertTrue((bool) $source, 'no se pudo leer class.analytics.php');

        preg_match_all('/\$this->(\w+)\s*\(/', $source, $matches);
        $called = array_values(array_unique($matches[1]));
        // Un puñado de llamadas internas (17 con la versión actual): si el
        // archivo se sustituye por otro, esta prueba no debe pasar en falso
        $this->assertTrue(count($called) >= 10,
            'no se encontraron llamadas $this-> en class.analytics.php');

        foreach ($called as $method)
            $this->assertTrue(method_exists('Analytics', $method),
                "falta Analytics::{$method}(), que la clase usa");
    }

    public function testLosMetodosLlamadosConSelfExisten(): void {
        // `self::countIf()` y compañía: el mismo control por el otro camino
        $source = file_get_contents(INCLUDE_DIR . 'class.analytics.php');
        preg_match_all('/(?:self|static)::(\w+)\s*\(/', $source, $matches);

        foreach (array_unique($matches[1]) as $method)
            $this->assertTrue(method_exists('Analytics', $method),
                "falta Analytics::{$method}(), que la clase usa");
    }
}
