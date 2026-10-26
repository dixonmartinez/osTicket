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
    tiene una función, `include/mysqli.php` se la pasa con el SQL y lo que
    tardó. No hace falta activar nada en la aplicación ni en MariaDB, y no
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
    );

    /** @var array Consultas acumuladas: patrón => [n, ms, ejemplo] */
    var $queries = array();
    /** @var array Pasos: [nombre, ms, consultas] */
    var $steps = array();
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

        $ticket = null;
        $this->startProfile();

        $this->step('Ticket::lookup()', function() use ($id, &$ticket) {
            $ticket = Ticket::lookup($id);
            return $ticket;
        });

        if (!$ticket)
            $this->fail("No existe el ticket #{$id}");

        $thread = null;

        // El encabezado de la página: cada uno de estos es una consulta o un
        // modelo relacionado (departamento, rol, agente, usuario, equipo…).
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

        $this->step('Hilo: getThread()', function() use ($ticket, &$thread) {
            $thread = $ticket->getThread();
            return $thread;
        });

        if (!$thread) {
            $this->report($id);
            return 0;
        }

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

        $this->step('Hilo: render() completo', function() use ($thread) {
            ob_start();
            $thread->render(array('M', 'R', 'N'), array(
                'html-id' => 'ticketThread',
                'mode' => Thread::MODE_STAFF,
                'sort' => $this->staff ? $this->staff->thread_view_order : 'ASC',
            ));
            return strlen(ob_get_clean());
        });

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

        $this->stopProfile();
        $this->report($id);
        return 0;
    }

    /* ------------------------------------------------------------------ */
    /* Medición                                                            */
    /* ------------------------------------------------------------------ */

    function startProfile() {
        $this->queries = array();
        $this->steps = array();
        $self = $this;
        $GLOBALS['__db_profile'] = function($sql, $ms) use ($self) {
            $self->record($sql, $ms);
        };
    }

    function stopProfile() {
        unset($GLOBALS['__db_profile']);
    }

    /** Acumula una consulta bajo su patrón (sin números ni literales). */
    function record($sql, $ms) {
        $key = self::pattern($sql);
        if (!isset($this->queries[$key]))
            $this->queries[$key] = array('sql' => $key, 'n' => 0, 'ms' => 0.0,
                'example' => $sql);
        $this->queries[$key]['n']++;
        $this->queries[$key]['ms'] += $ms;
    }

    /** Cronometra un paso y cuenta cuántas consultas provoca. */
    function step($label, $callback) {
        $before = $this->totals();
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
        $after = $this->totals();

        $this->steps[] = array(
            'label' => $label,
            'ms' => $ms,
            'queries' => $after['n'] - $before['n'],
            'sql_ms' => $after['ms'] - $before['ms'],
            'result' => is_int($result) ? $result : null,
            'error' => $error,
        );

        return $result;
    }

    function totals() {
        $n = 0;
        $ms = 0.0;
        foreach ($this->queries as $q) {
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
            $top = $this->topQueries();
            echo json_encode(array(
                'ticket' => (int) $id,
                'total_ms' => round($total, 1),
                'steps' => array_map(function($s) {
                    return array('label' => $s['label'],
                        'ms' => round($s['ms'], 1), 'queries' => $s['queries'],
                        'error' => $s['error']);
                }, $this->steps),
                'queries' => $top,
            ), JSON_PRETTY_PRINT), "\n";
            return;
        }

        $this->out('');
        $this->out(sprintf('Ticket #%s — perfil de la carga', $id));
        $this->out(str_repeat('-', 62));
        $rows = array();
        foreach ($this->steps as $step)
            $rows[] = array(
                $step['label'],
                sprintf('%.1f', $step['ms']),
                $step['queries'],
                $step['result'] === null ? '' : $step['result'],
                $step['error'] ? 'ERROR' : '',
            );
        $this->table(array('Paso', 'ms', 'SQL', 'N', 'Err'), $rows);

        // Los pasos que no se pudieron medir, con su motivo: un error aquí
        // no invalida el resto del informe, pero hay que verlo.
        foreach ($this->steps as $step)
            if ($step['error'])
                $this->err(sprintf('  · no se pudo medir «%s»: %s',
                    $step['label'], $step['error']));

        $totals = $this->totals();
        $this->out('');
        $this->out(sprintf('Total: %.1f ms en %d pasos · %d consultas (%.1f ms en SQL)',
            $total, count($this->steps), $totals['n'], $totals['ms'] * 1000));

        $top = $this->topQueries();
        if ($top) {
            $this->out('');
            $this->out(sprintf('Las %d consultas que más tiempo suman', count($top)));
            $this->out(str_repeat('-', 62));
            foreach ($top as $q) {
                $this->out(sprintf('  %5.1f ms · %3d × · %s', $q['ms'] * 1000,
                    $q['n'], $this->cut($q['sql'], 100)));
            }
        }

        // Dónde se va el tiempo, en una línea: es lo que se pregunta
        // El paso más caro, entre los que sí se pudieron medir
        $slowest = null;
        foreach ($this->steps as $step)
            if (!$step['error'] && (!$slowest || $step['ms'] > $slowest['ms']))
                $slowest = $step;
        if ($slowest) {
            $this->out('');
            $this->out(sprintf('El paso más caro: «%s» (%.1f ms, %d consultas)',
                $slowest['label'], $slowest['ms'], $slowest['queries']));
        }

        $repeated = null;
        foreach ($top as $q)
            if (!$repeated || $q['n'] > $repeated['n'])
                $repeated = $q;
        if ($repeated && $repeated['n'] > 1)
            $this->out(sprintf('La consulta más repetida: %d veces — %s',
                $repeated['n'], $this->cut($repeated['sql'], 90)));
    }

    function topQueries() {
        $top = array_values($this->queries);
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
        $widths = array();
        foreach ($headers as $i => $h)
            $widths[$i] = strlen($h);
        foreach ($rows as $row)
            foreach (array_values($row) as $i => $cell)
                $widths[$i] = max($widths[$i], strlen((string) $cell));

        $line = function($cells) use ($widths) {
            $out = array();
            foreach (array_values($cells) as $i => $cell)
                $out[] = str_pad((string) $cell, $widths[$i]);
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
