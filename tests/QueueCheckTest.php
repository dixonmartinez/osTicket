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

/* Tablas que nombra la consulta de departamentos. */
foreach (array('DEPT_TABLE' => 'department', 'TICKET_TABLE' => 'ticket') as $c => $t)
    if (!defined($c))
        define($c, TABLE_PREFIX . $t);

/* Las banderas de cola. `class.queue.php` no se carga aquí (necesita la
 * base de datos), pero `flagText()` las nombra. Los valores se copian de
 * include/class.queue.php y la prueba comprueba que siguen siendo esos. */
if (!class_exists('CustomQueue')) {
    class CustomQueue {
        const FLAG_PUBLIC = 0x0001;
        const FLAG_QUEUE = 0x0002;
        const FLAG_DISABLED = 0x0004;
        const FLAG_INHERIT_CRITERIA = 0x0008;
        const FLAG_INHERIT_COLUMNS = 0x0010;
        const FLAG_INHERIT_SORTING = 0x0020;
        const FLAG_INHERIT_DEF_SORT = 0x0040;
        const FLAG_INHERIT_EXPORT = 0x0080;
    }
}

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

    /**
     * La consulta de departamentos usaba `d.dept_id`, pero la clave de
     * `ost_department` es `id` (en `ost_ticket` sí se llama `dept_id`). El
     * error 1054 no lanza: la tabla del informe salía vacía y parecía que la
     * instalación no tenía departamentos.
     */
    function testLaConsultaDeDepartamentosUsaColumnasQueExisten() {
        $query = QueueCheck::deptCountsQuery();

        $depts = $this->columnsOf('department');
        foreach (array('id', 'name') as $column)
            $this->assertTrue(in_array($column, $depts),
                "`{$column}` no es una columna de ost_department");

        // La unión es `ost_ticket.dept_id = ost_department.id`
        $this->assertTrue(strpos($query, 't.dept_id = d.id') !== false,
            'La unión no es la del esquema: ' . $query);
        $this->assertTrue(strpos($query, 'd.dept_id') === false,
            'Vuelve a usar d.dept_id, que no existe: ' . $query);

        // Y las columnas que se piden existen en cada tabla
        $ticket = $this->columnsOf('ticket');
        $this->assertTrue(in_array('dept_id', $ticket),
            '`dept_id` no es una columna de ost_ticket');
    }

    /** El informe del agente y sus departamentos usa la misma consulta. */
    function testElInformeDeVisibilidadSeApoyaEnEsaConsulta() {
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/queue.php');

        $this->assertTrue(strpos($source, 'deptCountsQuery()') !== false,
            'El informe no usa la consulta comprobable');
        $this->assertTrue(strpos($source, 'Tickets fuera de su alcance') !== false,
            'No se informa de los tickets fuera del alcance del agente');
    }

    function testLenCountsAccentedCharactersOnce() {
        $this->assertEquals(6, QueueCheck::len('Año 26'));
    }

    /* ------------------------------------------------------------------ */
    /* ¿Dónde se pierden las filas?                                        */
    /* ------------------------------------------------------------------ */

    private function steps(array $counts) {
        $steps = array();
        foreach ($counts as $i => $count)
            $steps[] = array('label' => 'tramo ' . $i, 'count' => $count);
        return $steps;
    }

    /**
     * El caso del usuario: los 60 tickets están, el criterio encuentra 23 y
     * la consulta devuelve 0. El culpable es el tramo donde cae a cero, y
     * los posteriores ya no importan.
     */
    function testElPrimerTramoQueCaeACeroEsElCulpable() {
        $culprit = QueueCheck::firstDrop($this->steps(array(60, 23, 23, 0)));

        $this->assertEquals('tramo 3', $culprit);
    }

    /** Si el criterio ya no encuentra nada, el culpable es ese tramo. */
    function testSiElCriterioNoEncuentraNadaElCulpableEsElCriterio() {
        $culprit = QueueCheck::firstDrop($this->steps(array(60, 0, 0, 0)));

        $this->assertEquals('tramo 1', $culprit);
    }

    /** Sin caídas no hay culpable: la cola funciona. */
    function testSinCaidasNoHayCulpable() {
        $this->assertSame(null, QueueCheck::firstDrop(
            $this->steps(array(60, 23, 23, 23))));
        $this->assertSame(null, QueueCheck::firstDrop(
            $this->steps(array(0, 0, 0, 0))));
    }

    /**
     * Un tramo con error no es un cero: no se puede acusar de perder filas a
     * una consulta que ni siquiera se ejecutó, y menos cortar la búsqueda ahí
     * dejando sin revisar los siguientes.
     */
    function testLosTramosConErrorNoCuentanComoCero() {
        $steps = array(
            array('label' => 'tramo 0', 'count' => 60),
            array('label' => 'tramo 1', 'count' => 'error: sin conexión'),
            array('label' => 'tramo 2', 'count' => 0),
            array('label' => 'tramo 3', 'count' => 0),
        );

        $this->assertEquals('tramo 2', QueueCheck::firstDrop($steps));
    }

    /** El desglose admite enteros sueltos, no sólo arrays con etiqueta. */
    function testFirstDropAceptaNumerosSueltos() {
        $this->assertSame(null, QueueCheck::firstDrop(array(60, 0, 23)));
    }

    /**
     * La consulta del último tramo se imprime para poder pegarla en MariaDB;
     * si el módulo no la puede compilar, el desglose sigue sirviendo.
     */
    /**
     * La barra de `/scp/tickets.php` solo muestra las colas del primer nivel
     * que llevan el bit `FLAG_QUEUE`. Una cola sin ese bit existe y no sale:
     * el sintoma es "desaparecieron mis colas" y no hay nada en el log.
     *
     * `queue --nav` tiene que decir, para cada una, si entra en la barra y el
     * motivo cuando no entra. Aqui se comprueba la tabla de motivos, que es
     * la parte que se puede probar sin base de datos.
     */
    function testElDiagnosticoDeLaBarraExplicaPorQueFaltaUnaCola() {
        $public = CustomQueue::FLAG_PUBLIC;
        $queue = CustomQueue::FLAG_QUEUE;
        $disabled = CustomQueue::FLAG_DISABLED;

        $this->assertSame('pública, cola', QueueCheck::flagText($public | $queue));
        // Sin bit de cola: es una busqueda guardada, no una cola
        $this->assertSame('pública', QueueCheck::flagText($public));
        // Deshabilitada
        $this->assertSame('pública, cola, deshabilitada',
            QueueCheck::flagText($public | $queue | $disabled));

        $fuente = file_get_contents(INCLUDE_DIR . 'class.queue.php');
        foreach (array('FLAG_PUBLIC' => '0x0001', 'FLAG_QUEUE' => '0x0002',
                'FLAG_DISABLED' => '0x0004') as $nombre => $valor)
            $this->assertMatchesRegularExpression(
                '/const\s+' . $nombre . '\s*=\s*' . $valor . ';/', $fuente,
                "$nombre cambió: los bits de arriba ya no valen");
    }

    /** Y el comando tiene que ofrecer la acción `--nav`. */
    function testElComandoOfreceLaAccionNav() {
        $module = (new ReflectionClass('QueueCheck'))->getDefaultProperties();
        $spec = $module['options'];

        $this->assertTrue(isset($spec['nav']),
            '`manage.php queue --nav` es lo que dice que colas salen en la barra');
        $this->assertStringContainsString('barra', implode(' ', $spec['nav']));
    }

    function testElDiagnosticoPublicaElSqlDelUltimoTramo() {
        $source = file_get_contents(INCLUDE_DIR . 'cli/modules/queue.php');

        $this->assertTrue(strpos($source, "'SQL del último tramo'") !== false,
            'El diagnóstico no imprime el SQL que se puede ejecutar a mano');
        $this->assertTrue(strpos($source, 'conditionAudit') !== false,
            'Falta el desglose por tramos');
        $this->assertTrue(strpos($source, 'firstDrop') !== false,
            'Falta la detección del tramo que pierde las filas');
    }
}
