<?php
/*********************************************************************
    include/cli/modules/queue.php

    Diagnóstico de una cola de tickets (las del panel, `tickets.php?queue=`)

        php manage.php queue --list            # qué colas hay, con su recuento
        php manage.php queue --id=8            # revisa la cola 8
        php manage.php queue --id=8 --sql      # … y enseña el SQL completo
        php manage.php queue --name=cerradas   # por título, sin mayúsculas
        php manage.php queue --all             # revisa todas
        php manage.php queue --periods         # compara los seis periodos

    Para qué sirve: cuando el panel dice «Showing 1 - 23 of about 23» y la
    tabla sale vacía (o al revés), el problema puede estar en tres sitios
    distintos y desde la interfaz se ven igual:

      1. el **dato** — los tickets que el criterio busca no existen;
      2. la **consulta** — el SQL que se compila no trae lo que el criterio
         pide (un JOIN de más, un `SELECT` sin columnas, un `GROUP BY` que
         colapsa filas);
      3. el **recuento** — el número sale de otra consulta (o de la caché de
         cinco minutos de `SavedQueue::counts()`) y no cuenta lo mismo.

    Este comando ejecuta *la misma* consulta que ejecuta la página —con la
    visibilidad del agente, el filtro rápido, las columnas de la cola, el
    orden y el `distinct` del listado—, la cuenta por separado, y luego cuenta
    lo que hay de verdad en la base. Si los tres números no coinciden, dice
    cuál de los tres está mintiendo.

    Código de salida: 0 si los números cuadran, 1 si hay alguna contradicción
    (sirve tal cual en un `make queue-check` o en un script de arranque).

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

class QueueCheck extends Module {

    var $prologue = 'Diagnostica una cola: criterio, SQL real, filas y recuento';

    var $arguments = array();

    var $options = array(
        'id' => array('-i', '--id', 'default' => '', 'metavar' => 'ID',
            'help' => 'Cola a revisar (por omisión, la primera de la lista)'),
        'name' => array('-n', '--name', 'default' => '', 'metavar' => 'TEXTO',
            'help' => 'Busca la cola por su título, sin distinguir mayúsculas'),
        'list' => array('-l', '--list', 'action' => 'store_true',
            'help' => 'Lista las colas con su identificador y su recuento'),
        'all' => array('-a', '--all', 'action' => 'store_true',
            'help' => 'Revisa todas las colas, una sección por cola'),
        'as' => array('', '--as', 'default' => '', 'metavar' => 'AGENTE',
            'help' => 'Agente con el que se calcula la visibilidad (usuario o id)'),
        'filter' => array('-f', '--filter', 'default' => '', 'metavar' => 'FILTRO',
            'help' => 'Filtro rápido de la cola, igual que ?filter= en el panel'),
        'limit' => array('', '--limit', 'default' => '25', 'metavar' => 'N',
            'help' => 'Cuántas filas se piden, como la página (25 por omisión)'),
        'sql' => array('', '--sql', 'action' => 'store_true',
            'help' => 'Imprime el SQL completo de la consulta de la lista'),
        'periods' => array('', '--periods', 'action' => 'store_true',
            'help' => 'Compara los seis periodos de fecha con los tickets reales'),
        'json' => array('', '--json', 'action' => 'store_true',
            'help' => 'Salida JSON, para engancharla a otra herramienta'),
    );

    /** Filas que se piden, como la página. */
    var $limit = 25;
    var $json = false;
    var $showSql = false;
    /** @var Staff con el que se calcula la visibilidad. */
    var $staff;
    /** Lo que contestó la base en la comprobación previa (versión, base…). */
    var $connection = array();
    /** Contradicciones encontradas: son las que hacen fallar el comando. */
    var $problems = 0;

    /** Los seis periodos que trae la cola Closed del instalador. */
    static function periods() {
        return array(
            'td' => 'Hoy',
            'yd' => 'Ayer',
            'tw' => 'Esta semana',
            'tm' => 'Este mes',
            'tq' => 'Este trimestre',
            'ty' => 'Este año',
        );
    }

    /* ------------------------------------------------------------------ */
    /* Entrada                                                             */
    /* ------------------------------------------------------------------ */

    function run($args, $options) {
        global $ost, $cfg;

        $this->limit = max(1, (int) $this->getOption('limit', 25));
        $this->json = (bool) $this->getOption('json');
        $this->showSql = (bool) $this->getOption('sql');

        // El ORM lanza «Unable to prepare query:» con el mensaje de MariaDB
        // en blanco cuando `db_query()` alcanza a registrar el fallo: ese
        // INSERT en `ost_syslog` deja `mysqli::$error` limpio. Con el modo de
        // informes de mysqli apagado, la consulta devuelve `false` en vez de
        // lanzar, y el mensaje sigue disponible para quien lo cuente. Es un
        // ajuste sólo de este proceso de CLI, no de la aplicación.
        if (function_exists('mysqli_report') && defined('MYSQLI_REPORT_OFF'))
            mysqli_report(MYSQLI_REPORT_OFF);

        // `Bootstrap::loadCode()` ya trae `class.queue.php` y `class.search.php`,
        // pero no `class.ticket.php` (Ticket, TicketForm), que es donde están
        // el `FLAG_LINKED` y la vista materializada de los campos dinámicos.
        require_once INCLUDE_DIR . 'class.ticket.php';

        try {
            Bootstrap::connect();
            $ost = osTicket::start();
        }
        catch (Throwable $e) {
            $this->err('No se pudo abrir la base de datos: '.$e->getMessage());
            return 2;
        }

        if (!$ost || !($cfg = $ost->getConfig()))
            $this->fail('No se pudo leer la configuración de la base de datos');

        // Antes de tocar el ORM: ¿la conexión responde y las tablas están?
        // Si no, el aviso tiene que decir **qué** contesta MariaDB; el ORM sólo
        // puede decir «Unable to prepare query».
        list($this->connection, $problem) = $this->preflight();
        if ($problem) {
            $this->err('La base de datos no responde como se espera.');
            $this->err('  ' . $problem);
            $this->err('');
            $this->err('Compruebe que el contenedor `db` está arriba y que la');
            $this->err('instalación terminó:  docker compose ps db');
            $this->err('                      docker compose logs --tail=30 db');
            return 2;
        }

        // Los modelos de cola leen el agente «en curso»: `SavedQueue::__onload`
        // busca la configuración personal de `$thisstaff` (orden, columnas) y
        // la visibilidad de los tickets se calcula con él. En la web eso viene
        // de la sesión; aquí no hay sesión, así que se fija el agente a mano.
        try {
            $this->staff = $this->resolveStaff($this->getOption('as'));
        }
        catch (Throwable $e) {
            $this->err('No se pudo leer el agente con el que se calcula la'
                .' visibilidad.');
            $this->err('  ' . $e->getMessage());
            if (function_exists('db_last_failure_text') && db_last_failure_text())
                $this->err('  MariaDB: ' . db_last_failure_text());
            return 2;
        }

        if (!$this->staff) {
            $this->err('No hay agentes activos en esta instalación.');
            $this->err('  Agentes en la tabla `'.STAFF_TABLE.'`: '
                . $this->connectionCount(STAFF_TABLE));
            $this->err('  Créelos desde el panel (`scp/`) o con'
                .' `php manage.php agent --help`.');
            return 2;
        }

        $GLOBALS['thisstaff'] = $this->staff;

        try {
            $queues = $this->queues();
        }
        catch (Throwable $e) {
            $this->err('No se pudieron leer las colas.');
            $this->err('  ' . $e->getMessage());
            if (function_exists('db_last_failure_text') && db_last_failure_text())
                $this->err('  MariaDB: ' . db_last_failure_text());
            return 2;
        }

        if (!$queues) {
            $this->err('No hay colas de tickets en esta instalación.');
            $this->err('  Filas en `'.QUEUE_TABLE.'`: '
                . $this->connectionCount(QUEUE_TABLE));
            return 2;
        }

        try {
            return $this->dispatch($queues);
        }
        catch (Throwable $e) {
            $this->err('');
            $this->err('El diagnóstico se detuvo: ' . $e->getMessage());
            if (function_exists('db_last_failure_text') && db_last_failure_text())
                $this->err('  MariaDB: ' . db_last_failure_text());
            return 2;
        }
    }

    /** Ejecuta la acción pedida (listar, revisar una o revisar todas). */
    function dispatch($queues) {
        if ($this->getOption('list')) {
            $this->listQueues($queues);
            return $this->problems ? 1 : 0;
        }

        if ($this->getOption('all')) {
            $reports = array();
            foreach ($queues as $queue)
                $reports[] = $this->checkQueue($queue);
            if ($this->json)
                $this->emitJson(array('agent' => $this->staffInfo(),
                    'server' => $this->connection, 'queues' => $reports));
            return $this->problems ? 1 : 0;
        }

        $queue = $this->pickQueue($queues);
        $report = $this->checkQueue($queue);
        if ($this->json)
            $this->emitJson(array('agent' => $this->staffInfo(),
                'server' => $this->connection, 'queues' => array($report)));

        return $this->problems ? 1 : 0;
    }

    /**
     * Comprobación previa de la conexión, **sin** pasar por el ORM.
     *
     * `db_query()` registra cada fallo en `ost_syslog` (y puede mandar un
     * correo de aviso), y ese INSERT deja `mysqli::$error` vacío: por eso el
     * ORM acaba diciendo «Unable to prepare query:  SELECT …», con dos
     * espacios donde debería estar el motivo. Aquí se consulta con
     * `$logError = false` justo para que el mensaje de MariaDB siga ahí
     * cuando se lee.
     *
     * @return array [info de la conexión, problema o null]
     */
    function preflight() {
        $checks = array(
            'conexión' => 'SELECT 1 AS ok',
            'servidor' => 'SELECT DATABASE() AS base, VERSION() AS version',
            'agentes' => 'SELECT COUNT(*) AS n FROM ' . STAFF_TABLE,
            'colas' => 'SELECT COUNT(*) AS n FROM ' . QUEUE_TABLE,
        );

        $info = array();
        $problem = null;
        foreach ($checks as $name => $sql) {
            $res = false;
            try {
                $res = db_query($sql, false);
            }
            catch (Throwable $e) {
                $problem = sprintf('%s: %s (%s)', $name, $e->getMessage(), $sql);
                break;
            }

            if (!$res) {
                $problem = sprintf('%s: %s (%s)', $name,
                    (function_exists('db_last_failure_text') && db_last_failure_text())
                        ? db_last_failure_text()
                        : sprintf('[%d] %s', db_errno(),
                            db_error() ?: 'sin mensaje'),
                    $sql);
                break;
            }

            $row = db_fetch_array($res);
            db_free_result($res);
            if (!is_array($row))
                $row = array('base' => '?', 'version' => '?', 'n' => null);

            if ($name === 'servidor') {
                $info['base'] = $row['base'];
                $info['version'] = $row['version'];
            }
            elseif ($name === 'agentes' || $name === 'colas')
                $info[$name] = (int) $row['n'];
            else
                $info[$name] = true;
        }

        return array($info, $problem);
    }

    /** Cuántas filas tiene una tabla (para los mensajes de «no hay agentes»). */
    function connectionCount($table) {
        try {
            $res = db_query('SELECT COUNT(*) AS n FROM ' . $table, false);
            if (!$res)
                return '?';
            $row = db_fetch_array($res);
            db_free_result($res);
            return is_array($row) ? (int) $row['n'] : '?';
        }
        catch (Throwable $e) {
            return '?';
        }
    }

    /**
     * Agente con el que se calcula la visibilidad.
     *
     * Por omisión el primer administrador activo: es el que más ve, así que
     * una tabla vacía no se puede achacar a que ese agente no tiene acceso a
     * los tickets. `--as=usuario` o `--as=3` lo cambian para reproducir
     * exactamente lo que ve otra persona.
     */
    function resolveStaff($who) {
        if ($who) {
            $query = Staff::objects()->filter(self::activeStaffFilter());
            if (ctype_digit((string) $who))
                $query->filter(array('staff_id' => (int) $who));
            else
                $query->filter(array('username' => (string) $who));
            return $query->first();
        }

        $admins = Staff::objects()->filter(self::adminStaffFilter())
            ->order_by('staff_id');
        if ($admin = $admins->first())
            return $admin;

        return Staff::objects()->filter(self::activeStaffFilter())
            ->order_by('staff_id')->first();
    }

    /**
     * Agentes activos: sólo las columnas que existen en `ost_staff`.
     *
     * Ojo con `isadmin`: la tabla **no** tiene `admin` (ver
     * `setup/inc/streams/core/install-mysql.sql`), y `Staff` usa `isadmin` para
     * todo (`isAdmin()` devuelve `$this->isadmin`). Pedir `admin` aquí no
     * devuelve una lista vacía: MariaDB contesta «Unknown column 'A1.admin'»
     * y, como `db_query()` registra el fallo en `ost_syslog` antes de que nadie
     * lea el mensaje, el ORM lo cuenta como «Unable to prepare query» con el
     * motivo en blanco.
     */
    static function activeStaffFilter() {
        return array('isactive' => 1);
    }

    /** Agentes activos y administradores (columna `isadmin`). */
    static function adminStaffFilter() {
        return array('isactive' => 1, 'isadmin' => 1);
    }

    function staffInfo() {
        return array(
            'id' => $this->staff->getId(),
            'username' => $this->staff->getUserName(),
            'name' => $this->staff->getName(),
            'admin' => (bool) $this->staff->isAdmin(),
        );
    }

    /** @return SavedQueue[] en el orden en que el panel las muestra. */
    function queues() {
        return SavedQueue::objects()->order_by('sort')->all();
    }

    /** La cola que se pidió; si no se pidió ninguna, la primera. */
    function pickQueue($queues) {
        $id = trim((string) $this->getOption('id'));
        if ($id !== '') {
            if (!is_numeric($id) || !($queue = SavedQueue::lookup((int) $id)))
                $this->fail("No existe la cola {$id}");
            return $queue;
        }

        if (($name = trim((string) $this->getOption('name'))) !== '') {
            foreach ($queues as $queue)
                if (stripos($queue->getName(), $name) !== false)
                    return $queue;
            $this->fail("Ninguna cola se llama como «{$name}»");
        }

        return $queues[0];
    }

    /* ------------------------------------------------------------------ */
    /* Listado                                                             */
    /* ------------------------------------------------------------------ */

    function listQueues($queues) {
        $rows = array();
        foreach ($queues as $queue) {
            $rows[] = array(
                'id' => $queue->getId(),
                'title' => $queue->getName(),
                'parent' => ($parent = $queue->parent) ? $parent->getId() : '',
                'flags' => $this->flagText((int) $queue->flags),
                'count' => $this->safeCount($queue),
            );
        }

        if ($this->json) {
            $this->emitJson(array('agent' => $this->staffInfo(), 'queues' => $rows));
            return;
        }

        $this->out($this->serverLine());
        $this->out('');
        $this->table(
            array('ID', 'Cola', 'Padre', 'Tipo', 'Tickets'),
            array_map(function($r) {
                return array($r['id'], $r['title'], $r['parent'] ?: '—',
                    $r['flags'], $r['count'] === null ? '?' : $r['count']);
            }, $rows)
        );
        $this->out('');
        $this->out('Revise una con:  php manage.php queue --id=<ID>');
    }

    /** «MariaDB 11.4 · base osticket» para la cabecera de la salida. */
    function serverLine() {
        if (!$this->connection)
            return '';
        return sprintf('%s · base %s',
            $this->connection['version'] ?: 'MariaDB',
            $this->connection['base'] ?: '?');
    }

    /** Recuento de la cola, sin caché y sin romper el listado si falla. */
    function safeCount($queue) {
        try {
            return $queue->getCount($this->staff, false);
        }
        catch (Throwable $e) {
            return null;
        }
    }

    /* ------------------------------------------------------------------ */
    /* La revisión                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Revisa una cola y devuelve el informe (también es lo que se serializa
     * con `--json`).
     */
    function checkQueue($queue) {
        $report = array(
            'queue' => $this->describeQueue($queue),
            'agent' => $this->staffInfo(),
            'filter' => (string) $this->getOption('filter'),
            'query' => null,
            'database' => null,
            'verdict' => array(),
        );

        $report['query'] = $this->inspectListQuery($queue);
        $report['database'] = $this->dbStats();
        $report['verdict'] = $this->verdict($report);

        if (!$this->json)
            $this->printReport($report);

        if ($report['verdict']['status'] == 'error')
            $this->problems++;

        return $report;
    }

    function describeQueue($queue) {
        $columns = array();
        $error = null;
        try {
            foreach ($queue->getColumns() as $C)
                $columns[] = sprintf('%s (%s)', $C->getName(), $C->getId());
        }
        catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return array(
            'id' => $queue->getId(),
            'title' => $queue->getName(),
            'full_name' => $queue->getFullName(),
            'parent' => ($parent = $queue->parent) ? $parent->getName() : null,
            'flags' => (int) $queue->flags,
            'flags_text' => $this->flagText((int) $queue->flags),
            'criteria' => $this->criteria($queue),
            'columns' => $columns,
            'columns_error' => $error,
        );
    }

    /** El criterio de la cola, con el de sus padres si lo hereda. */
    function criteria($queue) {
        $out = array();
        try {
            foreach ($queue->getCriteria(true) as $criterion)
                $out[] = self::describeCriterion($criterion);
        }
        catch (Throwable $e) {
            $out[] = 'no se pudo leer el criterio: '.$e->getMessage();
        }
        return $out;
    }

    static function describeCriterion($criterion) {
        if (!is_array($criterion))
            return var_export($criterion, true);

        $name = isset($criterion[0]) ? $criterion[0] : '?';
        $method = isset($criterion[1]) ? $criterion[1] : '';
        $value = array_key_exists(2, $criterion) ? $criterion[2] : null;

        if (is_array($value)) {
            $parts = array();
            foreach ($value as $k => $v)
                $parts[] = is_int($k) ? self::scalarText($v) : "{$k}: ".self::scalarText($v);
            $value = '['.implode(', ', $parts).']';
        }
        else {
            $value = self::scalarText($value);
        }

        return trim($name.' '.$method.' '.$value);
    }

    static function scalarText($value) {
        if (is_bool($value))
            return $value ? 'sí' : 'no';
        if ($value === null)
            return '—';
        if (is_scalar($value))
            return (string) $value;
        return gettype($value);
    }

    static function flagText($flags) {
        $names = array();
        foreach (array(
            CustomQueue::FLAG_PUBLIC => 'pública',
            CustomQueue::FLAG_QUEUE => 'cola',
            CustomQueue::FLAG_DISABLED => 'deshabilitada',
            CustomQueue::FLAG_INHERIT_CRITERIA => 'hereda criterio',
            CustomQueue::FLAG_INHERIT_COLUMNS => 'hereda columnas',
            CustomQueue::FLAG_INHERIT_SORTING => 'hereda orden',
            CustomQueue::FLAG_INHERIT_DEF_SORT => 'hereda orden por omisión',
            CustomQueue::FLAG_INHERIT_EXPORT => 'hereda exportación',
        ) as $bit => $name)
            if ($flags & $bit)
                $names[] = $name;

        return $names ? implode(', ', $names) : sprintf('0x%02x', $flags);
    }

    /* ------------------------------------------------------------------ */
    /* La consulta de la lista                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Arma la consulta **igual** que la página de tickets y la ejecuta.
     *
     * El orden de los pasos no es decorativo: `scp/tickets.php` llama a
     * `getQuery()` (criterio + filtro rápido + columnas) y luego
     * `queue-tickets.tmpl.php` añade la visibilidad del agente, el filtro de
     * tickets hijos, el `distinct` y la ventana de paginación. Si aquí se
     * hiciera en otro orden, el SQL que se imprime no sería el de la página y
     * el diagnóstico mentiría.
     */
    function buildListQuery($queue, $withColumns=true) {
        $filter = trim((string) $this->getOption('filter'));
        // Sin columnas se parte del criterio pelado: es la consulta con la que
        // la cola **cuenta**, y sirve de referencia para saber cuántas filas
        // se lleva cada columna por el camino.
        $query = $withColumns
            ? $queue->getQuery(false, $filter !== '' ? $filter : null)
            : $queue->getBasicQuery();

        // Visibilidad: la misma condición que la plantilla
        $ignore = $queue->ignoreVisibilityConstraints($this->staff);
        if (!$ignore || ($ignore && ($queue->isAQueue() || $queue->isASubQueue())))
            $query->filter($this->staff->getTicketsVisibility());

        // Los tickets hijos no se listan en las colas
        $this->filterChildren($query, $queue);

        // Las tablas `*__cdata` son vistas materializadas: sin esto, las
        // columnas de asunto y de formulario no existen y la consulta falla.
        try {
            // `$croak = false`: si la vista no se puede construir, aquí se
            // prefiere el error de la consulta al `die()` de la vista.
            TicketForm::ensureDynamicDataView(false, false);
        }
        catch (Throwable $e) {
            // Se avisa más abajo: la consulta se ejecuta igual y el error de
            // la base dirá qué falta.
        }

        // Orden por omisión de la cola (el que aplica la plantilla cuando el
        // agente no ha elegido otro)
        try {
            if ($sort = $queue->getDefaultSort())
                $sort->applySort($query, 0);
            else
                $query->order_by('-created');
        }
        catch (Throwable $e) {
            $query->order_by('-created');
        }

        $query->distinct('ticket_id');

        return $query;
    }

    function inspectListQuery($queue) {
        $result = array(
            'sql' => null,
            'params' => array(),
            'rows' => null,
            'limit' => $this->limit,
            'count' => null,
            'queue_count' => null,
            'queue_total' => null,
            'error' => null,
            'issues' => array(),
            'shape' => array(),
        );

        try {
            $paginated = $this->buildListQuery($queue);
            $paginated->limit($this->limit);

            $compiled = $paginated->getQuery();
            $result['sql'] = $compiled->sql;
            $result['params'] = $compiled->params ?: array();
            $result['issues'] = self::inspectSql($result['sql']);
            $result['shape'] = self::sqlShape($result['sql']);
        }
        catch (Throwable $e) {
            $result['error'] = $e->getMessage();
            return $result;
        }

        // Filas: se ejecuta el SQL tal cual lo compiló el ORM. Se cuentan las
        // filas en vez de hidratar modelos: aquí interesa si la base devuelve
        // algo, no si el modelo de ticket se construye bien (eso lo prueba la
        // propia página).
        try {
            $sql = self::bindParams($result['sql'], $result['params']);
            if ($res = db_query($sql, false))
                $result['rows'] = db_num_rows($res);
        }
        catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        // Recuento de la **misma** consulta, sin ventana. Es el número que
        // debe salir en «Showing 1 - 25 of about N».
        try {
            $total = $this->buildListQuery($queue);
            $total->limit(false)->offset(false);
            $result['count'] = (int) $total->count();
        }
        catch (Throwable $e) {
            if (!$result['error'])
                $result['error'] = 'al contar: '.$e->getMessage();
        }

        // Lo que dice la cola: `getCount()` con la caché de cinco minutos y
        // `getTotal()` sin caché. Si el primero no coincide con el segundo, el
        // número que vio el usuario venía de la caché.
        try {
            $result['queue_count'] = $queue->getCount($this->staff);
        }
        catch (Throwable $e) {
            $result['queue_count'] = null;
        }
        try {
            $result['queue_total'] = $queue->getTotal($this->staff);
        }
        catch (Throwable $e) {
            $result['queue_total'] = null;
        }

        $result['conditions'] = $this->conditionAudit($queue);
        $result['visibility'] = $this->visibilityAudit();
        $result['columns'] = $this->columnAudit($queue);
        $result['materialized'] = $this->materializedAudit();

        return $result;
    }

    /**
     * Dónde se pierden las filas: se cuenta la consulta por tramos.
     *
     * `getCount()` dice 23 y la consulta 0; el desglose por columnas dirá que
     * el problema no son las columnas (la referencia sin columnas también da
     * 0). Entonces la pregunta es **qué condición** deja la consulta sin
     * filas. Se aplican por orden, contando después de cada una:
     *
     *   1. todos los tickets, sin nada;
     *   2. sólo el criterio de la cola;
     *   3. + la visibilidad del agente;
     *   4. + el filtro de tickets hijos (lo que lista la página).
     *
     * El tramo donde el número cae a cero es el culpable, y ahí se imprime
     * el SQL completo con los parámetros ya sustituidos para poder
     * ejecutarlo a mano.
     */
    function conditionAudit($queue) {
        $root = $queue->getRoot();
        $steps = array();

        $count = function($callback) {
            try {
                return (int) $callback()->count();
            }
            catch (Throwable $e) {
                return 'error: ' . $e->getMessage();
            }
        };

        $steps[] = array(
            'label' => 'Todos los tickets (sin criterio, sin columnas)',
            'count' => $count(function() use ($root) { return $root::objects(); }),
            'sql' => null,
        );

        $steps[] = array(
            'label' => 'Sólo el criterio de la cola',
            'count' => $count(function() use ($queue) {
                return $queue->getBasicQuery();
            }),
            'sql' => function() use ($queue) { return $queue->getBasicQuery(); },
        );

        $staff = $this->staff;
        $steps[] = array(
            'label' => '+ visibilidad del agente',
            'count' => $count(function() use ($queue, $staff) {
                return $queue->getBasicQuery()
                    ->filter($staff->getTicketsVisibility());
            }),
            'sql' => function() use ($queue, $staff) {
                return $queue->getBasicQuery()
                    ->filter($staff->getTicketsVisibility());
            },
        );

        $steps[] = array(
            'label' => '+ sin tickets hijos (lo que lista la página)',
            'count' => $count(function() use ($queue) {
                $query = $queue->getBasicQuery()
                    ->filter($this->staff->getTicketsVisibility());
                return $this->filterChildren($query, $queue);
            }),
            'sql' => function() use ($queue) {
                $query = $queue->getBasicQuery()
                    ->filter($this->staff->getTicketsVisibility());
                return $this->filterChildren($query, $queue);
            },
        );

        // El SQL del último tramo, que es el que la página ejecuta
        $audit = array('steps' => $steps, 'sql' => null, 'params' => array(),
            'culprit' => null);
        try {
            $compiled = $steps[3]['sql']()->getQuery();
            $audit['sql'] = $compiled->sql;
            $audit['params'] = $compiled->params ?: array();
        }
        catch (Throwable $e) {
            // Sin SQL queda el desglose, que ya dice dónde se cae
        }

        // Si el tramo que falla es el de los hijos, hacen falta los números
        // de las dos ramas del OR: `ticket_pid IS NULL` y la bandera.
        $audit['children'] = $this->childrenAudit();

        $audit['culprit'] = self::firstDrop($steps);

        return $audit;
    }

    /**
     * El primer tramo en el que el número cae de positivo a cero.
     *
     * Es el tramo que deja la consulta vacía; los siguientes ya no importan.
     * Se compara con el tramo anterior *con filas*: si una condición ya venía
     * fallando desde el principio (todos los tickets = 0), el culpable es ese
     * y no el siguiente.
     */
    static function firstDrop($steps) {
        $previous = null;
        foreach ($steps as $step) {
            $count = is_array($step) ? ($step['count'] ?? null) : $step;
            if (!is_int($count))
                continue;
            if ($previous !== null && $previous > 0 && $count === 0)
                return is_array($step) ? $step['label'] : null;
            $previous = $count;
        }

        return null;
    }

    /**
     * Las dos ramas del filtro de hijos, contadas por separado.
     *
     * La condición es `ticket_pid IS NULL OR (flags & FLAG_LINKED)`. Si
     * ninguna de las dos se cumple, la cola se queda sin filas aunque los
     * tickets existan y el criterio los encuentre.
     */
    function childrenAudit() {
        return array(
            'no_parent' => $this->tabled('SELECT COUNT(*) FROM '.TICKET_TABLE
                .' WHERE ticket_pid IS NULL'),
            'zero_parent' => $this->tabled('SELECT COUNT(*) FROM '.TICKET_TABLE
                .' WHERE ticket_pid = 0'),
            'linked' => $this->tabled('SELECT COUNT(*) FROM '.TICKET_TABLE
                .' WHERE (flags & '.Ticket::FLAG_LINKED.') <> 0'),
            'flag' => Ticket::FLAG_LINKED,
        );
    }

    /**
     * Departamentos del agente y tickets que quedan fuera de su alcance.
     *
     * La visibilidad que aplica la cola es la del agente: `dept_id IN (...)
     * OR asignado a él OR referido`. Esos departamentos **no son todos los de
     * la instalación**: salen de `dept_access`, del departamento principal y
     * de los que gestiona. Un administrador no ve, sólo por serlo, los
     * tickets de un departamento al que no está asignado — y una cola puede
     * contar 23 y no listar ninguno si esos 23 están fuera.
     */
    function visibilityAudit() {
        $audit = array('staff_depts' => array(), 'by_dept' => array(),
            'outside' => null, 'outside_names' => array(), 'no_dept' => null,
            'error' => null);

        try {
            $audit['staff_depts'] = array_map('intval', $this->staff->getDepts());
        }
        catch (Throwable $e) {
            $audit['error'] = $e->getMessage();
        }

        // El departamento tiene la clave en `id` (no en `dept_id`, que es el
        // nombre de la columna en `ost_ticket`). Y si la consulta falla hay
        // que decirlo: `db_query()` devuelve `false` sin lanzar, así que sin
        // esto la tabla sale vacía como si no hubiera departamentos.
        try {
            $res = db_query(self::deptCountsQuery(), false);
            if (!$res)
                throw new Exception(function_exists('db_last_failure_text')
                    && db_last_failure_text()
                        ? db_last_failure_text()
                        : 'la consulta de departamentos no devolvió resultado');
            while ($res && ($row = db_fetch_array($res)))
                $audit['by_dept'][(int) $row['id']] = array(
                    'name' => $row['name'], 'tickets' => (int) $row['n']);
            db_free_result($res);
        }
        catch (Throwable $e) {
            $audit['error'] = $audit['error'] ?: $e->getMessage();
        }

        $audit['no_dept'] = $this->tabled('SELECT COUNT(*) FROM '.TICKET_TABLE
            .' WHERE dept_id = 0');

        if ($audit['staff_depts']) {
            $ids = implode(',', $audit['staff_depts']);
            $audit['outside'] = $this->tabled('SELECT COUNT(*) FROM '
                .TICKET_TABLE.' WHERE dept_id NOT IN ('.$ids.')');
            $audit['outside_names'] = self::outsideNames($audit['by_dept'],
                $audit['staff_depts']);
        }

        return $audit;
    }

    /**
     * Departamentos con sus tickets. La clave del departamento es `id`; en
     * `ost_ticket` la columna se llama `dept_id`. Confundirlas da un error
     * 1054 que `db_query()` no lanza, así que la tabla salía vacía sin decir
     * nada: las dos partes de la unión van aquí, en una sola cadena, para que
     * se puedan comprobar contra el esquema en las pruebas.
     */
    static function deptCountsQuery() {
        return 'SELECT d.id, d.name, COUNT(t.ticket_id) AS n'
            .' FROM '.DEPT_TABLE.' d'
            .' LEFT JOIN '.TICKET_TABLE.' t ON (t.dept_id = d.id)'
            .' GROUP BY d.id, d.name ORDER BY d.id';
    }

    /**
     * Departamentos con tickets que el agente no tiene asignados, con su
     * recuento: «Soporte N2 (23)». Es lo que se nombra en el aviso, porque un
     * número de departamento no dice nada a quien lee el informe.
     */
    static function outsideNames($byDept, $staffDepts) {
        $names = array();
        foreach ($byDept as $id => $dept) {
            if (in_array((int) $id, array_map('intval', $staffDepts)))
                continue;
            if (empty($dept['tickets']))
                continue;
            $names[] = sprintf('%s (%d)',
                $dept['name'] !== '' ? $dept['name'] : 'dept ' . $id,
                $dept['tickets']);
        }

        return $names;
    }

    /** El filtro de «esto no es un ticket hijo», igual que la plantilla. */
    function filterChildren($query, $queue) {
        if ($queue->isAQueue() || $queue->isASubQueue())
            $query->filter(Q::any(array(
                'ticket_pid' => null,
                'flags__hasbit' => Ticket::FLAG_LINKED,
            )));
        return $query;
    }

    /**
     * Cuántas filas quedan al aplicar **cada columna** por separado.
     *
     * Es la prueba que separa «el criterio no encuentra nada» de «una columna
     * se lleva las filas por el camino». Las columnas no sólo añaden datos al
     * `SELECT`: meten JOIN (asunto → `ost_ticket__cdata`, usuario → `ost_user`,
     * asignado → `ost_staff`…) y, si alguno es interno y la tabla está a
     * medias, el listado sale vacío aunque el recuento diga 23.
     */
    function columnAudit($queue) {
        $audit = array('baseline' => null, 'baseline_error' => null,
            'columns' => array());

        try {
            $base = $this->buildListQuery($queue, false);
            $audit['baseline'] = (int) $base->count();
        }
        catch (Throwable $e) {
            $audit['baseline_error'] = $e->getMessage();
        }

        foreach ($queue->getColumns() as $C) {
            $row = array(
                'id' => $C->getId(),
                'name' => $C->getName(),
                'count' => null,
                'error' => null,
            );
            try {
                $query = $this->buildListQuery($queue, false);
                $C->setQueue($queue);
                $query = $C->mangleQuery($query, $queue->getRoot());
                $query->distinct('ticket_id');
                $row['count'] = (int) $query->count();
            }
            catch (Throwable $e) {
                $row['error'] = $e->getMessage();
                if (function_exists('db_last_failure_text')
                        && db_last_failure_text())
                    $row['error'] .= ' · ' . db_last_failure_text();
            }
            $audit['columns'][] = $row;
        }

        return $audit;
    }

    /**
     * Estado de las tablas materializadas de los formularios.
     *
     * `ost_ticket__cdata` no es una tabla que escriba la aplicación fila a
     * fila: se construye con `CREATE TABLE … AS SELECT` sobre
     * `form_entry_values` (`DynamicForm::buildDynamicDataView`). Si se quedó a
     * medias —se creó cuando había pocos tickets, o alguien la recreó— los
     * tickets que no están en ella desaparecen de **todas** las colas, porque
     * el JOIN de la columna «Subject» es interno. El recuento cacheado, que no
     * usa columnas, los sigue contando: de ahí «23 de 23» con la tabla vacía.
     */
    function materializedAudit() {
        $audit = array();

        $audit['cdata_rows'] = $this->tabled(
            'SELECT COUNT(*) FROM '.TICKET_CDATA_TABLE);
        $audit['cdata_missing'] = $this->tabled(
            'SELECT COUNT(*) FROM '.TICKET_TABLE.' t'
            .' LEFT JOIN '.TICKET_CDATA_TABLE.' c ON (c.ticket_id = t.ticket_id)'
            .' WHERE c.ticket_id IS NULL');
        $audit['entries'] = $this->tabled(
            'SELECT COUNT(DISTINCT object_id) FROM '.FORM_ENTRY_TABLE
            ." WHERE object_type = 'T'");
        $audit['tickets_without_entry'] = $this->tabled(
            'SELECT COUNT(*) FROM '.TICKET_TABLE.' t'
            .' LEFT JOIN '.FORM_ENTRY_TABLE.' e ON (e.object_id = t.ticket_id'
            ." AND e.object_type = 'T')"
            .' WHERE e.id IS NULL');

        return $audit;
    }

    /** Cuenta una fila; si la tabla no existe (o falla), devuelve null. */
    function tabled($sql) {
        return $this->scalar($sql);
    }

    /**
     * Sustituye los marcadores `:1`, `:2`… por sus valores.
     *
     * Es sólo para poder ejecutar a mano la consulta y para imprimirla
     * completa; la aplicación usa sentencias preparadas de mysqli. El
     * escapador se puede inyectar (lo usan las pruebas, que no tienen base).
     */
    static function bindParams($sql, $params, $escape=null) {
        if (!$params)
            return $sql;

        if (!$escape)
            $escape = function($value) {
                if (function_exists('db_real_escape'))
                    return "'".db_real_escape($value, false)."'";
                return "'".addslashes($value)."'";
            };

        return preg_replace_callback('/:(\d+)/',
            function($m) use ($params, $escape) {
                $index = (int) $m[1] - 1;
                if (!array_key_exists($index, $params))
                    return $m[0];

                $value = $params[$index];
                if ($value === null)
                    return 'NULL';
                if (is_bool($value))
                    return $value ? '1' : '0';
                if (is_int($value) || is_float($value))
                    return (string) $value;
                if ($value instanceof DateTimeInterface)
                    return $escape($value->format('Y-m-d H:i:s'));

                return $escape((string) $value);
            }, $sql);
    }

    /**
     * Comprueba la forma del SQL compilado. No valida la semántica (eso lo
     * hace la base), pero sí las dos formas rotas que ya se han visto en esta
     * instalación: una lista de columnas vacía y un `GROUP BY` que no nombra
     * ninguna columna.
     */
    static function inspectSql($sql) {
        $issues = array();

        if (preg_match('/\bSELECT\s+(?:SQL_CALC_FOUND_ROWS\s+)?FROM\b/i', $sql))
            $issues[] = 'la consulta no selecciona ninguna columna (SELECT … FROM)';

        if (preg_match('/\bGROUP BY\s+(ORDER BY|LIMIT|HAVING|$)/i', $sql))
            $issues[] = 'el GROUP BY no nombra ninguna columna';

        return $issues;
    }

    /** Un resumen de una línea de la forma de la consulta. */
    static function sqlShape($sql) {
        $shape = array();
        $shape['select'] = preg_match_all('/\bSELECT\b/i', $sql);
        $shape['joins'] = preg_match_all('/\bJOIN\b/i', $sql);
        $shape['left_joins'] = preg_match_all('/\bLEFT JOIN\b/i', $sql);
        $shape['where'] = (bool) preg_match('/\bWHERE\b/i', $sql);
        $shape['group_by'] = (bool) preg_match('/\bGROUP BY\b/i', $sql);
        $shape['order_by'] = (bool) preg_match('/\bORDER BY\b/i', $sql);
        $shape['limit'] = preg_match('/\bLIMIT\s+(\d+)/i', $sql, $m) ? (int) $m[1] : null;
        $shape['tables'] = array();
        // El prefijo de las tablas lo define la instalación; si no está (por
        // ejemplo en una prueba unitaria), vale cualquier nombre de tabla.
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : '';
        if (preg_match_all('/\b(?:FROM|JOIN)\s+`?(' . preg_quote($prefix, '/')
                . '\w+)`?/i', $sql, $m))
            $shape['tables'] = array_values(array_unique($m[1]));

        return $shape;
    }

    /* ------------------------------------------------------------------ */
    /* La base de datos                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Lo que hay de verdad en la base: reparto por estado, cuántos tickets
     * tienen fecha de cierre y las incoherencias entre estado y fecha.
     */
    function dbStats() {
        $stats = array(
            'total' => $this->scalar('SELECT COUNT(*) FROM '.TICKET_TABLE),
            'states' => array(),
            'closed_not_null' => $this->scalar(
                'SELECT COUNT(*) FROM '.TICKET_TABLE.' WHERE closed IS NOT NULL'),
            'closed_null' => $this->scalar(
                'SELECT COUNT(*) FROM '.TICKET_TABLE.' WHERE closed IS NULL'),
            'state_closed_but_no_date' => $this->scalar(
                'SELECT COUNT(*) FROM '.TICKET_TABLE.' t'
                .' JOIN '.TICKET_STATUS_TABLE.' s ON (s.id = t.status_id)'
                ." WHERE s.state = 'closed' AND t.closed IS NULL"),
            'date_but_not_closed' => $this->scalar(
                'SELECT COUNT(*) FROM '.TICKET_TABLE.' t'
                .' JOIN '.TICKET_STATUS_TABLE.' s ON (s.id = t.status_id)'
                ." WHERE s.state <> 'closed' AND t.closed IS NOT NULL"),
            'first_closed' => null,
            'last_closed' => null,
        );

        try {
            $res = db_query('SELECT s.state, COUNT(*) AS n FROM '.TICKET_TABLE.' t'
                .' LEFT JOIN '.TICKET_STATUS_TABLE.' s ON (s.id = t.status_id)'
                .' GROUP BY s.state ORDER BY n DESC', false);
            while ($res && ($row = db_fetch_array($res)))
                $stats['states'][$row['state'] ?: '(sin estado)'] = (int) $row['n'];
        }
        catch (Throwable $e) {
            $stats['states_error'] = $e->getMessage();
        }

        try {
            $res = db_query('SELECT MIN(closed) AS a, MAX(closed) AS b FROM '
                .TICKET_TABLE.' WHERE closed IS NOT NULL', false);
            if ($res && ($row = db_fetch_array($res))) {
                $stats['first_closed'] = $row['a'];
                $stats['last_closed'] = $row['b'];
            }
        }
        catch (Throwable $e) {
            // Sin fechas se sigue: el resto del informe vale igual.
        }

        return $stats;
    }

    /** Cuenta de tickets con `closed` (o `created`) dentro de cada periodo. */
    function periodStats() {
        global $cfg;

        $out = array();
        $tz = new DateTimeZone($cfg->getTimezone());
        $dbtz = new DateTimeZone($cfg->getDbTimezone());

        foreach (self::periods() as $period => $label) {
            $range = Misc::date_range($period, Misc::gmtime('now'), $tz);
            if (!$range)
                continue;

            // Igual que `DateTimeField::getSearchQ('period')`: los límites se
            // calculan en la zona del agente y se pasan a la de la base.
            $start = $range->start->setTimezone($dbtz)->format('Y-m-d H:i:s');
            $end = $range->end->setTimezone($dbtz)->format('Y-m-d H:i:s');

            $out[$period] = array(
                'label' => $label,
                'start' => $start,
                'end' => $end,
                'closed' => $this->scalar(sprintf(
                    'SELECT COUNT(*) FROM %s WHERE closed BETWEEN %s AND %s',
                    TICKET_TABLE, db_input($start), db_input($end))),
                'created' => $this->scalar(sprintf(
                    'SELECT COUNT(*) FROM %s WHERE created BETWEEN %s AND %s',
                    TICKET_TABLE, db_input($start), db_input($end))),
            );
        }

        return $out;
    }

    function scalar($sql) {
        try {
            if (!($res = db_query($sql, false)))
                return null;
            $row = db_fetch_row($res);
            return ($row === null || $row === false || !isset($row[0])) ? null : (int) $row[0];
        }
        catch (Throwable $e) {
            return null;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Veredicto                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Decide si los números cuadran. Es la parte que hay que leer: el resto
     * del informe es el contexto que permite entender el veredicto.
     */
    function verdict($report) {
        $query = $report['query'];
        $db = $report['database'];
        $notes = array();
        $status = 'ok';

        $error = function($text) use (&$status, &$notes) {
            $status = 'error';
            $notes[] = $text;
        };
        $warn = function($text) use (&$status, &$notes) {
            if ($status == 'ok')
                $status = 'warning';
            $notes[] = $text;
        };

        if ($query['error']) {
            $error('La consulta de la lista no se pudo ejecutar: '.$query['error']);
        }
        else {
            foreach ($query['issues'] as $issue)
                $error('El SQL compilado está roto: '.$issue.'.');

            if (is_int($query['rows']) && is_int($query['count'])) {
                if ($query['count'] > 0 && $query['rows'] === 0)
                    $error('La consulta cuenta '.$query['count']
                        .' tickets pero no devuelve ninguna fila.');
                elseif ($query['count'] === 0 && $query['rows'] > 0)
                    $error('La consulta devuelve '.$query['rows']
                        .' filas pero se cuenta 0.');
            }

            // Si ya sabemos *por qué* no salen filas, no se acusa al SQL
            $explained = false;

            // Dónde se pierden las filas (criterio, visibilidad, hijos)
            $conditions = $query['conditions'];
            if ($conditions['culprit'])
                $error('La consulta se queda sin filas en el tramo «'
                    .$conditions['culprit'].'»: el resto de la cola no llega a'
                    .' ejecutarse. Mire el SQL de ese tramo.');

            // Qué columna deja el listado a cero
            $baseline = $query['columns']['baseline'];
            if (is_int($baseline) && $baseline > 0) {
                $culprits = array();
                foreach ($query['columns']['columns'] as $column)
                    if ($column['count'] === 0)
                        $culprits[] = sprintf('%s (%s)', $column['name'],
                            $column['id']);
                if ($culprits) {
                    $explained = true;
                    $error('El criterio encuentra '.$baseline.' tickets, pero al'
                        .' aplicar '.count($culprits).' columna'
                        .(count($culprits) == 1 ? '' : 's')
                        .' la consulta se queda sin filas: '
                        .implode(', ', $culprits)
                        .'. El JOIN que mete esa columna es interno.');
                }
            }

            // Recuentos. Sólo tienen mérito propio si no hay una causa
            // conocida de las filas perdidas: la cola cuenta por un camino
            // (sin columnas) y lista por otro (con columnas), así que la
            // diferencia es el síntoma, no un segundo fallo.
            if (!$explained) {
                if (is_int($query['count']) && is_int($query['queue_total'])
                        && $query['count'] !== $query['queue_total'])
                    $warn('El recuento real ('.$query['count'].') no coincide con'
                        .' el de la cola ('.$query['queue_total']
                        .'): `SavedQueue::getTotal()` cuenta otro conjunto que el'
                        .' que lista la página.');
            }
            else {
                foreach (array(
                    array('getCount()', 'queue_count'),
                    array('getTotal()', 'queue_total'),
                ) as $pair) {
                    $value = $query[$pair[1]];
                    if (is_int($value) && is_int($query['count'])
                            && $value !== $query['count'])
                        $warn('`'.$pair[0].'` dice '.$value.' y la consulta '
                            .$query['count'].': la cola cuenta sin columnas y'
                            .' lista con ellas, así que el número de la'
                            .' navegación y el del listado no pueden cuadrar'
                            .' mientras la causa anterior siga ahí.');
                }
            }

            // Tickets fuera de los departamentos del agente
            $visibility = $query['visibility'];
            if (is_int($visibility['outside']) && $visibility['outside'] > 0) {
                $text = $visibility['outside'].' tickets están en departamentos'
                    .' que el agente no tiene asignados'
                    . ($visibility['outside_names']
                        ? ' ('.implode(', ', $visibility['outside_names']).')'
                        : '')
                    .': las colas no los listan. Se arregla en Admin → Agents →'
                    .' el agente → Departments, o revisando el departamento del'
                    .' ticket.';
                $empties = (is_int($baseline) && $baseline === 0);
                if ($empties) {
                    $error($text . ' Es la causa de que la consulta salga vacía.');
                    $explained = true;
                }
                else
                    $warn($text);
            }

            // Tablas materializadas a medias: es la causa habitual
            $materialized = $query['materialized'];
            if (is_int($materialized['cdata_missing'])
                    && $materialized['cdata_missing'] > 0) {
                $explained = true;
                $error($materialized['cdata_missing'].' tickets no tienen fila en'
                    .' `'.TICKET_CDATA_TABLE.'`: la columna «Subject» los deja'
                    .' fuera porque su JOIN es interno. Reconstruya la tabla con'
                    .' `php manage.php db --rebuild-views` (o'
                    .' `make db-rebuild-views`).');
            }

            if (is_int($materialized['tickets_without_entry'])
                    && $materialized['tickets_without_entry'] > 0)
                $error($materialized['tickets_without_entry'].' tickets no tienen'
                    .' fila en `'.FORM_ENTRY_TABLE."` (tipo T): sus datos de"
                    .' formulario no existen, así que tampoco pueden estar en'
                    .' `'.TICKET_CDATA_TABLE.'`.');

            if (is_int($query['count']) && is_int($db['total'])) {
                $wanted = $this->wantedByCriteria($report);
                if (!$explained && $query['count'] === 0 && $wanted > 0)
                    $error('La consulta devuelve 0 tickets, pero la base tiene '
                        .$wanted.' que cumplen el criterio de la cola: el fallo'
                        .' está en el SQL, no en el dato.');
            }

            if (is_int($query['count']) && is_int($db['closed_not_null'])) {
                $isClosed = $this->hasClosedStateCriteria($report);
                if (!$explained && $isClosed && $query['count'] === 0
                        && $db['closed_not_null'] > 0)
                    $error('La cola busca tickets cerrados y hay '
                        .$db['closed_not_null'].' con fecha de cierre, pero la'
                        .' consulta no devuelve ninguno.');
            }
        }

        if (is_int($db['state_closed_but_no_date']) && $db['state_closed_but_no_date'] > 0)
            $warn($db['state_closed_but_no_date'].' tickets tienen estado cerrado'
                .' pero `closed` vacío: no saldrán en los filtros por fecha.');

        if (is_int($db['date_but_not_closed']) && $db['date_but_not_closed'] > 0)
            $warn($db['date_but_not_closed'].' tickets tienen fecha de cierre pero'
                .' su estado no es «closed»: los filtros por estado y por fecha'
                .' discrepan.');

        if (!$notes) {
            $rows = is_int($query['rows']) ? $query['rows'] : '?';
            $notes[] = 'Los números cuadran: la consulta devuelve '.$rows
                .' filas' . (is_int($query['count']) ? ' de '.$query['count'] : '')
                .' y la base tiene '.(is_int($db['total']) ? $db['total'] : '?')
                .' tickets.';
        }

        return array('status' => $status, 'notes' => $notes);
    }

    /** ¿El criterio pide el estado «closed»? (sirve para el veredicto). */
    function hasClosedStateCriteria($report) {
        foreach ($report['queue']['criteria'] as $text)
            if (stripos($text, 'status__state') !== false
                    && stripos($text, 'closed') !== false)
                return true;
        return false;
    }

    /**
     * Cuántos tickets de la base cumplen el criterio de estado de la cola,
     * contado a mano. Es el número contra el que se compara la consulta del
     * ORM: si aquí sale 23 y la consulta 0, el problema es el SQL.
     */
    function wantedByCriteria($report) {
        $states = array();
        foreach (($report['database']['states'] ?: array()) as $state => $n)
            $states[strtolower($state)] = $n;

        if (!$this->hasClosedStateCriteria($report))
            return is_int($report['database']['total']) ? $report['database']['total'] : 0;

        return isset($states['closed']) ? (int) $states['closed'] : 0;
    }

    /* ------------------------------------------------------------------ */
    /* Salida                                                              */
    /* ------------------------------------------------------------------ */

    function printReport($report) {
        $queue = $report['queue'];

        $this->out('');
        $this->heading(sprintf('Cola %s — «%s»', $queue['id'], $queue['title']));
        $this->pair('Tipo', $queue['flags_text']);
        $this->pair('Nombre completo', $queue['full_name']);
        $this->pair('Cola padre', $queue['parent'] ?: '— (es una cola raíz)');
        $this->pair('Columnas', $queue['columns']
            ? implode(' · ', $queue['columns'])
            : ($queue['columns_error'] ?: '(ninguna)'));
        $this->pair('Criterio', $queue['criteria']
            ? implode("\n".str_repeat(' ', 20), $queue['criteria'])
            : '(ninguno: lista todos los tickets)');
        $agent = $report['agent'];
        $this->pair('Agente', sprintf('%s (%s)%s', $agent['name'], $agent['username'],
            $agent['admin'] ? ' · administrador' : ''));
        $this->pair('Filtro rápido', $report['filter'] !== '' ? $report['filter'] : '(ninguno)');

        $query = $report['query'];
        $this->heading('Consulta de la lista');
        if ($query['error'] && !$query['sql']) {
            $this->out('  No se pudo compilar: ' . $query['error']);
        }
        else {
            $shape = $query['shape'];
            $this->pair('Forma', sprintf('%d SELECT · %d JOIN (%d LEFT) · %s%s%s%s',
                $shape['select'], $shape['joins'], $shape['left_joins'],
                $shape['where'] ? 'WHERE · ' : '',
                $shape['group_by'] ? 'GROUP BY · ' : '',
                $shape['order_by'] ? 'ORDER BY · ' : '',
                $shape['limit'] ? 'LIMIT '.$shape['limit'] : 'sin LIMIT'));
            $this->pair('Tablas', implode(', ', $shape['tables']) ?: '—');
            $this->pair('Parámetros', $this->formatParams($query['params']));
            $this->pair('SQL', $this->showSql || $this->json
                ? "\n".$this->indent(self::bindParams($query['sql'], $query['params']), 4)
                : $this->oneline($query['sql']));
        }

        $this->heading('Números');
        $this->table(array('Qué', 'Valor'), array(
            array('Filas que devuelve la consulta (LIMIT '.$this->limit.')',
                $this->number($query['rows'])),
            array('Total de la misma consulta (sin LIMIT)',
                $this->number($query['count'])),
            array('`getCount()` de la cola (con caché de 5 min)',
                $this->number($query['queue_count'])),
            array('`getTotal()` de la cola (sin caché)',
                $this->number($query['queue_total'])),
        ));

        // Por tramos: criterio, visibilidad y filtro de hijos. Es lo primero
        // que hay que mirar cuando *sin columnas* tampoco salen filas.
        $conditions = $query['conditions'];
        $this->heading('¿Dónde se pierden las filas?');
        $rows = array();
        foreach ($conditions['steps'] as $step)
            $rows[] = array($step['label'], $this->number($step['count']));
        $this->table(array('Tramo de la consulta', 'Tickets'), $rows);
        if ($conditions['culprit']) {
            $this->out('  Las filas desaparecen en: ' . $conditions['culprit']);
            if (strpos($conditions['culprit'], 'hijos') !== false) {
                $children = $conditions['children'];
                $this->table(array('Rama del filtro de hijos', 'Tickets'), array(
                    array('Sin ticket padre (`ticket_pid IS NULL`)',
                        $this->number($children['no_parent'])),
                    array('`ticket_pid = 0` (no es NULL: nunca pasa el filtro)',
                        $this->number($children['zero_parent'])),
                    array('Marcados como enlazados (`flags & '
                        .$children['flag'].' <> 0`)',
                        $this->number($children['linked'])),
                ));
                if (is_int($children['no_parent']) && $children['no_parent'] === 0
                        && is_int($children['linked']) && $children['linked'] === 0)
                    $this->out('  Ningún ticket cumple ninguna de las dos ramas: la'
                        .' cola los cuenta y no los puede listar.');
            }
        }

        // El SQL del tramo final, con los parámetros ya puestos: es lo que se
        // puede pegar en un cliente de MariaDB para verlo con los ojos.
        if ($conditions['sql'] && ($this->showSql || $this->json
                || !$conditions['culprit'] && $conditions['steps'][3]['count'] === 0))
            $this->pair('SQL del último tramo', "\n" . $this->indent(
                self::bindParams($conditions['sql'], $conditions['params']), 4));

        // Visibilidad: el tramo que más veces explica «yo no veo esos tickets»
        $visibility = $query['visibility'];
        $this->heading('Departamentos y visibilidad del agente');
        if ($visibility['error'])
            $this->out('  No se pudo leer del todo: ' . $this->oneline(
                $visibility['error']));
        else {
            $rows = array();
            foreach ($visibility['by_dept'] as $id => $dept)
                $rows[] = array(sprintf('%d — %s', $id, $dept['name']),
                    $this->number($dept['tickets']),
                    in_array($id, $visibility['staff_depts']) ? 'sí' : 'NO');
            $this->table(array('Departamento', 'Tickets', '¿Lo ve el agente?'),
                $rows);
            $this->pair('Departamentos del agente',
                $visibility['staff_depts']
                    ? implode(', ', $visibility['staff_depts']) : '(ninguno)');
            $this->pair('Tickets fuera de su alcance',
                $this->number($visibility['outside'])
                    . ($visibility['outside_names']
                        ? ' · ' . implode(', ', $visibility['outside_names'])
                        : ''));
        }

        // Desglose por columna: es lo que dice *qué* columna recorta el listado
        $audit = $query['columns'];
        $this->heading('¿Qué columna se come las filas?');
        $rows = array(array('Sólo el criterio (sin columnas)',
            $this->number($audit['baseline'])));
        foreach ($audit['columns'] as $column) {
            $rows[] = array(sprintf('%s (%s)', $column['name'], $column['id']),
                $column['error'] ? 'error: ' . $this->oneline($column['error'])
                    : $this->number($column['count']));
        }
        $this->table(array('Columna', 'Tickets que quedan'), $rows);
        if ($audit['baseline_error'])
            $this->out('  Sin columnas tampoco se pudo contar: '
                . $audit['baseline_error']);

        $materialized = $query['materialized'];
        if (array_filter($materialized, function($v) { return $v !== null; })) {
            $this->heading('Tablas materializadas de los formularios');
            $this->table(array('Qué', 'Valor'), array(
                array('Filas en `'.TICKET_CDATA_TABLE.'`',
                    $this->number($materialized['cdata_rows'])),
                array('Tickets **sin** fila en `'.TICKET_CDATA_TABLE.'`',
                    $this->number($materialized['cdata_missing'])),
                array('Tickets con formulario (`form_entry`, tipo T)',
                    $this->number($materialized['entries'])),
                array('Tickets **sin** formulario',
                    $this->number($materialized['tickets_without_entry'])),
            ));
        }

        $db = $report['database'];
        $this->heading('Datos en la base');
        if ($this->serverLine())
            $this->pair('Servidor', $this->serverLine());
        $rows = array(
            array('Tickets en total', $this->number($db['total'])),
            array('Con fecha de cierre (`closed`)', $this->number($db['closed_not_null'])),
            array('Estado `closed` sin fecha de cierre',
                $this->number($db['state_closed_but_no_date'])),
            array('Fecha de cierre pero estado distinto de `closed`',
                $this->number($db['date_but_not_closed'])),
        );
        if ($db['first_closed'])
            $rows[] = array('Primer / último cierre',
                $db['first_closed'].'  →  '.$db['last_closed']);
        foreach (($db['states'] ?: array()) as $state => $n)
            $rows[] = array('Estado '.$state, $n);
        $this->table(array('Qué', 'Tickets'), $rows);

        if ($this->getOption('periods') || $this->mentionsPeriod($report)) {
            $this->heading('Periodos de fecha (columna `closed` y `created`)');
            $rows = array();
            foreach ($this->periodStats() as $stats)
                $rows[] = array($stats['label'],
                    $stats['start'].' → '.$stats['end'],
                    $this->number($stats['closed']),
                    $this->number($stats['created']));
            $this->table(array('Periodo', 'Rango (hora de la base)', 'closed', 'created'), $rows);
        }

        $this->heading('Veredicto');
        $verdict = $report['verdict'];
        $mark = array('ok' => 'OK', 'warning' => 'AVISO', 'error' => 'FALLO');
        foreach ($verdict['notes'] as $note)
            $this->out('  [' . $mark[$verdict['status']] . '] ' . $this->wrap($note));
    }

    function mentionsPeriod($report) {
        foreach ($report['queue']['criteria'] as $text)
            if (stripos($text, 'period') !== false)
                return true;
        return false;
    }

    function number($value) {
        return $value === null ? '?' : (string) $value;
    }

    function formatParams($params) {
        if (!$params)
            return '(ninguno)';

        $out = array();
        foreach ($params as $i => $value)
            $out[] = ':'.($i + 1).' = '.self::scalarText(
                $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value);

        return implode(' · ', $out);
    }

    function oneline($sql) {
        $sql = preg_replace('/\s+/', ' ', (string) $sql);
        return strlen($sql) > 160 ? substr($sql, 0, 157).'…' : $sql;
    }

    function indent($text, $spaces) {
        $pad = str_repeat(' ', $spaces);
        return $pad . str_replace("\n", "\n".$pad, rtrim($text));
    }

    function wrap($text, $width=78) {
        return wordwrap($text, $width, "\n".str_repeat(' ', 9));
    }

    function heading($text) {
        $this->out('');
        $this->out($text);
        $this->out(str_repeat('-', self::len($text)));
    }

    /**
     * Longitud en caracteres, no en bytes: los acentos y las rayas ocupan más
     * de un byte en UTF-8 y con `strlen()` las columnas de la tabla quedan
     * descuadradas. Se usa `mb_strlen()` si está, y si no (PHP sin mbstring)
     * se cuentan los caracteres con una expresión regular.
     */
    static function len($text) {
        $text = (string) $text;
        if (function_exists('mb_strlen'))
            return mb_strlen($text, 'UTF-8');
        return preg_match_all('/./us', $text) ?: strlen($text);
    }

    function pair($label, $value) {
        $label .= ':';
        $pad = max(1, 19 - self::len($label));
        $this->out('  ' . $label . str_repeat(' ', $pad) . $value);
    }

    /** Tabla de dos o más columnas, con el ancho de cada una calculado. */
    function table($headers, $rows) {
        $widths = array();
        foreach ($headers as $i => $header)
            $widths[$i] = self::len($header);
        foreach ($rows as $row)
            foreach (array_values($row) as $i => $cell)
                $widths[$i] = max($widths[$i], self::len((string) $cell));

        $line = function($cells) use ($widths) {
            $out = array();
            foreach (array_values($cells) as $i => $cell)
                $out[] = (string) $cell . str_repeat(' ', max(0, $widths[$i] - self::len((string) $cell)));
            return '  ' . rtrim(implode('  ', $out));
        };

        $this->out($line($headers));
        $this->out('  ' . implode('  ', array_map(function($w) {
            return str_repeat('-', $w);
        }, $widths)));
        foreach ($rows as $row)
            $this->out($line($row));
    }

    function out($text) {
        $this->stdout->write($text . "\n");
    }

    function emitJson($data) {
        $this->out(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES));
    }
}

Module::register('queue', 'QueueCheck');
