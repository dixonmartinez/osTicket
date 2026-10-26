<?php
/**
 * tests/QueueCheckTest.php
 *
 * Pruebas del diagnóstico de colas (`include/cli/modules/queue.php`, el
 * comando `php manage.php queue`).
 *
 * Lo que necesita base de datos —ejecutar la consulta, contar los tickets,
 * leer el criterio de una cola de verdad— no se prueba aquí. Lo que sí se
 * prueba es la parte que decide **qué se informa**: cómo se sustituyen los
 * parámetros del SQL para poder ejecutarlo a mano, cómo se detecta una
 * consulta rota y cómo se describe un criterio. Es justo la parte que, si se
 * equivoca, hace que el diagnóstico diga «todo bien» cuando no lo está.
 *
 * El módulo sólo usa `Module::register()` a nivel de archivo; la clase base de
 * verdad trae la consola. Aquí basta con una mínima, igual que en
 * tests/SeedDataTest.php.
 */

if (!class_exists('Module')) {
    class Module {
        static $reg = array();
        static function register($action, $class) {
            self::$reg[$action] = $class;
        }
    }
}

/* `sqlShape()` filtra las tablas por el prefijo de la instalación. */
if (!defined('TABLE_PREFIX'))
    define('TABLE_PREFIX', 'ost_');

require_once INCLUDE_DIR . 'cli/modules/queue.php';

class QueueCheckTest extends UnitTestCase {

    /** Escapador de mentira: no hay base de datos en las pruebas. */
    private function escaper(): callable {
        return function($value) {
            return "'" . addslashes($value) . "'";
        };
    }

    function testBindParamsLeavesSqlWithoutParamsAlone() {
        $sql = 'SELECT 1 FROM ost_ticket';
        $this->assertEquals($sql, QueueCheck::bindParams($sql, array()));
    }

    function testBindParamsSubstitutesInOrderOfMarkers() {
        $sql = 'WHERE A1.closed BETWEEN :1 AND :2 AND A1.status_id = :3';
        $out = QueueCheck::bindParams($sql, array(
            '2026-10-01 00:00:00', '2026-10-31 23:59:59', 7,
        ), $this->escaper());

        $this->assertEquals(
            "WHERE A1.closed BETWEEN '2026-10-01 00:00:00' AND '2026-10-31 23:59:59'"
            . ' AND A1.status_id = 7',
            $out);
    }

    function testBindParamsWritesNullWithoutQuotes() {
        $out = QueueCheck::bindParams('A1.ticket_pid = :1', array(null),
            $this->escaper());

        // `IS NULL` y «el valor es la cadena null» son cosas distintas: si se
        // citara, la consulta dejaría de devolver los tickets raíz.
        $this->assertEquals('A1.ticket_pid = NULL', $out);
    }

    function testBindParamsQuotesStringsAndEscapesThem() {
        $out = QueueCheck::bindParams('A1.subject = :1', array("O'Brien"),
            $this->escaper());

        $this->assertEquals("A1.subject = 'O\\'Brien'", $out);
    }

    function testBindParamsFormatsDates() {
        $out = QueueCheck::bindParams('A1.closed = :1',
            array(new DateTime('2026-10-26 13:45:00')), $this->escaper());

        $this->assertEquals("A1.closed = '2026-10-26 13:45:00'", $out);
    }

    function testBindParamsKeepsUnknownMarkers() {
        // Un `:` que no es un parámetro (por ejemplo dentro de un literal) no
        // debe desaparecer: se dejaría el SQL a medias y la base diría tonterías.
        $out = QueueCheck::bindParams("A1.subject LIKE '%:9%' AND A1.id = :1",
            array(3), $this->escaper());

        $this->assertEquals("A1.subject LIKE '%:9%' AND A1.id = 3", $out);
    }

    function testInspectSqlFindsEmptySelect() {
        $issues = QueueCheck::inspectSql(
            'SELECT FROM `ost_ticket` A1 WHERE A1.status_id = :1');

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('no selecciona', $issues[0]);
    }

    function testInspectSqlAcceptsANormalQuery() {
        $sql = 'SELECT A1.`ticket_id` AS `ticket_id` FROM `ost_ticket` A1'
            . ' LEFT JOIN `ost_ticket_status` A2 ON (A1.status_id = A2.id)'
            . ' WHERE A2.`state` = :1 GROUP BY A1.`ticket_id`'
            . ' ORDER BY A1.`closed` DESC LIMIT 25';

        $this->assertEquals(array(), QueueCheck::inspectSql($sql));
    }

    function testInspectSqlFindsEmptyGroupBy() {
        $issues = QueueCheck::inspectSql('SELECT A1.id FROM ost_ticket A1 GROUP BY ORDER BY A1.id');

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('GROUP BY', $issues[0]);
    }

    function testSqlShapeSummarisesTheQuery() {
        $sql = 'SELECT A1.`ticket_id` FROM `ost_ticket` A1'
            . ' LEFT JOIN `ost_thread` B1 ON (A1.ticket_id = B1.object_id)'
            . ' LEFT JOIN `ost_user` A3 ON (A1.user_id = A3.id)'
            . ' JOIN `ost_ticket_status` A2 ON (A1.status_id = A2.id)'
            . ' WHERE A2.state = :1 GROUP BY A1.ticket_id ORDER BY A1.closed DESC LIMIT 25';

        $shape = QueueCheck::sqlShape($sql);

        $this->assertEquals(1, $shape['select']);
        $this->assertEquals(3, $shape['joins']);
        $this->assertEquals(2, $shape['left_joins']);
        $this->assertTrue($shape['where']);
        $this->assertTrue($shape['group_by']);
        $this->assertTrue($shape['order_by']);
        $this->assertEquals(25, $shape['limit']);
        $this->assertTrue(in_array('ost_ticket', $shape['tables']));
        $this->assertTrue(in_array('ost_ticket_status', $shape['tables']));
        // El alias de la tabla raíz no se confunde con una tabla
        $this->assertFalse(in_array('A1', $shape['tables']));
    }

    function testSqlShapeWithoutLimit() {
        $shape = QueueCheck::sqlShape('SELECT A1.id FROM `ost_ticket` A1');

        $this->assertEquals(null, $shape['limit']);
        $this->assertFalse($shape['where']);
        $this->assertFalse($shape['group_by']);
    }

    function testDescribeCriterionForStatusState() {
        $text = QueueCheck::describeCriterion(
            array('status__state', 'includes', array('closed' => 'Closed')));

        $this->assertEquals('status__state includes [closed: Closed]', $text);
    }

    function testDescribeCriterionForAPeriod() {
        $text = QueueCheck::describeCriterion(array('closed', 'period', 'td'));

        $this->assertEquals('closed period td', $text);
    }

    function testDescribeCriterionWithoutValue() {
        $this->assertEquals('closed isnull —',
            QueueCheck::describeCriterion(array('closed', 'isnull', null)));
    }

    function testPeriodsAreTheSixOfTheInstallerQueue() {
        $periods = QueueCheck::periods();

        $this->assertEquals(array('td', 'yd', 'tw', 'tm', 'tq', 'ty'),
            array_keys($periods));
    }

    function testOnelineCollapsesWhitespaceAndTruncates() {
        $check = new QueueCheck();
        $sql = "SELECT A1.id\n    FROM   ost_ticket A1\n WHERE 1 = 1";

        $this->assertEquals('SELECT A1.id FROM ost_ticket A1 WHERE 1 = 1',
            $check->oneline($sql));

        $long = str_repeat('x', 400);
        $this->assertEquals(158, QueueCheck::len($check->oneline($long)));
        $this->assertStringContainsString('…', $check->oneline($long));
    }

    /* ------------------------------------------------------------------ */
    /* Nombres de columna: contra el DDL del instalador                    */
    /* ------------------------------------------------------------------ */

    /**
     * Columnas de una tabla según `setup/inc/streams/core/install-mysql.sql`.
     *
     * El diagnóstico pide las columnas por nombre al ORM, y un nombre que no
     * exista no da «lista vacía»: MariaDB contesta «Unknown column» y el
     * mensaje se pierde al registrarse. Ya pasó con `admin` en `ost_staff`
     * (la columna se llama `isadmin`), así que se comprueba contra el DDL.
     */
    private function columnsOf(string $table): array {
        $sql = file_get_contents(ROOT_DIR . '/setup/inc/streams/core/install-mysql.sql');
        preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`%TABLE_PREFIX%'
            .preg_quote($table, '/').'` \((.*?)\n\) [^\n]*/s', $sql, $m);
        $this->assertTrue(isset($m[1]),
            "no se encontró la tabla {$table} en el DDL");

        preg_match_all('/^\s*`(\w+)`/m', $m[1], $cols);
        $this->assertGreaterThan(5, count($cols[1]),
            "la tabla {$table} no tiene columnas en el DDL");

        return $cols[1];
    }

    function testLosFiltrosDeAgentesUsanColumnasQueExisten() {
        $columns = $this->columnsOf('staff');

        foreach (QueueCheck::activeStaffFilter() as $field => $value)
            $this->assertTrue(in_array($field, $columns),
                "`{$field}` no es una columna de ost_staff");

        foreach (QueueCheck::adminStaffFilter() as $field => $value)
            $this->assertTrue(in_array($field, $columns),
                "`{$field}` no es una columna de ost_staff");

        // La regresión concreta: `admin` no existe, es `isadmin`
        $this->assertFalse(in_array('admin', $columns));
        $this->assertTrue(in_array('isadmin', $columns));
        $this->assertArrayHasKey('isadmin', QueueCheck::adminStaffFilter());

        // Los otros dos nombres que usa `resolveStaff()`
        $this->assertTrue(in_array('staff_id', $columns));
        $this->assertTrue(in_array('username', $columns));
    }

    function testElDiagnosticoSoloConsultaColumnasQueExisten() {
        $ticket = $this->columnsOf('ticket');
        foreach (array('ticket_id', 'status_id', 'closed', 'created') as $column)
            $this->assertTrue(in_array($column, $ticket),
                "`{$column}` no es una columna de ost_ticket");

        $status = $this->columnsOf('ticket_status');
        foreach (array('id', 'state') as $column)
            $this->assertTrue(in_array($column, $status),
                "`{$column}` no es una columna de ost_ticket_status");
    }

    function testLenCountsAccentedCharactersOnce() {
        $this->assertEquals(6, QueueCheck::len('Año 26'));
    }
}
