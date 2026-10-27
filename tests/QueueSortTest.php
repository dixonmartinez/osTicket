<?php
/**
 * tests/QueueSortTest.php
 *
 * Pruebas de `QueueSortPlan::reconcile()`, la pieza pura que decide qué pasa
 * con las ordenaciones de una cola guardada cuando el formulario manda otra
 * lista (frente 2 de docs/CODE_QUALITY.md).
 *
 * Los valores del POST son cadenas y los ids guardados son enteros: la
 * comparación de antes era `array_search()` sin `strict`, así que '3' y 3 son
 * la misma ordenación. Eso se prueba aquí porque es justo lo que rompería una
 * «mejora» que añadiera `true` al tercer argumento.
 */
class QueueSortTest extends UnitTestCase {

    public function testSinCambiosTodoSeQuedaEnSuSitio(): void {
        $plan = QueueSortPlan::reconcile(array(1, 2), array(1, 2));

        $this->assertSame(array(1 => 0, 2 => 1), $plan['keep']);
        $this->assertSame(array(), $plan['remove']);
        $this->assertSame(array(), $plan['add']);
    }

    public function testReordenarCambiaLaPosicion(): void {
        $plan = QueueSortPlan::reconcile(array(1, 2), array(2, 1));

        $this->assertSame(array(1 => 1, 2 => 0), $plan['keep']);
        $this->assertSame(array(), $plan['remove']);
        $this->assertSame(array(), $plan['add']);
    }

    public function testLoQueYaNoVieneSeQuita(): void {
        $plan = QueueSortPlan::reconcile(array(1, 2), array(2));

        $this->assertSame(array(2 => 0), $plan['keep']);
        $this->assertSame(array(1), $plan['remove']);
        $this->assertSame(array(), $plan['add']);
    }

    public function testLoQueLlegaNuevoSeAnadeEnSuPosicion(): void {
        $plan = QueueSortPlan::reconcile(array(1), array(1, 3));

        $this->assertSame(array(1 => 0), $plan['keep']);
        $this->assertSame(array(), $plan['remove']);
        $this->assertSame(array(array(3, 1)), $plan['add']);
    }

    public function testUnaColaSinOrdenacionesGuardaLasDelFormulario(): void {
        $plan = QueueSortPlan::reconcile(array(), array(5, 6));

        $this->assertSame(array(), $plan['keep']);
        $this->assertSame(array(array(5, 0), array(6, 1)), $plan['add']);
    }

    public function testQuitarTodasLasOrdenaciones(): void {
        $plan = QueueSortPlan::reconcile(array(1), array());

        $this->assertSame(array(), $plan['keep']);
        $this->assertSame(array(1), $plan['remove']);
        $this->assertSame(array(), $plan['add']);
    }

    /** Mezcla de las tres cosas a la vez: la lista queda como vino. */
    public function testMezclaDeQuitarAnadirYReordenar(): void {
        $plan = QueueSortPlan::reconcile(array(1, 2, 3), array(3, 4, 1));

        $this->assertSame(array(1 => 2, 3 => 0), $plan['keep']);
        $this->assertSame(array(2), $plan['remove']);
        $this->assertSame(array(array(4, 1)), $plan['add']);
    }

    /** El POST trae cadenas: '3' tiene que reconocerse como la ordenación 3. */
    public function testLosValoresDelPostSonCadenasYSeReconocenIgual(): void {
        $plan = QueueSortPlan::reconcile(array(3, 1), array('3', '2'));

        $this->assertSame(array(3 => 0), $plan['keep']);
        $this->assertSame(array(1), $plan['remove']);
        $this->assertSame(array(array('2', 1)), $plan['add']);
    }

    /**
     * La misma entrada, el algoritmo de antes y el de ahora, lado a lado.
     *
     * Es la comprobación que de verdad importa: `reconcile()` tiene que dar
     * exactamente lo que hacía el código original de `CustomQueue::update()`.
     */
    public function testElPlanCoincideConElAlgoritmoDeAntes(): void {
        $casos = array(
            array(array(1, 2), array(1, 2)),
            array(array(1, 2), array(2, 1)),
            array(array(1, 2, 3), array(3, 1)),
            array(array(1), array(7, 1, 8)),
            array(array(), array()),
            array(array(9), array('9', '10')),
            // Repetidos: el POST puede traer el mismo id dos veces y el
            // original lo añadía como duplicado. Aquí se conserva tal cual.
            array(array(1), array(1, 1)),
        );

        foreach ($casos as $caso) {
            list($current, $posted) = $caso;
            $this->assertSame($this->original($current, $posted),
                QueueSortPlan::reconcile($current, $posted),
                sprintf('entrada: %s / %s',
                    json_encode($current), json_encode($posted)));
        }
    }

    /** Copia literal del bucle que había en `CustomQueue::update()`. */
    private function original(array $current, array $posted) {
        $new = $order = $posted;
        $result = array('keep' => array(), 'remove' => array(), 'add' => array());
        foreach ($current as $key) {
            $idx = array_search($key, $posted);
            if (false === $idx) {
                $result['remove'][] = $key;
            }
            else {
                $result['keep'][$key] = $idx;
                unset($new[$idx]);
            }
        }
        foreach ($new as $id)
            $result['add'][] = array($id, array_search($id, $order));

        return $result;
    }
}
