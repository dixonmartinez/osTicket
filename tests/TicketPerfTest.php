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

    /**
     * Las acciones de una entrada sólo existen si se ha cargado
     * `class.thread_actions.php`: medirlas antes daba 0.0 ms y escondía el
     * trabajo de verdad. El paso tiene que cargarlo, igual que `render()`.
     */
    function testElPerfilCargaLasAccionesAntesDeMedirlas() {
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/perf.php');

        $this->assertTrue(
            strpos($source, "include_once INCLUDE_DIR . 'class.thread_actions.php'") !== false,
            'El perfil mide las acciones sin cargar el archivo que las registra');
        $this->assertTrue(
            strpos($source, 'ThreadEntry::$action_registry') !== false);
        $this->assertTrue(strpos(file_get_contents(INCLUDE_DIR . 'class.thread.php'),
            'static $action_registry') !== false);
    }

    /** El desglose tiene que separar el proemio y una segunda pasada. */
    function testElPerfilMideElProemioYLaSegundaPasada() {
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/perf.php');

        foreach (array('Hilo: proemio del template',
                       'Hilo: render() otra vez (caché tibia)',
                       'Hilo: carga de acciones (thread_actions.php)') as $label)
            $this->assertTrue(strpos($source, $label) !== false,
                'Falta el paso: ' . $label);
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

    /**
     * Los 11 «SQL» de `Ticket::lookup()` en la consola eran casi todos
     * `INFORMATION_SCHEMA` de tablas nuevas. Contarlas con las reales hace
     * creer que un ticket necesita once consultas.
     */
    function testElPasoCuentaAparteLaIntrospeccionDeEsquema() {
        $perf = $this->probe();
        $perf->step('Ticket::lookup()', function() use ($perf) {
            $perf->record("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS"
                . " WHERE TABLE_NAME = 'a'", 0.010);
            $perf->record("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS"
                . " WHERE TABLE_NAME = 'b'", 0.010);
            $perf->record('SELECT * FROM ost_ticket WHERE ticket_id = 1', 0.002);
            return 1;
        });

        $this->assertSame(1, $perf->steps[0]['queries']);
        $this->assertSame(2, $perf->steps[0]['schema']);
        // Y el tiempo de SQL del paso es el de las consultas reales
        $this->assertEquals(0.002, round($perf->steps[0]['sql_ms'], 3));
    }

    /** La tabla del informe tiene que enseñar el desglose. */
    function testLaTablaDelInformeSeparaSqlYEsquema() {
        $perf = $this->probe();
        $perf->step('Ticket::lookup()', function() use ($perf) {
            $perf->record("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS"
                . " WHERE TABLE_NAME = 'a'", 0.010);
            return 1;
        });

        ob_start();
        $perf->report(7);
        $text = ob_get_clean();

        $this->assertTrue(strpos($text, 'Esq') !== false);
    }

    /**
     * Una consulta que sale al recorrer un conjunto perezoso se ejecuta dentro
     * del cierre del paso, y decir `perf.php:157` no ayuda: lo honesto es
     * decir en qué paso salió.
     */
    function testLaConsultaPerezosaSeAtribuyeAlPaso() {
        $perf = $this->probe();
        $perf->step('Hilo: getEntries()', function() use ($perf) {
            $perf->record('SELECT * FROM ost_thread_entry WHERE thread_id = 1',
                0.002, ROOT_DIR . '/include/cli/modules/perf.php:157');
            return 4;
        });

        $origins = $perf->topOrigins();
        $this->assertSame('paso «Hilo: getEntries()»', $origins[0]['origin']);
    }

    /**
     * La flecha de «Cuerpo → HTML» ocupa tres bytes en UTF-8 y descuadraba
     * la tabla entera. El ancho se mide en caracteres.
     */
    function testLaTablaCuadraConCaracteresDeVariosBytes() {
        $perf = $this->probe();

        ob_start();
        $perf->table(array('Paso', 'ms'),
            array(array('Hilo: cuerpo → HTML', '1.0'), array('Corto', '2.0')));
        $text = ob_get_clean();

        // La segunda columna tiene que empezar en el mismo carácter en todas
        // las líneas, aunque una celda lleve una flecha de tres bytes.
        $position = function($line, $needle) {
            preg_match('/^(.*?)' . preg_quote($needle, '/') . '/u', $line, $m);
            preg_match_all('/./u', $m[1], $chars);
            return count($chars[0]);
        };
        // `rtrim()` y no `trim()`: el encabezado empieza por los dos espacios
        // de margen, y recortarlos descuadraría justo lo que se compara.
        $lines = explode("\n", rtrim($text));
        $column = $position($lines[2], '1.0');
        $this->assertSame($column, $position($lines[0], 'ms'));
        $this->assertSame($column, $position($lines[3], '2.0'));
    }

    /**
     * La pregunta del usuario era «¿esto es la base de datos o es PHP?».
     * El informe tiene que contestarla sin echar cuentas a mano.
     */
    function testElInformeReparteElTiempoEntreSqlYPhp() {
        $perf = $this->probe();
        $perf->step('Ticket::lookup()', function() use ($perf) {
            $perf->record('SELECT * FROM ost_ticket WHERE ticket_id = 1', 0.002);
            return 1;
        });

        ob_start();
        $perf->report(7);
        $text = ob_get_clean();

        $this->assertTrue(strpos($text, 'SQLms') !== false,
            'La tabla no dice cuánto de cada paso es SQL');
        $this->assertTrue(strpos($text, 'en base de datos y') !== false,
            'El informe no reparte el tiempo entre base de datos y PHP');
        // El paso dura más que su SQL: el resto es PHP
        $this->assertTrue(strpos($text, 'en PHP') !== false);
    }

    /** El JSON lleva el mismo desglose, que es lo que se compara entre tandas. */
    function testElJsonLlevaElTiempoDeSqlPorPaso() {
        $perf = $this->probe(array('json' => true));
        $perf->json = true;
        $perf->step('Ticket::lookup()', function() use ($perf) {
            $perf->record('SELECT * FROM ost_ticket WHERE ticket_id = 1', 0.002);
            return 1;
        });

        ob_start();
        $perf->report(7);
        $json = json_decode(ob_get_clean(), true);

        $this->assertTrue(isset($json['steps'][0]['sql_ms']));
        $this->assertEquals(2.0, $json['steps'][0]['sql_ms']);
    }

    /**
     * Sin opcache, cada `include` de la plantilla de una entrada se compila
     * otra vez: el `render()` de la consola no es el de la web. El informe
     * tiene que avisarlo, como avisa de APCu.
     */
    function testElPerfilAvisaDeQueSinOpcacheElRenderEstaInflado() {
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/perf.php');

        $this->assertTrue(strpos($source, 'opcache_get_status') !== false,
            'El perfil no comprueba si el opcode se cachea');
        $this->assertTrue(strpos($source, 'opcache.enable_cli=1') !== false,
            'El aviso no dice cómo repetir el perfil con opcache');
    }

    /** `make ticket-perf` perfila con las cachés de la web. */
    function testElMakefilePerfilaConOpcacheYApcu() {
        $makefile = file_get_contents(dirname(INCLUDE_DIR) . '/Makefile');

        $this->assertTrue(
            strpos($makefile, '-d opcache.enable_cli=1 -d apc.enable_cli=1') !== false,
            'make ticket-perf no activa opcache/APCu');
        $this->assertTrue(strpos($makefile, 'ticket-perf-raw') !== false,
            'Falta la variante sin cachés para ver los artefactos');
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

    /**
     * El detalle por paso cuenta **lo de ese paso**, no el total.
     *
     * Salía del perfil real: dos pasos que usan la misma consulta mostraban
     * los dos el total de la ejecución entera (`4 ×` en cada uno), así que sus
     * tiempos parecían sumar más de lo que duraba el paso. Con el conteo por
     * paso, la suma de las filas de un paso es su tiempo de SQL.
     */
    function testElDetalleDelPasoCuentaSoloLoDeEsePaso() {
        $perf = $this->probe(array('step' => 'render'));
        $perf->step('Hilo: render() completo', function() use ($perf) {
            $perf->record('SELECT * FROM ost_file WHERE id = 1', 0.002);
            return 1;
        });
        $perf->step('Hilo: render() otra vez', function() use ($perf) {
            $perf->record('SELECT * FROM ost_file WHERE id = 1', 0.002);
            $perf->record('SELECT * FROM ost_file WHERE id = 1', 0.002);
            return 1;
        });

        $property = new ReflectionProperty('PerfCheck', 'queries');
        $property->setAccessible(true);
        $queries = array_values($property->getValue($perf));

        // La consulta se ejecutó tres veces en total, repartidas 1 y 2.
        $this->assertSame(3, $queries[0]['n']);
        $this->assertSame(1, $queries[0]['steps'][0]['n']);
        $this->assertSame(2, $queries[0]['steps'][1]['n']);
        $this->assertSame(0.002, round($queries[0]['steps'][0]['ms'], 6));

        ob_start();
        $perf->report(7);
        $text = ob_get_clean();

        // Cada bloque dice su cuenta: el primero 1×, el segundo 2×.
        $blocks = explode('Consultas de «', $text);
        $this->assertTrue(strpos($blocks[1], '1 ×') !== false,
            'El primer paso debe decir 1×, no el total');
        $this->assertTrue(strpos($blocks[2], '2 ×') !== false);
    }

    /**
     * La configuración de un espacio de nombres se pide una vez, no dos.
     *
     * `ConfigItem::items()` ejecutaba `count($items)` sólo para detectar una
     * instalación a medio actualizar (`InconsistentModelException` viene del
     * `db_prepare`), y `Config::load()` recorría la misma tabla a
     * continuación: dos consultas por espacio de nombres, que el perfil real
     * señalaba en `class.config.php:168` y `:49`. Recorrer la consulta hace
     * las dos cosas de una vez. Se comprueba sobre el código porque sin base
     * de datos no hay consultas que contar.
     */
    function testLaConfiguracionNoSePideDosVeces() {
        $source = file_get_contents(INCLUDE_DIR . 'class.config.php');

        $this->assertFalse(strpos($source, "        try {\n            count(\$items);")
            !== false, 'Volvió el count() que duplica la consulta de config');
        $this->assertTrue(strpos($source, 'foreach ($items as $item);') !== false,
            'Falta el recorrido que deja la consulta en la caché del iterador');
        // Y la detección de la actualización pendiente sigue en su sitio.
        $this->assertTrue(strpos($source, 'catch (InconsistentModelException $ex)')
            !== false);
    }

    /**
     * Los avisos del hilo se piden una vez, no dos.
     *
     * `count($events)` sobre la consulta compilaba
     * `SELECT COUNT(*) FROM (SELECT …)` y acto seguido se recorría la misma
     * tabla para traerlos: dos consultas a `ost_thread_event` por carga, que
     * el perfil real del ticket 428 señalaba en `thread-entries.tmpl.php:12`
     * y `:13`. Ahora se traen y se cuentan en memoria.
     */
    function testLosAvisosDelHiloSePidenUnaSolaVez() {
        $template = file_get_contents(
            INCLUDE_DIR . 'staff/templates/thread-entries.tmpl.php');

        $materialize = strpos($template, 'iterator_to_array');
        $count = strpos($template, '$eventCount = count($events);');

        $this->assertTrue($materialize !== false,
            'La plantilla no materializa los avisos antes de contarlos');
        $this->assertTrue($count !== false);
        $this->assertTrue($materialize < $count,
            'Se cuenta la consulta antes de traerla: vuelve el COUNT(*) de más');
        $this->assertFalse(strpos($template, 'new IteratorIterator') !== false,
            'Quedó el envoltorio anterior sobre la consulta sin materializar');
    }

    /**
     * El «proemio» del perfil hace lo que hace la plantilla, no lo que hacía.
     *
     * Medía el precache de adjuntos que se retiró y un `count()` de los avisos
     * que ya no existe, así que sumaba milisegundos de trabajo que la página
     * no hace. Un informe que miente sobre lo que mide es peor que no tenerlo.
     */
    function testElProemioDelPerfilCopiaLaPlantilla() {
        $perf = file_get_contents(INCLUDE_DIR . 'cli/modules/perf.php');

        $start = strpos($perf, '$this->step(\'Hilo: proemio del template\'');
        $end = strpos($perf, 'return $n;', $start);
        $this->assertTrue($start !== false && $end !== false);
        $block = substr($perf, $start, $end - $start);

        $this->assertFalse(
            strpos($block, 'AttachmentFile::objects()') !== false,
            'El proemio sigue midiendo el precache que ya no existe');
        // La consulta se ejecutaba al contar; ahora se materializa primero y
        // se cuenta en memoria (que es lo que hace la plantilla).
        $this->assertFalse(
            strpos($block, "\$thread->getEvents()->order_by('id');") !== false,
            'El proemio sigue contando los avisos con una consulta');
        $this->assertTrue(strpos($block, 'iterator_to_array') !== false,
            'El proemio no materializa los avisos como la plantilla');

        // Y la plantilla sigue haciendo lo que el proemio copia
        $template = file_get_contents(
            INCLUDE_DIR . 'staff/templates/thread-entries.tmpl.php');
        $this->assertTrue(strpos($template, 'iterator_to_array') !== false);
        $this->assertFalse(strpos($template, 'AttachmentFile::objects()') !== false);
    }

    /**
     * `render()` no repite la consulta de adjuntos que ya hace la plantilla.
     *
     * El «precache» llenaba `AttachmentFile::$keyCache`, y la plantilla lo
     * llena igual con su propio `select_related('thread_entry', 'file')`
     * —el ORM llama a `__onload()` también en los modelos que llegan por
     * JOIN—. Eran tres consultas por carga para nada; el perfil real las
     * señalaba en `class.thread.php:424`.
     */
    function testRenderNoRepiteLaConsultaDeAdjuntosDeLaPlantilla() {
        $source = file_get_contents(INCLUDE_DIR . 'class.thread.php');

        $this->assertFalse(
            strpos($source, "AttachmentFile::objects()->filter(array(\n"
                . "            'attachments__thread_entry__thread__id'") !== false,
            'Volvió el precache de adjuntos, que duplica el de la plantilla');

        // La plantilla sí sigue precargando, y con el file unido.
        $template = file_get_contents(
            INCLUDE_DIR . 'staff/templates/thread-entries.tmpl.php');
        $this->assertStringContainsString("select_related('thread_entry', 'file')",
            $template);
    }

    /**
     * Los avisos del hilo traen su autor en la misma consulta.
     *
     * El perfil real del ticket mostró dos consultas por carga atribuidas a
     * `class.thread.event.php:112` —la línea que hace `$this->user->getName()`
     * al pintar cada aviso—: una por evento con autor, y otra vez en la
     * segunda pasada del render.
     */
    function testLosAvisosDelHiloTraenSuAutorEnLaMismaConsulta() {
        $source = file_get_contents(INCLUDE_DIR . 'class.thread.event.php');
        $this->assertTrue(
            strpos($source, "'select_related' => array('user', 'agent')") !== false,
            'ThreadEvent volvería a pedir el autor aviso por aviso');

        // Y no se pide desde `render()`: `$this->events` es una lista y
        // `select_related()` se aplicaría a un clon de la consulta.
        $thread = file_get_contents(INCLUDE_DIR . 'class.thread.php');
        $this->assertFalse(
            strpos($thread, "\$events->select_related(") !== false,
            'select_related() sobre una InstrumentedList no tiene efecto');
    }

    /**
     * El informe dice qué opciones aplicó.
     *
     * Es la mitad visible del arreglo anterior: si una opción se ignora, se
     * ve en la cabecera en vez de descubrirse comparando filas a ojo.
     */
    function testElInformeDiceQueOpcionesAplico() {
        $perf = $this->probe(array('ticket' => '428', 'top' => '20',
            'step' => 'render'));

        ob_start();
        $perf->report(428);
        $text = ob_get_clean();

        $this->assertTrue(strpos($text, 'Opciones: --ticket=428') !== false);
        $this->assertTrue(strpos($text, '--top=20') !== false);
        $this->assertTrue(strpos($text, '--step=«render»') !== false);
    }

    /** Sin opciones no se inventa la línea. */
    function testSinOpcionesNoHayLineaDeOpciones() {
        $perf = $this->probe(array('top' => '12'));

        ob_start();
        $perf->report(7);
        $text = ob_get_clean();

        $this->assertFalse(strpos($text, 'Opciones:') !== false);
    }

    /**
     * El módulo no puede saltarse el análisis de sus propias opciones.
     *
     * `parseOptions()` sabía si ya había analizado mirando si `$_options` era
     * un arreglo, y el módulo `perf` lo declaraba como `array()`. Resultado:
     * `--ticket`, `--top`, `--step`, `--schema` y `--json` **nunca** se leían
     * —siempre el último ticket y las 12 filas— y el informe no lo decía.
     * Esta prueba vigila las dos mitades del arreglo, que viven en archivos
     * que no se pueden cargar aquí (`class.cli.php` define la clase real).
     */
    function testElModuloNoSeSaltaElAnalisisDeSusOpciones() {
        $cli = file_get_contents(INCLUDE_DIR . 'class.cli.php');
        $this->assertTrue(strpos($cli, 'if ($this->_parsed)') !== false,
            'parseOptions() debe usar la bandera _parsed');
        $this->assertFalse(strpos($cli, 'is_array($this->_options))') !== false,
            'el centinela ya no puede ser is_array($_options)');

        $perf = file_get_contents(INCLUDE_DIR . 'cli/modules/perf.php');
        $this->assertFalse(strpos($perf, 'var $_options = array()') !== false,
            'declarar $_options como array() desactiva parseOptions()');
        $this->assertTrue(strpos($perf, 'var $_options;') !== false);
    }

    /** Las banderas sin argumento (`--json`, `--schema`) no dejan avisos. */
    function testLasBanderasSinArgumentoNoTocanLaCadenaVacia() {
        $cli = file_get_contents(INCLUDE_DIR . 'class.cli.php');
        $this->assertTrue(strpos($cli, '$value !== null && $value !== \'\'') !== false,
            'handleValue() debe proteger el valor nulo antes de mirar $value[0]');
    }

}
