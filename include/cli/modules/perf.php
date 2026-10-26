<?php
/*********************************************************************
    include/cli/modules/perf.php

    Perfilado de la página del ticket: en qué se va el tiempo.

        php manage.php perf --ticket=405
        php manage.php perf             # el ticket modificado más recientemente

    Para qué sirve: «al abrir un ticket tarda» no dice dónde. La página hace
    muchas cosas —carga el ticket, sus relaciones, el hilo con sus entradas,
    eventos, adjuntos, colaboradores, tareas y los campos del formulario— y
    cualquiera de ellas puede ser la cara. Este comando hace **lo mismo que la
    página**, por pasos, cronometrando cada uno y contando las consultas.

    La medición sale del propio `db_query()`: cuando `$GLOBALS['__db_profile']`
    tiene una función, `include/mysqli.php` se la pasa con el SQL, lo que tardó
    y la línea que lo disparó (ver `include/class.db.origin.php`), para que un
    paso de 13 consultas diga también de dónde salen. No hace falta activar nada en la aplicación ni en MariaDB, y no
    queda nada encendido al terminar el proceso.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

class PerfCheck extends Module {

    var $prologue = 'Perfila la carga de un ticket: pasos, consultas y tiempo';

    var $arguments = array();

    var $options = array(
        'ticket' => array('-t', '--ticket', 'default' => '', 'metavar' => 'ID',
            'help' => 'Ticket a perfilar (por omisión, el último modificado)'),
        'top' => array('', '--top', 'default' => '12', 'metavar' => 'N',
            'help' => 'Cuántas consultas se listan, por tiempo total (12)'),
        'json' => array('-j', '--json', 'action' => 'store_true',
            'help' => 'Salida JSON, para engancharla a otra herramienta'),
        'step' => array('', '--step', 'default' => '', 'metavar' => 'TEXTO',
            'help' => 'Detalla las consultas de los pasos cuyo nombre contenga TEXTO'),
        'schema' => array('', '--schema', 'action' => 'store_true',
            'help' => 'Incluye la introspección del esquema en el ranking (normalmente se oculta)'),
    );

    /** @var array Consultas acumuladas: patrón => [n, ms, ejemplo] */
    var $queries = array();
    /** @var array Pasos: [nombre, ms, consultas] */
    var $steps = array();
    /** Paso en curso, para poder atribuirle cada consulta. */
    var $currentStep = -1;
    /** Nombre del paso en curso: el paso aún no está en `$steps` cuando corre. */
    var $currentLabel = '';
    var $json = false;
    var $staff;
    /** Los pone `Module::parseOptions()`; se declara para no crearla al vuelo. */
    var $_options = array();
    /** La consola de `Module`; se declara por el mismo motivo. */
    var $stdout;

    function run($args, $options) {
        global $ost, $cfg;

        $this->json = (bool) $this->getOption('json');

        if (function_exists('mysqli_report') && defined('MYSQLI_REPORT_OFF'))
            mysqli_report(MYSQLI_REPORT_OFF);

        require_once INCLUDE_DIR . 'class.ticket.php';
        require_once INCLUDE_DIR . 'class.task.php';
        require_once INCLUDE_DIR . 'class.dynamic_forms.php';

        try {
            Bootstrap::connect();
            $ost = osTicket::start();
        }
        catch (Throwable $e) {
            $this->err('No se pudo abrir la base de datos: ' . $e->getMessage());
            return 2;
        }

        if (!$ost || !($cfg = $ost->getConfig()))
            $this->fail('No se pudo leer la configuración de la base de datos');

        // Se perfila con un administrador: es el caso en el que la página
        // hace más trabajo (ve todos los departamentos y todos los botones).
        $this->staff = $this->admin();
        if ($this->staff)
            $GLOBALS['thisstaff'] = $this->staff;

        $id = $this->getOption('ticket');
        if (!$id)
            $id = $this->lastTicket();
        if (!$id)
            $this->fail('No hay ningún ticket que perfilar');

        $this->startProfile();

        if (!($ticket = $this->stepTicket($id)))
            $this->fail("No existe el ticket #{$id}");

        if ($thread = $this->stepThread($ticket))
            $this->stepsThreadBody($thread, $ticket);

        $this->stepsFormsAndTasks($id, $ticket);

        $this->stopProfile();
        $this->report($id);
        return 0;
    }

    /** El ticket y su encabezado: lo primero que hace la página. */
    function stepTicket($id) {
        $ticket = null;
        $this->step('Ticket::lookup()', function() use ($id, &$ticket) {
            $ticket = Ticket::lookup($id);
            return $ticket;
        });

        if (!$ticket)
            return null;

        // Cada uno de estos es una consulta o un modelo relacionado:
        // departamento, rol, agente, usuario, equipo, SLA y bloqueo.
        $this->step('Encabezado: dept, rol, agente, usuario, equipo, SLA',
            function() use ($ticket) {
                $ticket->getDept();
                $ticket->getRole($this->staff);
                $ticket->getStaff();
                $ticket->getOwner();
                $ticket->getTeam();
                $ticket->getSLA();
                $ticket->getLock();
                return true;
            });

        $this->step('Hijo/enlazado (getMergeType, getChildren)',
            function() use ($ticket) {
                $ticket->getMergeType();
                $ticket->getChildren();
                return true;
            });

        return $ticket;
    }

    /** El hilo, que es lo que se pinta en la pestaña principal. */
    function stepThread($ticket) {
        $thread = null;
        $this->step('Hilo: getThread()', function() use ($ticket, &$thread) {
            $thread = $ticket->getThread();
            return $thread;
        });

        return $thread;
    }

    /** Todo lo que cuesta el hilo: entradas, eventos, adjuntos y el pintado. */
    function stepsThreadBody($thread, $ticket) {
        $this->step('Colaboradores (4 veces, como la página)',
            function() use ($thread) {
                $thread->getCollaborators();
                $thread->getNumCollaborators();
                $thread->getNumCollaborators();
                $thread->getNumActiveCollaborators();
                $thread->getNumCollaborators();
                return true;
            });

        $entries = null;
        $this->step('Hilo: getEntries()', function() use ($thread, &$entries) {
            $entries = $thread->getEntries();
            // Se fuerza la hidratación: es lo que hace el `foreach` del
            // template, y ahí es donde aparecen (o no) las consultas por
            // entrada.
            $n = 0;
            foreach ($entries as $E)
                $n++;
            return $n;
        });

        $this->step('Hilo: eventos', function() use ($thread) {
            $n = 0;
            foreach ($thread->getEvents() as $e)
                $n++;
            return $n;
        });

        $this->step('Hilo: adjuntos', function() use ($thread) {
            $n = 0;
            foreach (Attachment::objects()->filter(array(
                    'thread_entry__thread__id' => $thread->getId(),
                ))->select_related('thread_entry', 'file') as $a)
                $n++;
            return $n;
        });

        // `render()` carga este archivo la primera vez (trae las clases de los
        // botones de cada entrada). Sin cargarlo, `hasActions()` no tiene
        // registro que recorrer y el paso de acciones mediría cero: hay que
        // medirlo en el mismo orden que la plantilla.
        $this->step('Hilo: carga de acciones (thread_actions.php)',
            function() {
                if (!class_exists('tea_showemailheaders'))
                    include_once INCLUDE_DIR . 'class.thread_actions.php';
                return count(ThreadEntry::$action_registry);
            });

        // Desglose de lo que la plantilla hace **una vez por entrada**: es lo
        // que convierte «render() tarda 24 ms» en un culpable concreto.
        // El cuerpo se vuelve a pedir frío (`_body` a null) para que el paso
        // de render mida lo mismo que la primera carga de la página.
        $this->step('Hilo: cuerpo → HTML (por entrada)', function() use ($entries) {
            $n = 0;
            foreach ($entries as $E) {
                $E->_body = null;
                $E->getBody()->toHtml();
                $E->_body = null;
                $n++;
            }
            return $n;
        });

        $this->step('Hilo: acciones por entrada', function() use ($entries) {
            $n = 0;
            foreach ($entries as $E) {
                $E->hasActions();
                $E->getMergeData();
                $n++;
            }
            return $n;
        });

        // Lo que la plantilla hace **antes** del bucle: precargar adjuntos,
        // contar entradas y eventos, y ordenar. Va aparte para poder restar.
        $this->step('Hilo: proemio del template',
            function() use ($thread, $entries, $ticket) {
                AttachmentFile::objects()->filter(array(
                    'attachments__thread_entry__thread__id' => $thread->getId()
                ))->all();

                $n = 0;
                foreach (Attachment::objects()->filter(array(
                        'thread_entry__thread__id' => $thread->getId(),
                    ))->select_related('thread_entry', 'file') as $A)
                    $n++;

                $entries->exists(true);
                $entries->count();
                $events = $thread->getEvents()->order_by('id');
                count($events);
                ThreadEntry::sortEntries($entries, $ticket);
                return $n;
            });

        $this->step('Hilo: render() completo', function() use ($thread) {
            return $this->renderThread($thread);
        });

        // La segunda pasada distingue lo que se paga una vez (compilar el
        // template, cargar clases, hidratar) de lo que se paga en cada
        // pintado.
        $this->step('Hilo: render() otra vez (caché tibia)',
            function() use ($thread) {
                return $this->renderThread($thread);
            });
    }

    /** Pinta el hilo como la página y devuelve los bytes producidos. */
    function renderThread($thread) {
        ob_start();
        $thread->render(array('M', 'R', 'N'), array(
            'html-id' => 'ticketThread',
            'mode' => Thread::MODE_STAFF,
            'sort' => $this->staff ? $this->staff->thread_view_order : 'ASC',
        ));
        return strlen(ob_get_clean());
    }

    /** Formulario y tareas: el resto de la página del ticket. */
    function stepsFormsAndTasks($id, $ticket) {
        $this->step('Formulario: DynamicFormEntry::forTicket()',
            function() use ($id) {
                $n = 0;
                foreach (DynamicFormEntry::forTicket($id) as $f)
                    $n++;
                return $n;
            });

        // La pestaña de tareas sólo pinta el número: el listado llega por
        // AJAX (`#tickets/<id>/tasks`), así que lo que cuesta la carga es
        // `getNumTasks()`/`getNumOpenTasks()`, no una consulta de tareas.
        $this->step('Tareas: el número de la pestaña', function() use ($ticket) {
            $ticket->getNumTasks();
            $ticket->getNumOpenTasks();
            return $ticket->getNumTasks();
        });

        // Y lo que sí llega por AJAX, para saber si el retardo está ahí: al
        // abrir el ticket esta consulta no se hace, se hace al cambiar de
        // pestaña.
        $this->step('Tareas: listado (AJAX, sólo si se abre la pestaña)',
            function() use ($id) {
                $n = 0;
                foreach (Task::objects()->filter(array('object_id' => $id,
                        'object_type' => 'T')) as $t)
                    $n++;
                return $n;
            });
    }

    /* ------------------------------------------------------------------ */
    /* Medición                                                            */
    /* ------------------------------------------------------------------ */

    function startProfile() {
        $this->queries = array();
        $this->steps = array();
        $self = $this;
        $GLOBALS['__db_profile'] = function($sql, $ms, $origin = null) use ($self) {
            $self->record($sql, $ms, $origin);
        };
    }

    function stopProfile() {
        unset($GLOBALS['__db_profile']);
    }

    /** Acumula una consulta bajo su patrón (sin números ni literales). */
    /**
     * ¿La consulta sólo está leyendo el esquema?
     *
     * El ORM, la primera vez que ve una tabla en un proceso, pregunta sus
     * columnas a `INFORMATION_SCHEMA`. Con APCu eso se guarda 30 minutos y lo
     * paga la primera petición web; en la consola (`apc.enable_cli = 0`) lo
     * paga **siempre**. Mezclarlas con las consultas reales hace que el
     * informe señale un fantasma, así que se cuentan aparte.
     */
    static function isSchemaQuery($sql) {
        return stripos($sql, 'INFORMATION_SCHEMA') !== false
            || stripos($sql, 'SHOW TABLES') === 0
            || stripos($sql, 'SHOW COLUMNS') === 0;
    }

    function record($sql, $ms, $origin = null) {
        $key = self::pattern($sql);
        if (!isset($this->queries[$key]))
            $this->queries[$key] = array('sql' => $key, 'n' => 0, 'ms' => 0.0,
                'example' => $sql, 'steps' => array(), 'origins' => array());
        $this->queries[$key]['n']++;
        $this->queries[$key]['ms'] += $ms;
        $this->queries[$key]['schema'] = self::isSchemaQuery($key);
        if ($origin) {
            // Una consulta lanzada al recorrer un conjunto perezoso se ejecuta
            // dentro del cierre del paso, así que el primer marco de la
            // aplicación es el propio perfilador. Decir «perf.php:157» no
            // ayuda a nadie: lo honesto es decir que salió al recorrer ese
            // paso.
            if (basename(strtok($origin, ':')) === 'perf.php')
                $origin = 'paso «' . ($this->currentLabel ?: '?') . '»';
            $this->queries[$key]['origins'][$origin] =
                (isset($this->queries[$key]['origins'][$origin])
                    ? $this->queries[$key]['origins'][$origin] : 0) + 1;
        }
        if ($this->currentStep >= 0)
            $this->queries[$key]['steps'][$this->currentStep] = true;
    }

    /**
     * Las líneas de código que disparan consultas, de la que más tiempo suma
     * a la que menos.
     *
     * Es lo que convierte «este paso hace 13 consultas» en «esta línea, una
     * vez por entrada». Se agrupa por origen y se cuentan también las
     * consultas distintas: una línea que pide tres cosas distintas no es lo
     * mismo que una que repite la misma tres veces.
     */
    function topOrigins($includeSchema = null) {
        if ($includeSchema === null)
            $includeSchema = (bool) $this->getOption('schema');
        $origins = array();
        foreach ($this->queries as $q) {
            if (!$includeSchema && !empty($q['schema']))
                continue;
            if (empty($q['origins']))
                continue;
            foreach ($q['origins'] as $origin => $n) {
                if (!isset($origins[$origin]))
                    $origins[$origin] = array('origin' => $origin, 'n' => 0,
                        'ms' => 0.0, 'sql' => array());
                $origins[$origin]['n'] += $n;
                // El tiempo de la consulta se reparte entre sus orígenes; en
                // la práctica cada patrón tiene uno solo.
                $origins[$origin]['ms'] += $q['ms'] * $n / max($q['n'], 1);
                $origins[$origin]['sql'][$q['sql']] = true;
            }
        }
        foreach ($origins as $i => $o)
            $origins[$i]['distinct'] = count($o['sql']);
        usort($origins, function($a, $b) {
            return $b['ms'] <=> $a['ms'];
        });
        return $origins;
    }

    /** Un origen sin la ruta del proyecto: `include/class.thread.php:1012`. */
    static function shortOrigin($origin, $root = null) {
        if (!$origin)
            return '';
        if ($root === null)
            $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(INCLUDE_DIR) . '/';
        $root = rtrim($root, '/') . '/';
        if (strpos($origin, $root) === 0)
            $origin = substr($origin, strlen($root));
        return $origin;
    }

    /**
     * Cronometra un paso y cuenta cuántas consultas provoca.
     *
     * Se cuentan **por separado** las consultas reales y la introspección del
     * esquema: en la consola, un paso que toca cinco tablas nuevas suma cinco
     * `INFORMATION_SCHEMA` que la web no paga. Contarlas juntas hacía que
     * «Ticket::lookup(): 11 SQL» pareciera un ticket que necesita once
     * consultas.
     */
    function step($label, $callback) {
        $before = $this->totals(false);
        $beforeSchema = $this->schemaTotals();
        $this->currentStep = count($this->steps);
        $this->currentLabel = $label;
        $started = microtime(true);
        $result = null;
        $error = null;
        try {
            $result = $callback();
        }
        catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $ms = (microtime(true) - $started) * 1000;
        $after = $this->totals(false);
        $afterSchema = $this->schemaTotals();

        $this->steps[] = array(
            'label' => $label,
            'ms' => $ms,
            'queries' => $after['n'] - $before['n'],
            'schema' => $afterSchema['n'] - $beforeSchema['n'],
            'sql_ms' => $after['ms'] - $before['ms'],
            'result' => is_int($result) ? $result : null,
            'error' => $error,
        );

        return $result;
    }

    function totals($includeSchema = true) {
        $n = 0;
        $ms = 0.0;
        foreach ($this->queries as $q) {
            if (!$includeSchema && !empty($q['schema']))
                continue;
            $n += $q['n'];
            $ms += $q['ms'];
        }
        return array('n' => $n, 'ms' => $ms);
    }

    /** Lo que se llevó la introspección del esquema, aparte. */
    function schemaTotals() {
        $n = 0;
        $ms = 0.0;
        foreach ($this->queries as $q) {
            if (empty($q['schema']))
                continue;
            $n += $q['n'];
            $ms += $q['ms'];
        }
        return array('n' => $n, 'ms' => $ms);
    }

    /**
     * Patrón de una consulta para poder agruparlas: números, literales y
     * listas `IN (…)` fuera. Dos ejecuciones del mismo `SELECT` con otro id
     * son «la misma consulta» y suman.
     */
    static function pattern($sql) {
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "'?'", $sql);
        $sql = preg_replace('/\b\d+\b/', '?', $sql);
        $sql = preg_replace('/\s+/', ' ', $sql);
        return trim($sql);
    }

    /* ------------------------------------------------------------------ */
    /* Salida                                                              */
    /* ------------------------------------------------------------------ */

    function report($id) {
        $total = 0.0;
        foreach ($this->steps as $step)
            $total += $step['ms'];

        if ($this->json) {
            $this->reportJson($id, $total);
            return;
        }

        $this->out('');
        $requested = (string) $this->getOption('ticket');
        $this->out(sprintf('Ticket #%s — perfil de la carga%s', $id,
            $requested !== '' && $requested !== (string) $id
                ? ' (se pidió «' . $requested . '»: no existe, se usó el último)'
                : ''));
        $this->out(str_repeat('-', 62));
        $this->reportTable();

        // Los pasos que no se pudieron medir, con su motivo: un error aquí
        // no invalida el resto del informe, pero hay que verlo.
        foreach ($this->steps as $step)
            if ($step['error'])
                $this->err(sprintf('  · no se pudo medir «%s»: %s',
                    $step['label'], $step['error']));

        $totals = $this->totals(false);
        $schema = $this->schemaTotals();
        $this->reportTotals($total, $totals, $schema);
        $this->reportRankings();
        $this->reportSlowest();

        // Detalle por paso: qué consultas hace cada uno. Es lo que permite
        // pasar de «este paso cuesta 24 ms» a «estas cuatro consultas son la
        // misma, una por entrada».
        $filter = (string) $this->getOption('step');
        if ($filter !== '')
            $this->detailStep($filter);

        $this->reportNotes($totals);
    }

    /** El informe en JSON, para engancharlo a otra herramienta. */
    function reportJson($id, $total) {
        echo json_encode(array(
            'ticket' => (int) $id,
            'total_ms' => round($total, 1),
            'steps' => array_map(function($s) {
                return array('label' => $s['label'],
                    'ms' => round($s['ms'], 1),
                    // En milisegundos, como la tabla: `sql_ms` interno está
                    // en segundos y redondear a 2 decimales lo dejaba en cero.
                    'sql_ms' => round($s['sql_ms'] * 1000, 1),
                    'queries' => $s['queries'],
                    'schema_queries' => isset($s['schema']) ? $s['schema'] : 0,
                    'error' => $s['error']);
            }, $this->steps),
            'queries' => $this->topQueries(),
            'origins' => array_map(function($o) {
                return array('origin' => PerfCheck::shortOrigin($o['origin']),
                    'queries' => $o['n'], 'distinct' => $o['distinct'],
                    'ms' => round($o['ms'], 1));
            }, array_slice($this->topOrigins(), 0, 10)),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE), "\n";
    }

    /** La tabla de pasos: tiempo, cuánto es SQL y cuántas consultas. */
    function reportTable() {
        $rows = array();
        foreach ($this->steps as $step)
            $rows[] = array(
                $step['label'],
                sprintf('%.1f', $step['ms']),
                // Lo que de ese tiempo es base de datos: el resto es PHP.
                sprintf('%.1f', $step['sql_ms'] * 1000),
                $step['queries'],
                isset($step['schema']) ? $step['schema'] : 0,
                $step['result'] === null ? '' : $step['result'],
                $step['error'] ? 'ERROR' : '',
            );
        $this->table(array('Paso', 'ms', 'SQLms', 'SQL', 'Esq', 'N', 'Err'), $rows);
    }

    /** El total, y en qué se reparte: base de datos contra PHP. */
    function reportTotals($total, $totals, $schema) {
        $this->out('');
        $this->out(sprintf('Total: %.1f ms en %d pasos · %d consultas reales (%.1f ms en SQL)',
            $total, count($this->steps), $totals['n'], $totals['ms'] * 1000));
        if ($schema['n']) {
            $this->out(sprintf('       + %d de introspección de esquema (%.1f ms):'
                .' en la web las paga APCu una vez cada 30 min',
                $schema['n'], $schema['ms'] * 1000));
        }

        // La pregunta que se hace todo el mundo: ¿esto es la base de datos o
        // es PHP? Se responde en una línea.
        $db = ($totals['ms'] + $schema['ms']) * 1000;
        $php = max($total - $db, 0);
        $this->out(sprintf('       = %.1f ms en base de datos y %.1f ms en PHP'
            . ' (plantillas, objetos, saneado del HTML)', $db, $php));
    }

    /** Los dos rankings: consultas por tiempo total y líneas que las piden. */
    function reportRankings() {
        $top = $this->topQueries();
        if ($top) {
            $this->out('');
            $this->out(sprintf('Las %d consultas que más tiempo suman%s',
                count($top), $this->getOption('schema')
                    ? '' : ' (sin la introspección del esquema)'));
            $this->out(str_repeat('-', 62));
            foreach ($top as $q) {
                $this->out(sprintf('  %5.1f ms · %3d × · %s', $q['ms'] * 1000,
                    $q['n'], $this->cut($q['sql'], 100)));
            }
        }

        // Qué línea de código dispara las consultas. Sin esto, «10 consultas
        // en render()» sólo se puede adivinar leyendo el código.
        $origins = $this->topOrigins();
        if ($origins) {
            $this->out('');
            $this->out(sprintf('De dónde salen las consultas%s',
                $this->getOption('schema')
                    ? '' : ' (sin la introspección del esquema)'));
            $this->out(str_repeat('-', 62));
            foreach (array_slice($origins, 0, 10) as $o) {
                $this->out(sprintf('  %5.1f ms · %3d × · %2d SQL distinta%s · %s',
                    $o['ms'] * 1000, $o['n'], $o['distinct'],
                    $o['distinct'] == 1 ? '' : 's',
                    self::shortOrigin($o['origin'])));
            }
        }
    }

    /** El paso más caro, entre los que sí se pudieron medir. */
    function reportSlowest() {
        $slowest = null;
        foreach ($this->steps as $step)
            if (!$step['error'] && (!$slowest || $step['ms'] > $slowest['ms']))
                $slowest = $step;
        if ($slowest) {
            $this->out('');
            $this->out(sprintf('El paso más caro: «%s» (%.1f ms, %d consultas)',
                $slowest['label'], $slowest['ms'], $slowest['queries']));
        }
    }

    /** Los avisos del final: APCu y la consulta que más se repite. */
    function reportNotes($totals) {
        // Aviso honesto: en CLI no hay APCu, así que la introspección del
        // esquema se paga aquí y no en la web.
        if (!function_exists('apcu_fetch'))
            $this->out(''
                . "\nNota: APCu no está activo en este proceso (apc.enable_cli=0),"
                . "\nasí que la introspección del esquema se paga entera aquí. En la"
                . "\nweb (FPM) se cachea 30 minutos, de modo que la página real hace"
                . "\nlas " . $totals['n'] . " consultas de arriba y, como mucho, una"
                . "\ntanda de esquema tras arrancar o tras expirar la caché.");

        $repeated = null;
        foreach ($this->topQueries() as $q)
            if (!$repeated || $q['n'] > $repeated['n'])
                $repeated = $q;
        if ($repeated && $repeated['n'] > 1)
            $this->out(sprintf('La consulta más repetida: %d veces — %s',
                $repeated['n'], $this->cut($repeated['sql'], 90)));
    }

    /** Lista las consultas de los pasos cuyo nombre contenga `$filter`. */
    function detailStep($filter) {
        $shown = 0;
        $matched = 0;
        foreach ($this->steps as $index => $step) {
            if (stripos($step['label'], $filter) === false)
                continue;
            $matched++;

            $queries = array();
            foreach ($this->queries as $q) {
                if (!isset($q['steps'][$index]))
                    continue;
                $queries[] = $q;
            }
            usort($queries, function($a, $b) {
                return $b['ms'] <=> $a['ms'];
            });

            $this->out('');
            $this->out(sprintf('Consultas de «%s» — %.1f ms, %d SQL',
                $step['label'], $step['ms'], $step['queries']));
            $this->out(str_repeat('-', 62));
            if (!$queries) {
                $this->out('  (ninguna: el tiempo de este paso es de PHP)');
                continue;
            }
            foreach ($queries as $q) {
                $this->out(sprintf('  %5.1f ms · %3d × · %s', $q['ms'] * 1000,
                    $q['n'], $this->cut($q['sql'], 120)));
                if ($origin = $this->mainOrigin($q))
                    $this->out('        desde ' . self::shortOrigin($origin));
                $shown++;
            }
        }

        if (!$matched) {
            // Un filtro que no encuentra nada no puede dejar el informe mudo:
            // así se ve qué nombres de paso hay para elegir.
            $this->out(''
                . "\nNingún paso coincide con «" . $filter . "». Los pasos"
                . " medidos son:");
            foreach ($this->steps as $step)
                $this->out('  · ' . $step['label']);
        }
    }

    /** El origen que más veces disparó una consulta, si se conoce. */
    static function mainOrigin($query) {
        if (empty($query['origins']))
            return null;
        $best = null;
        $count = 0;
        foreach ($query['origins'] as $origin => $n) {
            if ($n > $count) {
                $best = $origin;
                $count = $n;
            }
        }
        return $best;
    }

    function topQueries() {
        $top = array();
        $schema = (bool) $this->getOption('schema');
        foreach ($this->queries as $q)
            if ($schema || empty($q['schema']))
                $top[] = $q;
        usort($top, function($a, $b) {
            return $b['ms'] <=> $a['ms'];
        });
        $limit = (int) $this->getOption('top') ?: 12;
        return array_slice($top, 0, $limit);
    }

    function cut($text, $width) {
        if (strlen($text) <= $width)
            return $text;
        return substr($text, 0, $width - 1) . '…';
    }

    function table($headers, $rows) {
        // `strlen()` cuenta bytes: una flecha «→» ocupa tres y descuadraba la
        // tabla entera. El ancho se mide en caracteres.
        $len = function($text) {
            $text = (string) $text;
            if (function_exists('mb_strlen'))
                return mb_strlen($text, 'UTF-8');
            // Sin mbstring: contar caracteres UTF-8 con el motor de regex.
            return preg_match_all('/./u', $text) ?: strlen($text);
        };
        $widths = array();
        foreach ($headers as $i => $h)
            $widths[$i] = $len($h);
        foreach ($rows as $row)
            foreach (array_values($row) as $i => $cell)
                $widths[$i] = max($widths[$i], $len((string) $cell));

        $line = function($cells) use ($widths, $len) {
            $out = array();
            foreach (array_values($cells) as $i => $cell)
                $out[] = str_pad((string) $cell,
                    $widths[$i] + strlen((string) $cell) - $len($cell));
            return '  ' . rtrim(implode('  ', $out));
        };

        $this->out($line($headers));
        $this->out('  ' . implode('  ', array_map(function($w) {
            return str_repeat('-', $w);
        }, $widths)));
        foreach ($rows as $row)
            $this->out($line($row));
    }

    /* ------------------------------------------------------------------ */
    /* Datos                                                               */
    /* ------------------------------------------------------------------ */

    /** Un administrador activo, para perfilar con el peor caso. */
    function admin() {
        $found = null;
        try {
            foreach (Staff::objects()
                    ->filter(array('isadmin' => 1, 'isactive' => 1))
                    ->order_by('staff_id') as $staff) {
                $found = $staff;
                break;
            }
        }
        catch (Throwable $e) {
            return null;
        }
        return $found;
    }

    /** El ticket modificado más recientemente: el que uno acaba de abrir. */
    function lastTicket() {
        try {
            if ($row = db_fetch_array(db_query('SELECT ticket_id FROM '
                    .TICKET_TABLE.' ORDER BY lastupdate DESC LIMIT 1', false)))
                return (int) $row['ticket_id'];
        }
        catch (Throwable $e) {}
        return null;
    }

    function out($text) {
        if ($this->stdout)
            $this->stdout->write($text . "\n");
        else
            echo $text, "\n";
    }

    /**
     * Un aviso. `Module` (la base de los comandos) no tiene `err()`: la
     * escribe cada módulo que la necesita. Aquí va aparte porque un paso que
     * falla **no puede** tumbar el perfil: si no se puede medir una parte, se
     * mide el resto y se dice cuál quedó fuera.
     */
    function err($text) {
        $this->out($text);
    }
}

Module::register('perf', 'PerfCheck');
