<?php
/*********************************************************************
    include/cli/modules/queue.report.php

    El veredicto y el informe del diagnóstico de colas: qué se ha comprobado,
    qué números no cuadran y por qué. Van en su propio archivo porque
    `queue.php` había pasado de las 2.000 líneas (frente 6 de
    docs/CODE_QUALITY.md); se movieron los métodos enteros, sin tocar su
    código.

    Es un rasgo de QueueCheck y no una clase aparte porque el informe usa el
    estado de la ejecución (`$this->out()`, `$this->table()`, `$this->wrap()`)
    y los datos que ya ha reunido el diagnóstico.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

trait QueueReport {

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
}
