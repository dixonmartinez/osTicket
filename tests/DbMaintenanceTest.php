<?php
/**
 * tests/DbMaintenanceTest.php
 *
 * Pruebas de `include/cli/modules/db.php` (el comando
 * `php manage.php db --views|--rebuild-views`).
 *
 * El comando existe por un fallo que se ve en la interfaz y no en los datos:
 * la cola «Closed» decía tener 23 tickets y no listaba ninguno. La causa es
 * que las columnas de las colas hacen un JOIN interno contra las tablas
 * materializadas (`ost_ticket__cdata`), y un ticket sin fila ahí desaparece de
 * todos los listados aunque el recuento —que no usa columnas— lo siga
 * contando.
 *
 * Lo que se prueba aquí es la parte que no necesita base de datos: que el
 * comando sepa **qué tablas** son, **qué las alimenta** y **cuántas filas
 * faltan**. Es lo que decide si avisa o si dice «todo bien».
 */

if (!class_exists('Module')) {
    class Module {
        static $reg = array();
        static function register($action, $class) {
            self::$reg[$action] = $class;
        }
    }
}

/* Constantes que en una instalación define bootstrap.php. */
if (!defined('TABLE_PREFIX'))
    define('TABLE_PREFIX', 'ost_');
foreach (array('FORM_ENTRY_TABLE' => 'form_entry',
               'TICKET_CDATA_TABLE' => 'ticket__cdata',
               'TASK_CDATA_TABLE' => 'task__cdata',
               'USER_CDATA_TABLE' => 'user__cdata',
               'ORGANIZATION_CDATA_TABLE' => 'organization__cdata') as $name => $table)
    if (!defined($name))
        define($name, TABLE_PREFIX . $table);

require_once INCLUDE_DIR . 'cli/modules/db.php';

/** Consola falsa: acumula lo que el módulo imprime. */
class DbMaintenanceFakeConsole {
    var $lines = array();
    function write($text) { $this->lines[] = rtrim($text, "\n"); }
    function text() { return implode("\n", $this->lines); }
}

/**
 * Módulo con la base de datos simulada: responde a `scalar()` con los números
 * que le demos y dice que todas las tablas existen.
 */
class DbMaintenanceFake extends DbMaintenance {
    var $numbers = array();
    var $tables = array();
    var $stdout;

    function __construct($numbers, $tables = null) {
        $this->numbers = $numbers;
        $this->tables = $tables === null ? array_keys(DbMaintenance::views()) : $tables;
    }

    function tableExists($table) { return true; }

    function scalar($sql) {
        foreach ($this->numbers as $needle => $value)
            if (strpos($sql, $needle) !== false)
                return $value;
        return 0;
    }
}

class DbMaintenanceTest extends UnitTestCase {

    /** Estado como el que devuelve `state()`: filas y filas esperadas. */
    private function state(array $rows, array $expected) {
        $state = array();
        foreach (DbMaintenance::views() as $name => $view) {
            $state[$name] = array(
                'table' => $view['table'],
                'exists' => true,
                'rows' => $rows[$name],
                'expected' => $expected[$name],
            );
        }
        return $state;
    }

    function testViewsCoverTheFourForms() {
        $views = DbMaintenance::views();

        $this->assertEquals(array('ticket', 'task', 'user', 'organization'),
            array_keys($views));

        foreach ($views as $name => $view)
            foreach (array('table', 'source', 'type', 'class') as $key)
                $this->assertTrue(isset($view[$key]),
                    'La vista ' . $name . ' no declara «' . $key . '»');
    }

    function testViewsPointAtTheCataloguedTables() {
        $views = DbMaintenance::views();

        $this->assertEquals(TICKET_CDATA_TABLE, $views['ticket']['table']);
        $this->assertEquals(TASK_CDATA_TABLE, $views['task']['table']);
        $this->assertEquals(USER_CDATA_TABLE, $views['user']['table']);
        $this->assertEquals(ORGANIZATION_CDATA_TABLE,
            $views['organization']['table']);
    }

    /**
     * El tipo de objeto de `form_entry` es lo que separa los tickets (T) de
     * las tareas (A), usuarios (U) y organizaciones (O). Un tipo equivocado
     * haría que la tabla se reconstruyera con las filas de otro formulario.
     */
    function testEachViewUsesItsOwnObjectType() {
        $views = DbMaintenance::views();

        $this->assertEquals('T', $views['ticket']['type']);
        $this->assertEquals('A', $views['task']['type']);
        $this->assertEquals('U', $views['user']['type']);
        $this->assertEquals('O', $views['organization']['type']);
        $this->assertEquals(FORM_ENTRY_TABLE, $views['ticket']['source']);
    }

    /** Todas las clases declaradas tienen que existir en el proyecto. */
    function testDeclaredFormClassesExist() {
        foreach (DbMaintenance::views() as $name => $view) {
            $file = INCLUDE_DIR . 'class.'
                . strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $view['class']))
                . '.php';
            // TicketForm vive en class.dynamic_forms.php, no en class.ticket_form.php
            $found = file_exists($file);
            if (!$found) {
                foreach (glob(INCLUDE_DIR . 'class.*.php') as $candidate)
                    if (strpos(file_get_contents($candidate),
                            'class ' . $view['class'] . ' ') !== false) {
                        $found = true;
                        break;
                    }
            }
            $this->assertTrue($found,
                'No se encuentra la clase ' . $view['class'] . ' de la vista ' . $name);
        }
    }

    function testDeficitIsZeroWhenTablesMatchTheirSource() {
        $state = $this->state(
            array('ticket' => 60, 'task' => 16, 'user' => 60, 'organization' => 2),
            array('ticket' => 60, 'task' => 16, 'user' => 60, 'organization' => 2));

        $deficit = DbMaintenance::deficit($state);

        $this->assertEquals(0, $deficit['rows']);
        $this->assertEquals(array(), $deficit['tables']);
    }

    /**
     * El caso real: 23 tickets cerrados con `closed` pero sin fila en
     * `ticket__cdata`. El déficit tiene que verlo **por ticket que falta**,
     * no sólo decir «esta tabla está mal».
     */
    function testDeficitCountsMissingRowsAndNamesTheTable() {
        $state = $this->state(
            array('ticket' => 37, 'task' => 16, 'user' => 60, 'organization' => 2),
            array('ticket' => 60, 'task' => 16, 'user' => 60, 'organization' => 2));

        $deficit = DbMaintenance::deficit($state);

        $this->assertEquals(23, $deficit['rows']);
        $this->assertEquals(array(TICKET_CDATA_TABLE), $deficit['tables']);
    }

    /** Una tabla que sobra (más filas que su origen) no es un déficit. */
    function testDeficitIgnoresTablesWithExtraRows() {
        $state = $this->state(
            array('ticket' => 62, 'task' => 16, 'user' => 60, 'organization' => 2),
            array('ticket' => 60, 'task' => 16, 'user' => 60, 'organization' => 2));

        $this->assertEquals(0, DbMaintenance::deficit($state)['rows']);
    }

    /** Si no se pudo contar (`null`), no se inventa un déficit. */
    function testDeficitIgnoresUnknownNumbers() {
        $state = $this->state(
            array('ticket' => null, 'task' => null, 'user' => null,
                'organization' => null),
            array('ticket' => 60, 'task' => 16, 'user' => 60, 'organization' => 2));

        $deficit = DbMaintenance::deficit($state);

        $this->assertEquals(0, $deficit['rows']);
        $this->assertEquals(array(), $deficit['tables']);
    }

    function testDeficitReportsSeveralTablesAtOnce() {
        $state = $this->state(
            array('ticket' => 55, 'task' => 10, 'user' => 60, 'organization' => 2),
            array('ticket' => 60, 'task' => 16, 'user' => 60, 'organization' => 2));

        $deficit = DbMaintenance::deficit($state);

        $this->assertEquals(11, $deficit['rows']);
        $this->assertEquals(array(TICKET_CDATA_TABLE, TASK_CDATA_TABLE),
            $deficit['tables']);
    }

    /** El informe tiene que nombrar la tabla y el número que falta. */
    function testReportPointsAtTheTableAndOffersTheFix() {
        $module = new DbMaintenanceFake(array());
        $module->stdout = new DbMaintenanceFakeConsole();

        $module->report($this->state(
            array('ticket' => 37, 'task' => 16, 'user' => 60, 'organization' => 2),
            array('ticket' => 60, 'task' => 16, 'user' => 60, 'organization' => 2)));

        $text = $module->stdout->text();
        $this->assertTrue(strpos($text, 'ticket__cdata') !== false,
            "El informe no nombra la tabla: $text");
        $this->assertTrue(strpos($text, 'Faltan 23 filas') !== false,
            "El informe no dice cuántas faltan: $text");
        $this->assertTrue(strpos($text, 'db --rebuild-views') !== false,
            "El informe no dice cómo arreglarlo: $text");
        $this->assertTrue(strpos($text, 'se listan en las colas') !== false,
            "El informe no explica el síntoma: $text");
    }

    function testReportIsSilentAboutTheFixWhenNothingIsMissing() {
        $module = new DbMaintenanceFake(array());
        $module->stdout = new DbMaintenanceFakeConsole();

        $module->report($this->state(
            array('ticket' => 60, 'task' => 16, 'user' => 60, 'organization' => 2),
            array('ticket' => 60, 'task' => 16, 'user' => 60, 'organization' => 2)));

        $text = $module->stdout->text();
        $this->assertTrue(strpos($text, 'ticket__cdata') !== false,
            "El informe no lista la tabla: $text");
        $this->assertTrue(strpos($text, 'Faltan') === false,
            "El informe avisa de un déficit que no existe: $text");
    }

    /**
     * `state()` pregunta a la base de datos por las filas de cada vista y por
     * las filas de `form_entry` con el tipo correspondiente. Si la consulta
     * del tipo se equivoca, el «debería tener» sale mal y el comando avisaría
     * de un fallo inexistente.
     */
    function testStateQueriesTheSourceByObjectType() {
        $seen = array();
        $module = new DbMaintenanceFake(array(
            'ticket__cdata' => 60, 'task__cdata' => 16,
            'user__cdata' => 60, 'organization__cdata' => 2,
            "object_type = 'T'" => 60, "object_type = 'A'" => 16,
            "object_type = 'U'" => 60, "object_type = 'O'" => 2,
        ));
        $module->stdout = new DbMaintenanceFakeConsole();

        $state = $module->state();

        $this->assertEquals(60, $state['ticket']['rows']);
        $this->assertEquals(16, $state['task']['expected']);
        $this->assertEquals(2, $state['organization']['expected']);
    }
}
