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

require_once INCLUDE_DIR . 'cli/modules/queue.report.php';

class QueueCheck extends Module {
    use QueueReport;

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
        'hydrate' => array('', '--hydrate', 'action' => 'store_true',
            'help' => 'Recorre las filas como lo hace la página (un modelo por '
                . 'fila): distingue «la base no devuelve filas» de «la base las '
                . 'devuelve y la página no las pinta»'),
        'periods' => array('', '--periods', 'action' => 'store_true',
            'help' => 'Compara los seis periodos de fecha con los tickets reales'),
        'nav' => array('', '--nav', 'action' => 'store_true',
            'help' => 'Que colas salen en la barra de tickets.php y por que las demas no'),
        'trace' => array('', '--trace', 'action' => 'store_true',
            'help' => 'Con --nav: enseña paso a paso donde se pierden las colas'),
        'json' => array('', '--json', 'action' => 'store_true',
            'help' => 'Salida JSON, para engancharla a otra herramienta'),
    );

    /** Filas que se piden, como la página. */
    var $limit = 25;
    var $json = false;
    var $showSql = false;
    /** Recorrer las filas como la página, para ver si hidratan (--hydrate). */
    var $hydrate = false;
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
        $this->hydrate = (bool) $this->getOption('hydrate');

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

        // `--nav` se deduce de las banderas y no necesita agente; los demas
        // modos si (el criterio de una cola se evalua como un agente).
        if (!$this->staff && !$this->getOption('nav')) {
            $c = $this->staffCounts();
            $this->err('No hay agentes activos en esta instalación.');
            $this->err('  Filas en `'.STAFF_TABLE.'`: '.$c['filas']
                . '; activos: '.$c['activos'].'; admins: '.$c['admin']);
            $this->err('  Créelos desde el panel (`scp/`) o con'
                .' `php manage.php agent --help`.');
            return 2;
        }

        if ($this->staff)
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

        if (!$queues && $this->getOption('nav')) {
            // Sin colas no hay nada que diagnosticar, pero es justo la
            // respuesta: la barra solo puede mostrar colas, y no hay.
            $this->out('No hay ninguna cola creada en esta instalación.');
            $this->out('  Filas en `' . QUEUE_TABLE . '`: '
                . $this->connectionCount(QUEUE_TABLE));
            $this->err('Por eso la barra de tickets.php solo muestra'
                .' "Search" y "New Ticket": las busquedas guardadas van en'
                .' "Search" y las colas en la barra.');
            $this->err('Cree una en `scp/queues.php` (boton "Add New Queue")'
                .' o marque "Shows up in queue navigation" en la que quiera ver.');
            return 1;
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
        if ($this->getOption('nav')) {
            $this->navDiagnosis($queues);
            return $this->problems ? 1 : 0;
        }

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
            if ($staff = $query->first())
                return $staff;
        }
        else {
            $admins = Staff::objects()->filter(self::adminStaffFilter())
                ->order_by('staff_id');
            if ($admin = $admins->first())
                return $admin;

            if ($staff = Staff::objects()->filter(self::activeStaffFilter())
                    ->order_by('staff_id')->first())
                return $staff;
        }

        // Ultimo recurso: mirar la tabla a pelo. Si el ORM se queda en blanco
        // por un filtro que no encaja con esta instalacion, el comando diria
        // «no hay agentes» con la tabla llena, que es peor que no decir nada.
        try {
            $res = db_query('SELECT staff_id FROM ' . STAFF_TABLE
                . ($who && ctype_digit((string) $who)
                    ? ' WHERE staff_id = ' . (int) $who
                    : ($who ? '' : ' WHERE isactive = 1'))
                . ' ORDER BY staff_id LIMIT 1', false);
            if ($res && ($row = db_fetch_array($res))) {
                db_free_result($res);
                return Staff::lookup((int) $row['staff_id']);
            }
        }
        catch (Throwable $e) {
            // Se informa arriba, con los recuentos.
        }

        return null;
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

    /**
     * Cuantos agentes hay, para poder decir *por que* no se resolvio ninguno.
     *
     * Si `activos` es 0 y `filas` no, el problema es el filtro o la conexion;
     * si las dos son 0, la tabla esta vacia o se esta leyendo otra base.
     */
    /**
     * El `--as` pedido, en texto.
     *
     * `getOption()` lo trae `class.cli.php`; las pruebas cargan un `Module`
     * minimo sin consola, asi que se pregunta antes de llamarlo. En la CLI de
     * verdad siempre existe.
     */
    function staffOption() {
        return method_exists($this, 'getOption')
            ? trim((string) $this->getOption('as')) : '';
    }

    function staffCounts() {
        $out = array();
        $out['filas'] = $this->connectionCount(STAFF_TABLE);
        foreach (array('activos' => self::activeStaffFilter(),
                'admin' => self::adminStaffFilter()) as $k => $f) {
            try {
                $out[$k] = (int) Staff::objects()->filter($f)->count();
            }
            catch (Throwable $e) {
                $out[$k] = '?';
            }
        }
        return $out;
    }

    /** Agentes activos y administradores (columna `isadmin`). */
    static function adminStaffFilter() {
        return array('isactive' => 1, 'isadmin' => 1);
    }

    function staffInfo() {
        if (!$this->staff)
            return array('id' => null, 'username' => null, 'name' => null,
                'admin' => false);
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

    /**
     * Que colas salen en la barra de `/scp/tickets.php`, y por que las demas no.
     *
     * La barra la arma `CustomQueue::getHierarchicalQueues($staff, 0, true)`:
     * en el primer nivel solo deja pasar las que **son cola** (`FLAG_QUEUE`),
     * no estan deshabilitadas y no cuelgan de otra. Una cola sin ese bit
     * existe, se ve en `queue --list`, y sin embargo no aparece en la barra:
     * el sintoma es "desaparecieron mis colas" y no hay nada en el log.
     *
     * El diagnostico recorre **todas** las filas de la tabla y dice, para cada
     * una, si entra y el motivo cuando no. Lo deduce de las banderas, no de
     * `getHierarchicalQueues()`, para que funcione aunque no se pueda resolver
     * el agente (sin agente esa funcion no se puede ni llamar: pide un
     * `Staff`). Cuando si hay agente, compara con la barra real y avisa si no
     * coinciden.
     */
    function navDiagnosis($queues) {
        $staffId = $this->staff ? (int) $this->staff->getId() : null;

        // Lo que la barra trae de verdad. Solo como contraste: si falla, el
        // diagnostico por banderas sigue valiendo.
        $real = null;
        $realError = null;
        if ($this->staff) {
            try {
                $real = array();
                foreach (CustomQueue::getHierarchicalQueues($this->staff, 0, true) as $_) {
                    list($queue,) = $_;
                    $real[] = (int) $queue->getId();
                }
            }
            catch (Throwable $e) {
                $realError = get_class($e) . ': ' . $e->getMessage()
                    . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            }
        }

        $rows = array();
        $inNav = 0;
        foreach ($queues as $queue) {
            $id = (int) $queue->getId();
            $flags = (int) $queue->flags;
            list($entra, $reasons) = self::navVerdict($flags, $queue->parent_id);

            $rows[] = array(
                'id' => $id,
                'title' => $queue->getName(),
                'flags' => $this->flagText($flags),
                'in_nav' => $entra,
                'real' => $real === null ? null : in_array($id, $real),
                'reason' => implode('; ', $reasons),
            );
            if ($entra)
                $inNav++;
        }

        if ($this->json) {
            $this->emitJson(array(
                'agent' => $this->staffInfo(),
                'nav' => $real,
                'nav_error' => $realError,
                'queues' => $rows,
            ));
            return;
        }

        $this->out(sprintf('Colas en la barra de tickets.php%s: %d de %d',
            $this->staff ? ' para '.$this->staff->getName() : '',
            $inNav, count($rows)));
        $this->out('');

        $this->out(sprintf('  %-4s %-30s %-30s %-6s %s',
            'ID', 'Cola', 'Banderas', 'Barra', 'Motivo'));
        foreach ($rows as $row) {
            $this->out(sprintf('  %-4d %-30s %-30s %-6s %s',
                $row['id'],
                self::cut($row['title'], 30),
                self::cut($row['flags'], 30),
                $row['in_nav'] ? 'si' : 'no',
                $row['reason']));
        }

        $this->out('');
        if ($inNav == 0) {
            $this->problems++;
            $this->err('Ninguna cola lleva el bit de cola: la barra de'
                .' tickets.php se queda vacia.');
            $this->err('Se activa en la ficha de la cola, en "Queue Visibility"'
                .' -> "Shows up in queue navigation".');
            $this->err('Ojo: "Open", "My Tickets" y "Closed" del instalador ya'
                .' lo traen; si tampoco salen, el problema es otro.');
        }

        // Contraste con la barra real.
        if ($realError) {
            // La funcion de la barra fallo: el motivo es lo mas importante de
            // todo el informe, asi que va siempre, aunque la lista quedara a
            // medias.
            $this->problems++;
            $this->err('getHierarchicalQueues() fallo: ' . $realError);
            $this->err('La tabla de arriba es solo por banderas.');
        }
        elseif ($real === null) {
            $this->err('Sin agente resuelto: la tabla es solo por banderas.'
                .' Prueba `--as=<usuario>`.');
        }
        else {
            $diff = array();
            foreach ($rows as $row) {
                if ($row['in_nav'] !== $row['real'])
                    $diff[] = $row['id'];
            }
            if ($diff) {
                $this->problems++;
                $this->err('La barra real no coincide con las banderas en: '
                    . implode(', ', $diff));
                $this->err('Ahi manda tambien la visibilidad del agente'
                    .' (colas privadas de otro agente) y la jerarquia.');
                $this->navTrace();
            }
            else {
                $this->out('La barra real coincide con lo que dicen las'
                    .' banderas: ' . count($real) . ' cola(s).');
            }
        }

        // Si no hay ninguna, el motivo mas probable es el agente: se dan los
        // numeros para no tener que adivinar.
        if (!$this->staff) {
            $this->problems++;
            $c = $this->staffCounts();
            $this->err('No hay agente con el que calcular la barra.');
            $this->err('  Filas en `'.STAFF_TABLE.'`: '.$c['filas']
                . '; activos: '.$c['activos'].'; admins: '.$c['admin']);
            if (($as = $this->staffOption()) !== '')
                $this->err('  Se pidio --as='.$as.' y no hay ningun agente'
                    .' activo con ese usuario o id.');
            else
                $this->err('  Usa `--as=<usuario>`'
                    .' (o `make queue-nav AS=dixon`).');
        }
    }

    /**
     * Donde se pierden las colas, paso a paso.
     *
     * Se imprime sola cuando la barra real no coincide con las banderas, y
     * con `--trace` cuando se quiera ver igualmente. Cada tramo va en su
     * `try`: si uno falla, se dice y se sigue con el siguiente, que es lo
     * que hace falta cuando el problema es justo que algo revienta.
     */
    function navTrace() {
        if (!function_exists('db_query'))
            return;

        $staffId = $this->staff ? (int) $this->staff->getId() : 0;

        $this->out('');
        $this->out('Traza (barra de la cola ' . QUEUE_TABLE . '):');
        $this->out('  agente: id=' . $staffId . ' '
            . ($this->staff ? $this->staff->getUserName() : '(ninguno)'));

        // a) La tabla tal cual, sin ORM ni modelos.
        $this->out('  a) ' . QUEUE_TABLE . ' leida a pelo:');
        try {
            $res = db_query('SELECT id, parent_id, flags, staff_id, title FROM '
                . QUEUE_TABLE . ' ORDER BY parent_id, sort, id', false);
            if (!$res) {
                $this->out('     la consulta fallo: ' . db_error());
            }
            else {
                $n = 0;
                while ($row = db_fetch_array($res)) {
                    $n++;
                    $this->out(sprintf('     id=%-3s parent=%-3s flags=%-3s'
                        . ' staff=%-3s %s', $row['id'], $row['parent_id'],
                        $row['flags'], $row['staff_id'], $row['title']));
                }
                db_free_result($res);
                $this->out('     ' . $n . ' fila(s)');
            }
        }
        catch (Throwable $e) {
            $this->out('     fallo: ' . $e->getMessage());
        }

        // b) La consulta de la barra, tramo a tramo.
        $this->out('  b) la consulta de la barra, por tramos:');
        $this->traceStep('todas las colas', function() {
            return CustomQueue::objects()->count();
        });
        $this->traceStep('con el filtro (publica | cola | propia)', function() use ($staffId) {
            return CustomQueue::objects()->filter(Q::any(array(
                'flags__hasbit' => CustomQueue::FLAG_PUBLIC,
                'flags__hasbit' => CustomQueue::FLAG_QUEUE,
                'staff_id' => $staffId,
            )))->count();
        });
        $this->traceStep('sin las deshabilitadas', function() use ($staffId) {
            return CustomQueue::objects()
                ->filter(Q::any(array(
                    'flags__hasbit' => CustomQueue::FLAG_PUBLIC,
                    'flags__hasbit' => CustomQueue::FLAG_QUEUE,
                    'staff_id' => $staffId,
                )))
                ->exclude(array('flags__hasbit' => CustomQueue::FLAG_DISABLED))
                ->count();
        });
        $this->traceStep('con annotate y order_by, leidas (asArray)', function() use ($staffId) {
            $all = CustomQueue::objects()
                ->annotate(array('_sort' => SqlCase::N()
                    ->when(array('sort' => 0), 999)
                    ->otherwise(new SqlField('sort'))))
                ->filter(Q::any(array(
                    'flags__hasbit' => CustomQueue::FLAG_PUBLIC,
                    'flags__hasbit' => CustomQueue::FLAG_QUEUE,
                    'staff_id' => $staffId,
                )))
                ->exclude(array('flags__hasbit' => CustomQueue::FLAG_DISABLED))
                ->order_by('parent_id', '_sort', 'title')
                ->asArray();

            // La misma cuenta que hace getHierarchicalQueues, paso a paso,
            // para ver en cual se cae.
            $raiz = $cola = 0;
            $ids = array();
            foreach ($all as $q) {
                if ((int) $q->parent_id === 0)
                    $raiz++;
                if ((int) $q->parent_id === 0 && $q->isAQueue()) {
                    $cola++;
                    $ids[] = $q->getId();
                }
            }

            $detalle = sprintf(' -> en la raiz: %d; de esas son cola: %d%s',
                $raiz, $cola, $ids ? ' ['.implode(', ', $ids).']' : '');
            if ($all) {
                $q = $all[0];
                $detalle .= sprintf('; 1a fila %s id=%s parent_id=%s (%s)'
                    . ' flags=%s (%s)', get_class($q),
                    var_export($q->getId(), true),
                    var_export($q->parent_id, true), gettype($q->parent_id),
                    var_export($q->flags, true), gettype($q->flags));
            }
            return count($all) . $detalle;
        });

        // c) La funcion que usa la barra.
        $this->out('  c) CustomQueue::getHierarchicalQueues():');
        if (!$this->staff) {
            $this->out('     no se puede llamar sin agente');
        }
        else {
            try {
                $ids = array();
                foreach (CustomQueue::getHierarchicalQueues($this->staff, 0, true) as $_) {
                    list($q,) = $_;
                    $ids[] = $q->getId();
                }
                $this->out('     ' . ($ids ? 'devuelve: ' . implode(', ', $ids)
                    : 'devuelve una lista vacia'));
            }
            catch (Throwable $e) {
                $this->out('     fallo ' . get_class($e) . ': ' . $e->getMessage());
                $this->out('     en ' . $e->getFile() . ':' . $e->getLine());
            }
        }
    }

    /** Un tramo de la traza: la etiqueta y lo que devuelva o el fallo. */
    function traceStep($label, callable $fn) {
        try {
            $this->out(sprintf('     %-44s %s', $label, $fn()));
        }
        catch (Throwable $e) {
            $this->out(sprintf('     %-44s FALLO %s: %s', $label,
                get_class($e), $e->getMessage()));
            $this->out('     ' . str_repeat(' ', 44) . basename($e->getFile())
                . ':' . $e->getLine());
        }
    }

    /**
     * Veredicto por banderas: ¿esta fila sale en la barra?
     *
     * Es la regla de `CustomQueue::getHierarchicalQueues($staff, 0, true)`
     * escrita aparte, para poder probarla sin base de datos. Devuelve
     * `array($entra, $motivos)`.
     */
    static function navVerdict($flags, $parentId) {
        $reasons = array();
        $flags = (int) $flags;
        if ($flags & CustomQueue::FLAG_DISABLED)
            $reasons[] = 'esta deshabilitada (FLAG_DISABLED)';
        if (!($flags & CustomQueue::FLAG_QUEUE))
            $reasons[] = 'sin bit de cola (FLAG_QUEUE): es una busqueda'
                . ' guardada, no una cola';
        if ($parentId)
            $reasons[] = 'cuelga de la cola '.$parentId.' y va en su'
                . ' desplegable';
        return array(!$reasons, $reasons);
    }

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
            'hydrated' => null,
            'hydrated_null' => null,
            'hydrated_numbers' => array(),
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

        // Hidratación: lo que hace la página. `rows` dice si la base devuelve
        // filas; esto dice si el ORM las convierte en tickets. Es el hueco por
        // el que se pierden las filas sin un solo error visible, así que se
        // mide aparte (una por una, como el `foreach` de la plantilla).
        if ($this->hydrate) {
            try {
                $built = 0;
                $nullIds = 0;
                $numbers = array();

                $query = $this->buildListQuery($queue);
                $query->limit($this->limit);

                foreach ($query as $ticket) {
                    $built++;
                    if (!$ticket['ticket_id'])
                        $nullIds++;
                    elseif (count($numbers) < 5)
                        $numbers[] = $ticket['number'];
                }

                $result['hydrated'] = $built;
                $result['hydrated_null'] = $nullIds;
                $result['hydrated_numbers'] = $numbers;
            }
            catch (Throwable $e) {
                $result['hydrated'] = null;
                $result['error'] = 'al hidratar: ' . $e->getMessage();
            }
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
    /** Corta sin depender de mbstring (la CLI puede venir con la extension
     *  apagada; `substr` partiria un acento por la mitad). */
    static function cut($text, $length) {
        $text = (string) $text;
        if (function_exists('mb_substr'))
            return mb_substr($text, 0, $length, 'UTF-8');
        return preg_replace('/^(.{0,' . (int) $length . '}).*$/us', '$1', $text);
    }

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

    /* A stderr, no a stdout: asi `make queue-nav | head` no se come el aviso.
     * (Module no lo trae; perf.php y seed.php lo definen igual.) */
    function err($text) {
        $this->stderr->write($text . "\n");
    }

    function emitJson($data) {
        $this->out(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES));
    }
}

Module::register('queue', 'QueueCheck');
