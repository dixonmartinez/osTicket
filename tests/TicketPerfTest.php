<?php
/**
 * tests/TicketPerfTest.php
 *
 * Pruebas del perfilado de la página del ticket (`php manage.php perf`).
 *
 * No hay base de datos aquí, así que lo que se prueba es la parte que
 * convierte medidas en un informe útil:
 *
 *   - agrupar consultas: la misma `SELECT` con otro id tiene que sumar en la
 *     misma fila, o el informe se llena de ruido y no se ve la que se repite;
 *   - ordenar por tiempo **total**, no por tiempo de una sola ejecución: una
 *     consulta de 1 ms repetida 40 veces pesa más que una de 8 ms;
 *   - que la plantilla del hilo no vuelva a buscar cada entrada (una consulta
 *     por entrada en cada carga) y que los colaboradores se cuenten una vez.
 */

if (!class_exists('Module')) {
    class Module {
        static $reg = array();
        static function register($action, $class) {
            self::$reg[$action] = $class;
        }
        function getOption($name, $default = false) {
            return isset($this->_options[$name]) ? $this->_options[$name] : $default;
        }
    }
}

if (!defined('INCLUDE_DIR'))
    define('INCLUDE_DIR', dirname(__DIR__) . '/include/');
if (!defined('ROOT_DIR'))
    define('ROOT_DIR', dirname(__DIR__));

require_once INCLUDE_DIR . 'cli/modules/perf.php';
require_once INCLUDE_DIR . 'class.db.origin.php';

/**
 * El `Module` falso de las pruebas es el mínimo común a todos los ficheros de
 * prueba (no tiene `getOption()` ni `err()`), así que aquí se estuban.
 */
class PerfProbe extends PerfCheck {
    var $errors = array();

    function getOption($name, $default = false) {
        return isset($this->_options[$name]) ? $this->_options[$name] : $default;
    }

    function err($text) {
        $this->errors[] = $text;
    }
}

class TicketPerfTest extends UnitTestCase {

    private function probe(array $options = array()) {
        $perf = new PerfProbe();
        $perf->_options = $options;
        return $perf;
    }

    /* ------------------------------------------------------------------ */
    /* Agrupar consultas                                                   */
    /* ------------------------------------------------------------------ */

    function testLaMismaConsultaConOtroIdEsLaMismaFila() {
        $a = PerfCheck::pattern('SELECT * FROM ost_ticket WHERE ticket_id = 405');
        $b = PerfCheck::pattern('SELECT * FROM ost_ticket WHERE ticket_id = 406');

        $this->assertSame($a, $b);
    }

    /** Las listas `IN (1,2,3)` también son el mismo patrón. */
    function testLasListasDeIdsNoSeparanElPatron() {
        $a = PerfCheck::pattern('SELECT * FROM ost_ticket WHERE dept_id IN (1,2,3)');
        $b = PerfCheck::pattern('SELECT * FROM ost_ticket WHERE dept_id IN (8,53,54)');

        $this->assertSame($a, $b);
    }

    function testLosLiteralesNoSeparanElPatron() {
        $a = PerfCheck::pattern("SELECT * FROM ost_thread WHERE object_type = 'T'");
        $b = PerfCheck::pattern("SELECT * FROM ost_thread WHERE object_type = 'A'");

        $this->assertSame($a, $b);
    }

    /** Pero dos consultas distintas siguen siendo distintas. */
    function testDosConsultasDistintasNoSeMezclan() {
        $a = PerfCheck::pattern('SELECT * FROM ost_ticket WHERE ticket_id = 405');
        $b = PerfCheck::pattern('SELECT * FROM ost_thread WHERE id = 405');

        $this->assertTrue($a !== $b);
    }

    /** El patrón no conserva espacios ni saltos de línea: se compara fácil. */
    function testElPatronSeAplana() {
        $sql = "SELECT  COUNT(*)\n   FROM ost_ticket\n  WHERE ticket_id = 12";
        $this->assertSame('SELECT COUNT(*) FROM ost_ticket WHERE ticket_id = ?',
            PerfCheck::pattern($sql));
    }

    /* ------------------------------------------------------------------ */
    /* Acumular y ordenar                                                  */
    /* ------------------------------------------------------------------ */

    function testAcumulaEjecucionesYTiempoPorPatron() {
        $perf = $this->probe();

        $perf->record('SELECT * FROM ost_ticket WHERE ticket_id = 1', 0.010);
        $perf->record('SELECT * FROM ost_ticket WHERE ticket_id = 2', 0.020);
        $perf->record('SELECT * FROM ost_thread WHERE id = 1', 0.005);

        $totals = $perf->totals();
        $this->assertSame(3, $totals['n']);
        $this->assertEquals(0.035, round($totals['ms'], 3));
    }

    /**
     * Ordenar por tiempo total: tres consultas de 1 ms pesan más que una de
     * 2 ms, y es la repetida la que interesa arreglar.
     */
    function testOrdenaPorTiempoTotalYNoPorUnaEjecucion() {
        $perf = $this->probe(array('top' => 5));

        $perf->record('SELECT * FROM ost_ticket WHERE ticket_id = 1', 0.002);
        for ($i = 0; $i < 4; $i++)
            $perf->record('SELECT * FROM ost_thread_entry WHERE id = ' . $i, 0.001);

        $top = $perf->topQueries();

        $this->assertSame(2, count($top));
        $this->assertTrue(strpos($top[0]['sql'], 'ost_thread_entry') !== false);
        $this->assertSame(4, $top[0]['n']);
        $this->assertEquals(0.004, round($top[0]['ms'], 3));
    }

    function testElTopRespetaElLimite() {
        $perf = $this->probe(array('top' => 2));
        for ($i = 0; $i < 6; $i++)
            $perf->record("SELECT * FROM ost_tabla_$i WHERE id = $i", 0.001);

        $this->assertSame(2, count($perf->topQueries()));
    }

    /** Un paso que falla no puede tumbar el informe. */
    function testUnPasoConErrorSeRegistraYNoCortaElPerfil() {
        $perf = $this->probe();

        $perf->step('paso que falla', function() {
            throw new Exception('sin conexión');
        });
        $perf->step('paso que va', function() {
            return 3;
        });

        $steps = $this->stepsOf($perf);
        $this->assertSame(2, count($steps));
        $this->assertSame('sin conexión', $steps[0]['error']);
        $this->assertSame(3, $steps[1]['result']);
    }

    private function stepsOf(PerfCheck $perf) {
        $property = new ReflectionProperty('PerfCheck', 'steps');
        $property->setAccessible(true);
        return $property->getValue($perf);
    }

    /* ------------------------------------------------------------------ */
    /* Las dos optimizaciones de esta tanda                                */
    /* ------------------------------------------------------------------ */

    /**
     * `sortEntries()` ya devuelve los modelos hidratados, con la anotación
     * `has_attachments`. Volver a buscarlos dentro del `foreach` era una
     * consulta por entrada en **cada** carga de la página.
     */
    function testLaPlantillaDelHiloNoVuelveABuscarCadaEntrada() {
        $template = file_get_contents(
            INCLUDE_DIR . 'staff/templates/thread-entries.tmpl.php');

        $this->assertTrue(strpos($template, 'ThreadEntry::lookup($entry->id)') === false,
            'La plantilla vuelve a consultar cada entrada del hilo');
        $this->assertTrue(strpos($template, 'foreach ($buckets as $entry)') !== false,
            'La plantilla ya no recorre las entradas como se esperaba');
    }

    /** La plantilla sigue usando la anotación de adjuntos del hilo. */
    function testLaPlantillaAprovechaLosAdjuntosPrecargados() {
        $template = file_get_contents(
            INCLUDE_DIR . 'staff/templates/thread-entry.tmpl.php');

        $this->assertTrue(strpos($template, '$thread_attachments') !== false,
            'La plantilla dejó de usar los adjuntos precargados');
    }

    /**
     * La regresión de esta tanda: el módulo llamaba a `$ticket->getTasks()`,
     * que no existe (en el ticket las tareas son una colección: `tasks`, y lo
     * único que la página hace es contarlas con `getNumTasks()`), y al fallar
     * el paso llamaba a `$this->err()`, que el `Module` base tampoco tiene.
     * Las dos cosas juntas mataban el comando con «Call to undefined method».
     */
    /**
     * Y el fallo en cadena que provocó: el paso de tareas lanzó, el `catch`
     * llamó a `$this->err()` y el comando murió con «Call to undefined
     * method». Un paso roto tiene que dejar el informe, no llevarse el
     * proceso.
     */
    function testUnPasoRotoNoMataElComando() {
        $perf = new PerfCheck();
        $perf->_options = array();

        $perf->step('tareas', function() {
            throw new Error('Call to undefined method Ticket::getTasks()');
        });

        $steps = $this->stepsOf($perf);
        $this->assertSame(1, count($steps));
        $this->assertTrue(strpos($steps[0]['error'], 'getTasks') !== false);
    }

    function testLosMetodosQueUsaElPerfilExisten() {
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/perf.php');
        $orm = file_get_contents(INCLUDE_DIR . 'class.orm.php');

        foreach (array('ticket' => 'class.ticket.php',
                       'thread' => 'class.thread.php') as $var => $file) {
            $class = file_get_contents(INCLUDE_DIR . $file);
            preg_match_all('/\$' . $var . '->(\w+)\(/', $source, $m);
            $used = array_unique($m[1]);
            $this->assertGreaterThan(3, count($used),
                "No se encontraron llamadas a \$$var en el perfilador");

            foreach ($used as $method)
                $this->assertTrue(
                    strpos($class, "function {$method}(") !== false
                        || strpos($orm, "function {$method}(") !== false,
                    "El perfilador llama a \$$var->{$method}(), que no existe");
        }
    }

    /** `Ticket::getTasks()` no existe: las tareas no se cargan así. */
    function testElPerfilNoInventaMetodosDeTareas() {
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/perf.php');
        $ticket = file_get_contents(INCLUDE_DIR . 'class.ticket.php');

        $this->assertTrue(strpos($ticket, 'function getTasks(') === false);
        $this->assertTrue(strpos($source, 'getTasks(') === false,
            'El perfilador vuelve a llamar a un método que no existe');
    }

    /** Un paso que falla se informa, y para eso `err()` tiene que existir. */
    function testElPerfilTieneSusPropiosAvisos() {
        $this->assertTrue(method_exists('PerfCheck', 'err'),
            'PerfCheck no define err(): un paso fallido mata el comando');
        $this->assertTrue(method_exists('PerfCheck', 'out'));
    }

    /** Las consultas quedan atribuidas al paso que las lanzó. */
    function testLasConsultasSeAtribuyenASuPaso() {
        $perf = $this->probe(array('step' => 'hilo'));
        $perf->startProfile();

        $perf->step('Ticket::lookup()', function() use ($perf) {
            $perf->record('SELECT * FROM ost_ticket WHERE ticket_id = 1', 0.001);
            return 1;
        });
        $perf->step('Hilo: getEntries()', function() use ($perf) {
            $perf->record('SELECT * FROM ost_thread_entry WHERE thread_id = 1', 0.002);
            $perf->record('SELECT * FROM ost_thread_entry WHERE thread_id = 2', 0.002);
            return 2;
        });

        $property = new ReflectionProperty('PerfCheck', 'queries');
        $property->setAccessible(true);
        $queries = $property->getValue($perf);

        foreach ($queries as $q) {
            if (strpos($q['sql'], 'ost_thread_entry') !== false) {
                $this->assertSame(array(1), array_keys($q['steps']),
                    'La consulta del hilo no está atribuida al segundo paso');
                $this->assertSame(2, $q['n']);
            }
            else
                $this->assertSame(array(0), array_keys($q['steps']));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Introspección del esquema, aparte de las consultas reales           */
    /* ------------------------------------------------------------------ */

    function testReconoceLaIntrospeccionDelEsquema() {
        $this->assertTrue(PerfCheck::isSchemaQuery(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS"
            . " WHERE TABLE_NAME = 'ost_ticket'"));
        $this->assertTrue(PerfCheck::isSchemaQuery('SHOW TABLES LIKE \'ost_ticket\''));
        $this->assertTrue(PerfCheck::isSchemaQuery('SHOW COLUMNS FROM ost_ticket'));
    }

    function testUnaConsultaRealNoEsIntrospeccion() {
        foreach (array(
            'SELECT * FROM ost_ticket WHERE ticket_id = ?',
            'SELECT * FROM ost_thread_entry WHERE thread_id = ?',
            'UPDATE ost_ticket SET status_id = ? WHERE ticket_id = ?',
        ) as $sql)
            $this->assertFalse(PerfCheck::isSchemaQuery($sql),
                "Se clasifica como esquema algo que no lo es: $sql");
    }

    /**
     * El caso real del informe: 22 de las 46 consultas eran
     * `INFORMATION_SCHEMA`, y con ellas el «total» asustaba. Tienen que
     * contarse aparte.
     */
    function testLasConsultasDeEsquemaNoEntranEnElTotalReal() {
        $perf = $this->probe();

        $perf->record("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS"
            . " WHERE TABLE_NAME = 'a'", 0.010);
        $perf->record("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS"
            . " WHERE TABLE_NAME = 'b'", 0.010);
        $perf->record('SELECT * FROM ost_ticket WHERE ticket_id = 1', 0.002);

        $reales = $perf->totals(false);
        $esquema = $perf->schemaTotals();

        $this->assertSame(1, $reales['n']);
        $this->assertSame(2, $esquema['n']);
        $this->assertEquals(0.002, round($reales['ms'], 3));
        $this->assertEquals(0.020, round($esquema['ms'], 3));
    }

    /** Y no aparecen en el ranking salvo que se pidan con `--schema`. */
    function testElRankingOcultaElEsquemaSalvoQueSePida() {
        $perf = $this->probe(array('top' => 10));
        $perf->record("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS"
            . " WHERE TABLE_NAME = 'a'", 0.010);
        $perf->record('SELECT * FROM ost_ticket WHERE ticket_id = 1', 0.001);

        $top = $perf->topQueries();
        $this->assertSame(1, count($top));
        $this->assertTrue(strpos($top[0]['sql'], 'ost_ticket') !== false);

        $perf->_options['schema'] = true;
        $this->assertSame(2, count($perf->topQueries()));
    }

    /* ------------------------------------------------------------------ */
    /* El origen de cada consulta                                          */
    /* ------------------------------------------------------------------ */

    /**
     * El primer marco que no es del acceso a datos ni del ORM es la línea de
     * la aplicación que pidió los datos. Es lo que convierte «13 consultas»
     * en un número de línea.
     */
    function testElOrigenEsLaPrimeraLineaDeLaAplicacion() {
        $frames = array(
            array('file' => '/app/include/class.db.origin.php', 'line' => 47),
            array('file' => '/app/include/mysqli.php', 'line' => 258),
            array('file' => '/app/include/class.orm.php', 'line' => 1200),
            array('file' => '/app/include/class.orm.select.php', 'line' => 88),
            array('file' => '/app/include/class.thread.php', 'line' => 1012),
            array('file' => '/app/scp/tickets.php', 'line' => 30),
        );

        $this->assertSame('/app/include/class.thread.php:1012',
            DbSource::fromFrames($frames));
    }

    /** Sin marcos de la aplicación no hay origen inventado. */
    function testSinMarcosDeLaAplicacionNoHayOrigen() {
        $this->assertSame(null, DbSource::fromFrames(array(
            array('file' => '/app/include/mysqli.php', 'line' => 10),
            array('function' => 'sin-archivo'),
        )));
    }

    /** Una consulta se atribuye a las líneas que la dispararon. */
    function testLasConsultasSeAgrupanPorSuOrigen() {
        $perf = $this->probe();

        $perf->record('SELECT * FROM ost_thread_entry WHERE id = 1', 0.002,
            '/app/include/class.thread.php:1012');
        $perf->record('SELECT * FROM ost_thread_entry WHERE id = 2', 0.002,
            '/app/include/class.thread.php:1012');
        $perf->record('SELECT * FROM ost_user WHERE id = 3', 0.001,
            '/app/include/class.thread.php:900');

        $origins = $perf->topOrigins();
        $this->assertSame(2, count($origins));
        // El que dispara más consultas y más tiempo suma va primero
        $this->assertSame('/app/include/class.thread.php:1012',
            $origins[0]['origin']);
        $this->assertSame(2, $origins[0]['n']);
        $this->assertEquals(0.004, round($origins[0]['ms'], 4));
        $this->assertSame(1, $origins[0]['distinct']);
    }

    /** Una línea que pide tres cosas distintas se ve distinta de una repetida. */
    function testElOrigenCuentaCuantasConsultasDistintasDispara() {
        $perf = $this->probe();

        $perf->record('SELECT * FROM ost_ticket WHERE ticket_id = 1', 0.001,
            '/app/include/class.ticket.php:99');
        $perf->record('SELECT * FROM ost_user WHERE id = 1', 0.001,
            '/app/include/class.ticket.php:99');
        $perf->record('SELECT * FROM ost_ticket WHERE ticket_id = 2', 0.001,
            '/app/include/class.ticket.php:99');

        $origins = $perf->topOrigins();
        $this->assertSame(3, $origins[0]['n']);
        $this->assertSame(2, $origins[0]['distinct']);
    }

    /** En la consola la ruta del proyecto sobra; en el informe se recorta. */
    function testElOrigenSeMuestraSinLaRutaDelProyecto() {
        $this->assertSame('include/class.thread.php:1012',
            PerfCheck::shortOrigin('/var/www/html/include/class.thread.php:1012',
                '/var/www/html/'));
        $this->assertSame('include/class.thread.php:1012',
            PerfCheck::shortOrigin('/var/www/html/include/class.thread.php:1012',
                '/var/www/html'));
        // Un origen de fuera del proyecto se deja tal cual
        $this->assertSame('/otro/sitio/x.php:1',
            PerfCheck::shortOrigin('/otro/sitio/x.php:1', '/var/www/html'));
    }

    /** La introspección del esquema no aparece como origen salvo con --schema. */
    function testElOrigenOcultaElEsquemaSalvoQueSePida() {
        $perf = $this->probe();
        $perf->record("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS"
            . " WHERE TABLE_NAME = 'a'", 0.010, '/app/include/class.orm.php:3247');
        $perf->record('SELECT * FROM ost_ticket WHERE ticket_id = 1', 0.001,
            '/app/include/class.ticket.php:99');

        $this->assertSame(1, count($perf->topOrigins()));

        $perf->_options['schema'] = true;
        $this->assertSame(2, count($perf->topOrigins()));
    }

    /** Y el informe lo escribe: sin esta línea hay que leer el código. */
    function testElInformeDiceDeDondeSalenLasConsultas() {
        $perf = $this->probe();
        $perf->record('SELECT * FROM ost_thread_entry WHERE id = 1', 0.002,
            ROOT_DIR . '/include/class.thread.php:1012');

        ob_start();
        $perf->report(7);
        $text = ob_get_clean();

        $this->assertTrue(strpos($text, 'De dónde salen las consultas') !== false);
        $this->assertTrue(strpos($text, 'include/class.thread.php:1012') !== false);
        // Y sin repetir la ruta del proyecto
        $this->assertFalse(strpos($text, ROOT_DIR . '/include/class.thread.php') !== false);
    }

    /*
     * Un `--step` que no encuentra nada no puede dejar el informe mudo: el
     * usuario lanzó `--step=lookup` y no vio ni el detalle ni el motivo.
     */
    function testUnFiltroDePasoQueNoCoincideListaLosPasos() {
        $perf = $this->probe(array('step' => 'inexistente'));
        $perf->step('Ticket::lookup()', function() { return 1; });
        $perf->step('Hilo: render() completo', function() { return 1; });

        ob_start();
        $perf->report(7);
        $text = ob_get_clean();

        $this->assertTrue(strpos($text, 'Ningún paso coincide con «inexistente»') !== false);
        $this->assertTrue(strpos($text, '· Ticket::lookup()') !== false);
        $this->assertTrue(strpos($text, '· Hilo: render() completo') !== false);
    }

    /** Y si coincide, el detalle del paso sale con sus consultas. */
    function testElDetalleDelPasoListaSusConsultas() {
        $perf = $this->probe(array('step' => 'render'));
        $perf->step('Ticket::lookup()', function() use ($perf) {
            $perf->record('SELECT * FROM ost_ticket WHERE ticket_id = 1', 0.001,
                ROOT_DIR . '/include/class.ticket.php:1200');
            return 1;
        });
        $perf->step('Hilo: render() completo', function() use ($perf) {
            $perf->record('SELECT * FROM ost_file WHERE id = 1', 0.002,
                ROOT_DIR . '/include/class.file.php:213');
            return 1;
        });

        ob_start();
        $perf->report(7);
        $text = ob_get_clean();

        $this->assertTrue(strpos($text, 'Consultas de «Hilo: render() completo»') !== false);
        $this->assertTrue(strpos($text, 'FROM ost_file') !== false);
        $this->assertTrue(strpos($text, 'desde include/class.file.php:213') !== false);
        // El paso que no coincide no se cuela
        $this->assertFalse(strpos($text, 'Consultas de «Ticket::lookup()»') !== false);
    }

    /** El perfil avisa de que sin APCu las medidas son pesimistas. */
    function testAvisaCuandoApcuNoEstaActivo() {
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/perf.php');

        $this->assertTrue(strpos($source, "function_exists('apcu_fetch')") !== false,
            'El perfil no comprueba APCu');
        $this->assertTrue(strpos($source, 'la página real hace') !== false,
            'El perfil no avisa de que en CLI el resultado es pesimista');
    }

    /**
     * Las entradas del hilo traen su `merge_info` en el mismo SELECT: era una
     * consulta por entrada en cada carga (`SELECT … FROM
     * ost_thread_entry__merge WHERE thread_entry_id = ?`).
     */
    function testLasEntradasTraenSuInformacionDeMezclaEnLaMismaConsulta() {
        $source = file_get_contents(INCLUDE_DIR . 'class.thread.php');

        $this->assertTrue(strpos($source, "select_related('merge_info')") !== false,
            'getEntries() volvería a pedir merge_info entrada por entrada');
    }

    function testLosColaboradoresSeCuentanUnaSolaVez() {
        $source = file_get_contents(INCLUDE_DIR . 'class.thread.php');

        $this->assertTrue(
            strpos($source, "if (!isset(\$this->ht['num_collaborators']))") !== false,
            'getNumCollaborators() volvería a lanzar un COUNT por llamada');
    }
}
