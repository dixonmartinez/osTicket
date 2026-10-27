<?php
/**
 * include/class.queue.sort.php
 *
 * El reparto de las ordenaciones de una cola guardada, sacado de
 * `CustomQueue::update()` (frente 2 de docs/CODE_QUALITY.md).
 *
 * El formulario de una cola manda la lista de ordenaciones en el orden en que
 * deben aplicarse. Compararla con la que hay guardada son tres preguntas:
 *
 *   - las que ya estaban y siguen en la lista  → qué posición tienen ahora
 *   - las que ya no están                      → se quitan
 *   - las que llegan nuevas                    → se añaden en su posición
 *
 * Antes eso eran tres recorridos con `array_search()` y `unset()` mezclados
 * con la escritura en la base de datos. Aquí es una función sin estado: entra
 * la lista guardada, sale el plan. `CustomQueue::setupSorts()` aplica el plan.
 *
 * Ojo con las posiciones: no son «1, 2, 3» sino el índice dentro de la lista
 * que mandó el formulario (0, 1, 2...). Es lo que hacía el código original y
 * lo que espera la columna `sort` de `QUEUE_SORTING_TABLE`.
 */

class QueueSortPlan {

    /**
     * Qué hacer con las ordenaciones de la cola.
     *
     * @param array $current  ids de las ordenaciones guardadas, en su orden
     * @param array $posted   lista del formulario, con los ids en su posición
     * @return array  `keep` (id => posición), `remove` (ids) y `add`
     *                (lista de `array($id, $posición)` en el orden del POST)
     */
    static function reconcile(array $current, array $posted) {
        $plan = array('keep' => array(), 'remove' => array(), 'add' => array());
        $new = $order = $posted;

        foreach ($current as $sort_id) {
            $position = array_search($sort_id, $posted);
            if ($position === false) {
                // Ya no está en el formulario: fuera
                $plan['remove'][] = $sort_id;
                continue;
            }
            $plan['keep'][$sort_id] = $position;
            unset($new[$position]);
        }

        // Lo que sobra del POST es nuevo; se añade en la posición en la que
        // vino, no al final.
        foreach ($new as $sort_id)
            $plan['add'][] = array($sort_id, array_search($sort_id, $order));

        return $plan;
    }
}
