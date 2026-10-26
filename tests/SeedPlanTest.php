<?php
/**
 * tests/SeedPlanTest.php
 *
 * Pruebas de los planes del sembrador (`--count`, `--with-tasks`):
 * `include/cli/seed.plan.php` y los `*Plan()` de `SeedManager`.
 *
 * Lo que se crea de verdad necesita MariaDB, pero **qué** se va a crear no:
 * `SeedPlan` recibe los datos curados y devuelve filas. Aquí se comprueba
 * que el número pedido se respeta, que lo generado es determinista (para no
 * duplicar al repetir), que los asuntos y títulos no chocan con los curados
 * y que una lista vacía de temas, departamentos o agentes no rompe nada.
 */

if (!class_exists('Module')) {
    class Module {
        static $reg = array();
        static function register($action, $class) {
            self::$reg[$action] = $class;
        }
    }
}

require_once INCLUDE_DIR . 'cli/modules/seed.php';

class SeedPlanTest extends UnitTestCase {

    /* ------------------------------------------------------------------ */
    /* fill(): el número pedido                                            */
    /* ------------------------------------------------------------------ */

    function testSinNumeroSeQuedaConElPlanCurado() {
        $rows = SeedPlan::fill(array('a', 'b'), null, function($i) {
            return 'x' . $i;
        });

        $this->assertSame(array('a', 'b'), $rows);
    }

    function testUnNumeroMenorRecortaElPlan() {
        $rows = SeedPlan::fill(array('a', 'b', 'c'), 2, function($i) {
            return 'x' . $i;
        });

        $this->assertSame(array('a', 'b'), $rows);
    }

    function testUnNumeroMayorCompletaElPlan() {
        $rows = SeedPlan::fill(array('a', 'b'), 4, function($i) {
            return 'x' . $i;
        });

        $this->assertSame(array('a', 'b', 'x2', 'x3'), $rows);
    }

    /** El índice que recibe el generador es el de la fila final. */
    function testElGeneradorRecibeElIndiceFinal() {
        $seen = array();
        SeedPlan::fill(array('a', 'b', 'c'), 5, function($i) use (&$seen) {
            $seen[] = $i;
            return $i;
        });

        $this->assertSame(array(3, 4), $seen);
    }

    function testCeroNoCreaNada() {
        $rows = SeedPlan::fill(array('a', 'b'), 0, function($i) {
            return $i;
        });

        $this->assertSame(array(), $rows);
    }

    /** Un número negativo no puede vaciar el plan curado de rebote. */
    function testUnNumeroNegativoTampocoCreaNada() {
        $rows = SeedPlan::fill(array('a'), -5, function($i) {
            return $i;
        });

        $this->assertSame(array(), $rows);
    }

    /* ------------------------------------------------------------------ */
    /* Usuarios                                                            */
    /* ------------------------------------------------------------------ */

    function testCadaUsuarioGeneradoTieneCorreoPropio() {
        $emails = array();
        for ($i = 12; $i < 212; $i++) {
            list($name, $local, $org) = SeedPlan::person($i);

            $this->assertTrue(strlen($name) > 3, "nombre del usuario $i");
            $this->assertFalse(isset($emails[$local]), "correo repetido: $local");
            $emails[$local] = true;
        }

        $this->assertCount(200, $emails);
    }

    /** El mismo número da siempre la misma persona: así no se duplica. */
    function testElUsuarioGeneradoEsDeterminista() {
        $this->assertSame(SeedPlan::person(20), SeedPlan::person(20));
        $this->assertSame('cliente20', SeedPlan::person(20)[1]);
    }

    /** Uno de cada cuatro va sin organización, como en la lista curada. */
    function testUnoDeCadaCuatroUsuariosVaSinOrganizacion() {
        $sin = 0;
        for ($i = 12; $i < 32; $i++)
            if (SeedPlan::person($i)[2] === null)
                $sin++;

        $this->assertSame(5, $sin);
    }

    /* ------------------------------------------------------------------ */
    /* Tickets                                                             */
    /* ------------------------------------------------------------------ */

    private function temas() {
        $names = array();
        foreach (SeedManager::topics() as $topic)
            if ($topic[2])
                $names[] = $topic[0];

        return $names;
    }

    private function agentes() {
        $names = array();
        foreach (SeedManager::agents() as $agent)
            $names[] = $agent[0];

        return $names;
    }

    function testCadaTicketGeneradoTieneSeisCampos() {
        $row = SeedPlan::ticket(60, $this->temas(), $this->agentes());

        $this->assertCount(6, $row);
        $this->assertTrue(strlen($row[0]) > 5, 'asunto');
        $this->assertTrue(in_array($row[1], $this->temas()), 'tema conocido');
        $this->assertTrue($row[2] >= 0 && $row[2] < 60, 'antigüedad razonable');
        $this->assertTrue(is_bool($row[3]), 'cerrado es booleano');
        $this->assertTrue($row[4] >= 1 && $row[4] <= 8, 'mensajes del hilo');
        $this->assertTrue($row[5] === null
            || in_array($row[5], $this->agentes()), 'agente conocido');
    }

    /** Los asuntos generados no repiten ni chocan con los curados. */
    function testLosAsuntosGeneradosSonUnicos() {
        $seen = array();
        foreach (SeedManager::tickets() as $row)
            $seen[$row[0]] = true;

        $temas = $this->temas();
        $agentes = $this->agentes();
        for ($i = 59; $i < 259; $i++) {
            $subject = SeedPlan::ticket($i, $temas, $agentes)[0];

            $this->assertFalse(isset($seen[$subject]), "asunto repetido: $subject");
            // El sembrador evita duplicados con un «contiene» sobre el
            // asunto: un generado no puede estar dentro de uno curado.
            foreach (array_keys($seen) as $other)
                $this->assertFalse(strpos($other, $subject) !== false,
                    '«' . $subject . '» está dentro de «' . $other . '»');
            $seen[$subject] = true;
        }

        $this->assertCount(259, $seen);
    }

    /** Sin temas ni agentes (instalación recién hecha) no revienta. */
    function testUnTicketGeneradoSinTemasNiAgentesSigueSiendoValido() {
        $row = SeedPlan::ticket(60, array(), array());

        $this->assertCount(6, $row);
        $this->assertTrue(strlen($row[1]) > 3, 'tema de respaldo');
        $this->assertNull($row[5]);
    }

    /* ------------------------------------------------------------------ */
    /* Tareas                                                              */
    /* ------------------------------------------------------------------ */

    function testCadaTareaGeneradaVaSuelta() {
        $row = SeedPlan::task(16, array('Soporte', 'Ventas'), array('agente1'));

        $this->assertCount(8, $row);
        $this->assertTrue(strlen($row[0]) > 5, 'título');
        $this->assertTrue(in_array($row[1], array('Soporte', 'Ventas')), 'departamento');
        $this->assertTrue(is_bool($row[3]), 'hecha es booleano');
        // Lo último es el ticket de origen: ninguna generada lo lleva
        $this->assertNull($row[7]);
    }

    function testLosTitulosDeTareaGeneradosSonUnicos() {
        $seen = array();
        foreach (SeedManager::tasks() as $row)
            $seen[$row[0]] = true;

        for ($i = 16; $i < 216; $i++) {
            $title = SeedPlan::task($i, array('Soporte'), array())[0];
            $this->assertFalse(isset($seen[$title]), "título repetido: $title");
            $seen[$title] = true;
        }

        $this->assertCount(216, $seen);
    }

    /** Una tarea sin departamentos disponibles usa el de siempre. */
    function testUnaTareaSinDepartamentosUsaSoporte() {
        $row = SeedPlan::task(30, array(), array());

        $this->assertSame('Soporte', $row[1]);
        $this->assertNull($row[5]);
    }

    /* ------------------------------------------------------------------ */
    /* Tareas de seguimiento (`--with-tasks`)                              */
    /* ------------------------------------------------------------------ */

    function testCadaTicketNuevoLlevaSuTareaDeSeguimiento() {
        $tickets = array(
            array('subject' => 'No puedo entrar', 'age' => 10, 'agent' => 'agente1'),
            array('subject' => 'Cobro duplicado', 'age' => 0, 'agent' => null),
        );

        $rows = SeedPlan::followUps($tickets, array('Soporte', 'Ventas'));

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertCount(8, $row);
            $this->assertTrue(strpos($row[0], 'Seguimiento: ') === 0,
                'el título dice que es un seguimiento');
        }

        // El asunto del ticket es lo que las enlaza
        $this->assertSame('No puedo entrar', $rows[0][7]);
        $this->assertSame('Cobro duplicado', $rows[1][7]);
        $this->assertSame('agente1', $rows[0][5]);
        $this->assertNull($rows[1][5]);
        // La edad no puede ser negativa aunque el ticket sea de hoy
        $this->assertSame(0, $rows[1][2]);
    }

    function testSinTicketsNoHayTareasDeSeguimiento() {
        $this->assertSame(array(), SeedPlan::followUps(array(), array('Soporte')));
    }

    /* ------------------------------------------------------------------ */
    /* Los planes del módulo                                               */
    /* ------------------------------------------------------------------ */

    function testElPlanDeUsuariosEmpiezaPorLosCurados() {
        $plan = SeedManager::peoplePlan(200);
        $curated = SeedManager::people();

        $this->assertCount(200, $plan);
        $this->assertSame($curated[0], $plan[0]);
        $this->assertSame(SeedPlan::person(12), $plan[12]);
    }

    function testElPlanDeTicketsYElDeTareasRespetanElNumero() {
        $this->assertCount(100, SeedManager::ticketPlan(100,
            $this->temas(), $this->agentes()));
        $this->assertCount(100, SeedManager::taskPlan(100,
            array('Soporte'), array('agente1')));

        // Y sin número, los curados de siempre
        $this->assertSame(SeedManager::tickets(),
            SeedManager::ticketPlan(null));
        $this->assertSame(SeedManager::tasks(),
            SeedManager::taskPlan(null));
    }

    /* ------------------------------------------------------------------ */
    /* Las opciones del comando                                            */
    /* ------------------------------------------------------------------ */

    private function seed() {
        $seed = new SeedManager();
        // Sin `stdout` real: el módulo escribe por ahí y en la prueba no hay
        // consola. `quiet` lo apaga en vez de simularla.
        $seed->quiet = true;

        return $seed;
    }

    function testElRecuentoSeLeeComoNumero() {
        $seed = $this->seed();

        $this->assertNull($seed->parseCount(''));
        $this->assertSame(200, $seed->parseCount('200'));
        $this->assertSame(0, $seed->parseCount('0'));
    }

    /** Un `--count=2oo` (letra o) se rechaza antes de tocar la base. */
    function testUnRecuentoQueNoEsNumeroSeExplica() {
        $seed = $this->seed();
        $error = $seed->parseCount('2oo');

        $this->assertTrue(is_string($error), 'debería devolver el motivo');
        $this->assertTrue(strpos($error, '2oo') !== false,
            'el motivo cita lo que se escribió');
    }

    /** El recuento sólo vale para los pasos que se repiten. */
    function testElRecuentoSoloSeAplicaAUsuariosTicketsYTareas() {
        $seed = $this->seed();
        $seed->parseCount('50');

        $this->assertSame(50, $seed->limitFor('users'));
        $this->assertSame(50, $seed->limitFor('tickets'));
        $this->assertSame(50, $seed->limitFor('tasks'));
        $this->assertNull($seed->limitFor('topics'));
    }

    function testSinRecuentoNingunPasoTieneLimite() {
        $seed = $this->seed();
        $seed->parseCount('');

        $this->assertNull($seed->limitFor('users'));
        $this->assertNull($seed->limitFor('tickets'));
    }
}
