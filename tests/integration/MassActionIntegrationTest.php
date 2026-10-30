<?php
/**
 * tests/integration/MassActionIntegrationTest.php
 *
 * Las acciones en bloque del panel, **en proceso**: se instancia el mismo
 * controlador que atiende el AJAX (`TicketsAjaxAPI::massProcess()`) con un
 * `$thisstaff` de verdad y el `$_POST` que manda el diálogo. No se levanta
 * nginx ni se mantiene una sesión, que es justo lo que hace frágil una prueba
 * de este tipo.
 *
 * Lo que interesa aquí es lo que no se puede saber sin base de datos: que el
 * ticket cambie de departamento, que quede asignado, que se borre, y que un
 * agente sin el permiso no toque nada. Las decisiones puras —qué se puede
 * combinar, qué textos salen, qué agentes se ofrecen— ya están fijadas en
 * `tests/MassActionTest.php` (68 casos, sin base).
 *
 * Cada caso corre en su propio proceso (`tests/support/create-ticket.php`,
 * modos `masiva-*`) porque la acción completa termina el script con
 * `Http::response()`, igual que en la petición real.
 */
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/IntegrationTestCase.php';

class MassActionIntegrationTest extends IntegrationTestCase {

    protected function beforeEach(): void {
    }

    /** El ayudante, como en la prueba del alta. */
    private function ayudante() {
        $ruta = rtrim(ROOT_DIR, '/') . '/tests/support/create-ticket.php';
        $this->assertTrue(is_file($ruta), 'no se encuentra el ayudante: ' . $ruta);

        return $ruta;
    }

    /**
     * Ejecuta una acción masiva y devuelve el informe del ayudante.
     *
     * El JSON va en la última línea: antes puede salir el HTML del diálogo
     * que `massProcess()` incluye.
     */
    private function accion($modo) {
        $comando = sprintf('%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY ?: 'php'),
            escapeshellarg($this->ayudante()),
            escapeshellarg($modo));

        $lineas = array();
        $codigo = 0;
        exec($comando, $lineas, $codigo);

        $salida = trim(implode("\n", $lineas));
        $this->assertSame(0, $codigo,
            sprintf("el ayudante «%s» falló (código %d):\n%s", $modo, $codigo,
                $salida));

        foreach (array_reverse($lineas) as $linea) {
            $linea = trim($linea);
            if ($linea !== '' && $linea[0] === '{') {
                $datos = json_decode($linea, true);
                if (is_array($datos))
                    return $datos;
            }
        }

        $this->assertTrue(false, sprintf(
            "respuesta inesperada del ayudante «%s»:\n%s", $modo, $salida));

        return array();
    }

    /* ------------------------------------------------------------------ */
    /* Traslado en bloque                                                  */
    /* ------------------------------------------------------------------ */

    public function testElTrasladoEnBloqueMueveLaSeleccion(): void {
        $r = $this->accion('masiva-transfer');

        $this->assertTrue(!empty($r['ok']), 'la acción no llegó a ejecutarse');

        // Los dos seleccionados cambian de departamento…
        foreach (array(0, 1) as $i) {
            $this->assertSame((int) $r['destino'],
                (int) $r['tickets'][$i]['dept_id'],
                sprintf('el ticket %d no se trasladó al departamento elegido',
                    $r['ids'][$i]));
        }

        // …y el tercero, que no estaba marcado, se queda donde estaba.
        $this->assertSame((int) $r['origen'],
            (int) $r['tickets'][2]['dept_id'],
            'se trasladó un ticket que no estaba en la selección');
    }

    /* ------------------------------------------------------------------ */
    /* Reclamo en bloque                                                   */
    /* ------------------------------------------------------------------ */

    public function testElReclamoEnBloqueAsignaAlAgente(): void {
        $r = $this->accion('masiva-claim');

        $this->assertTrue(!empty($r['ok']), 'la acción no llegó a ejecutarse');

        $this->assertSame((int) $r['agente'],
            (int) $r['tickets'][0]['staff_id'],
            'el ticket no quedó asignado al agente que reclamó');
        $this->assertSame(0, (int) $r['tickets'][1]['staff_id'],
            'se asignó un ticket que no estaba en la selección');
    }

    /* ------------------------------------------------------------------ */
    /* Borrado en bloque                                                   */
    /* ------------------------------------------------------------------ */

    public function testElBorradoEnBloqueQuitaElTicket(): void {
        $r = $this->accion('masiva-delete');

        $this->assertTrue(!empty($r['ok']), 'la acción no llegó a ejecutarse');

        $this->assertNull($r['tickets'][0],
            'el ticket seleccionado sigue en la tabla después del borrado');
        $this->assertTrue(!empty($r['tickets'][1]),
            'el borrado en bloque se llevó tickets que no estaban marcados');
    }

    /* ------------------------------------------------------------------ */
    /* Sin permiso                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * El permiso se comprueba ticket a ticket (`checkStaffPerm()`), antes de
     * tocar nada: un agente sin `ticket.transfer` no mueve **ninguno**, ni
     * siquiera los de su propio departamento.
     */
    public function testSinPermisoDeTrasladoNoSeMueveNada(): void {
        $r = $this->accion('masiva-sin-permiso');

        $this->assertTrue(!empty($r['ok']), 'la acción no llegó a ejecutarse');

        $this->assertSame((int) $r['origen'],
            (int) $r['tickets'][0]['dept_id'],
            'un agente sin permiso trasladó el ticket');
        $this->assertSame((int) $r['origen'],
            (int) $r['tickets'][1]['dept_id'],
            'un agente sin permiso trasladó un ticket de la selección');
    }
}
