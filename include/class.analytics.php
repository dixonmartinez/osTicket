<?php
/**
 * include/class.analytics.php
 *
 * Métricas operativas del help desk.
 *
 * A diferencia de OverviewReport (que cuenta eventos del hilo), este motor
 * responde a las preguntas que se hacen a diario en una mesa de ayuda:
 *
 *   - ¿Cuánto tarda la primera respuesta y cuánto se tarda en cerrar?
 *   - ¿Qué porcentaje de tickets se resuelve dentro del SLA?
 *   - ¿Cuánto trabajo está abierto y quién lo tiene?
 *   - ¿De dónde vienen los tickets y a qué horas?
 *
 * Decisiones de diseño:
 *
 *   - **Sólo lectura**: no escribe en la base de datos ni en la sesión.
 *   - **Agregación en SQL**: se agrupa por fecha con índices existentes
 *     (`created`, `closed`, `thread_stats`) para no traer filas a PHP.
 *   - **Respeto de permisos**: `$thisstaff->getDepts()` y el permiso
 *     `stats.agents` filtran el alcance, igual que el resto del panel.
 *   - **Rangos validados**: la fecha de entrada se normaliza con
 *     `strtotime()` y se acota a un máximo configurable; nunca se interpola
 *     texto del usuario en SQL.
 */
class Analytics
{
    /** Duración máxima de un informe, en días. */
    const MAX_DAYS = 730;

    /** Rangos predefinidos que ofrece la interfaz. */
    public static $ranges = [
        '7d'  => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last quarter',
        '12m' => 'Last 12 months',
        'ytd' => 'Year to date',
    ];

    /** @var int Inicio del periodo (timestamp UTC) */
    private $start;
    /** @var int Fin del periodo (timestamp UTC) */
    private $end;
    /** @var array<int> Departamentos visibles para el agente */
    private $depts;
    /** @var bool Si puede ver métricas de otros agentes */
    private $seeAgents;
    /** @var int|null id del agente que consulta */
    private $staffId = null;

    /**
     * @param string     $range  Clave de self::$ranges
     * @param object|null $staff Agente actual (para aplicar permisos)
     * @param string|null $start Fecha de inicio personalizada (Y-m-d)
     * @param string|null $end   Fecha de fin personalizada (Y-m-d)
     */
    public function __construct($range = '30d', $staff = null, $start = null, $end = null)
    {
        global $thisstaff;

        $staff = $staff ?: $thisstaff;
        $this->staffId = $staff ? (int) $staff->getId() : null;

        // Alcance por permisos
        $this->seeAgents = $staff
            ? $staff->hasPerm(ReportModel::PERM_AGENTS)
            : false;
        // getDepts() devuelve la lista de ids a los que tiene acceso
        $this->depts = ($staff && !$staff->isAdmin())
            ? array_values(array_map('intval', (array) $staff->getDepts()))
            : null; // null = todos

        // Periodo
        $this->end = self::toTimestamp($end) ?: time();
        if ($start && ($ts = self::toTimestamp($start))) {
            $this->start = $ts;
        }
        else {
            $this->start = $this->end - $this->rangeSeconds($range);
        }

        if ($this->start > $this->end)
            list($this->start, $this->end) = [$this->end, $this->start];

        // Cota de seguridad
        if ($this->end - $this->start > self::MAX_DAYS * 86400)
            $this->start = $this->end - self::MAX_DAYS * 86400;
    }

    /* ------------------------------------------------------------------ */
    /* Utilidades                                                          */
    /* ------------------------------------------------------------------ */

    private function rangeSeconds($range)
    {
        switch ($range) {
            case '7d':  return 7 * 86400;
            case '90d': return 90 * 86400;
            case '12m': return 365 * 86400;
            case 'ytd': return max(86400, time() - strtotime(gmdate('Y') . '-01-01 00:00:00'));
            case '30d':
            default:    return 30 * 86400;
        }
    }

    /** Convierte una fecha ISO a timestamp UTC, o false. */
    private static function toTimestamp($value)
    {
        if (!$value)
            return false;

        $ts = strtotime($value . ' UTC');
        return $ts ?: false;
    }

    /** ¿El agente actual puede ver las métricas de todo el equipo? */
    public function canSeeOthers()
    {
        return $this->seeAgents;
    }

    public function getStart() { return $this->start; }
    public function getEnd() { return $this->end; }

    public function getStartDate() { return gmdate('Y-m-d', $this->start); }
    public function getEndDate()   { return gmdate('Y-m-d', $this->end); }

    public static function getRangeChoices()
    {
        $choices = [];
        foreach (self::$ranges as $key => $label)
            $choices[$key] = __($label);
        return $choices;
    }

    /** SQL literal para el inicio del periodo. */
    private function sqlStart()
    {
        return sprintf("'%s'", gmdate('Y-m-d H:i:s', $this->start));
    }

    /** SQL literal para el fin del periodo. */
    private function sqlEnd()
    {
        return sprintf("'%s'", gmdate('Y-m-d H:i:s', $this->end));
    }

    /**
     * Restricción de departamentos para las consultas directas.
     * Devuelve '' si el agente puede verlo todo.
     */
    private function deptClause($column = 't.dept_id')
    {
        if ($this->depts === null)
            return '';

        if (!$this->depts)
            return ' AND 1=0';

        return sprintf(' AND %s IN (%s)', $column,
            implode(',', array_map('intval', $this->depts)));
    }

    /** Equivalente para el ORM. */
    private function deptFilter()
    {
        if ($this->depts === null)
            return [];

        if (!$this->depts)
            return ['dept_id__in' => [-1]];

        return ['dept_id__in' => array_map('intval', $this->depts)];
    }

    /* ------------------------------------------------------------------ */
    /* KPIs principales                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Resumen del periodo.
     *
     * @return array{
     *   created:int,closed:int,open:int,overdue:int,unanswered:int,
     *   reopened:int,first_response_h:?float,resolution_h:?float,
     *   sla_met:int,sla_breached:int,sla_pct:?float,
     *   backlog_avg:?float,per_day:float,trend:?float
     * }
     */
    public function getSummary()
    {
        $start = $this->sqlStart();
        $end = $this->sqlEnd();
        $dept = $this->deptClause();
        $dept0 = $this->deptClause('t0.dept_id');

        // Volumen del periodo
        $sql = 'SELECT
                COUNT(*) AS created,
                SUM(t.closed IS NOT NULL
                    AND t.closed BETWEEN '.$start.' AND '.$end.') AS closed,
                SUM(t.reopened IS NOT NULL) AS reopened
            FROM '.TICKET_TABLE.' t
            WHERE t.created BETWEEN '.$start.' AND '.$end.$dept;
        $row = db_fetch_array(db_query($sql)) ?: [];

        // Estado actual: no se limita al periodo, porque "abierto ahora"
        // incluye también lo que viene de meses anteriores.
        $sql = 'SELECT
                COUNT(*) AS open_now,
                SUM(t.isoverdue = 1) AS overdue,
                SUM(t.isanswered = 0) AS unanswered
            FROM '.TICKET_TABLE.' t
            WHERE t.status_id IN (
                    SELECT id FROM '.TABLE_PREFIX.'ticket_status
                     WHERE state IN (\'open\', \'in_progress\'))'.$dept;
        $current = db_fetch_array(db_query($sql)) ?: [];

        // Tiempos: primera respuesta (agente) y resolución.
        //
        // La subconsulta se limita a los tickets del periodo antes de recorrer
        // thread/thread_entry: sin ese filtro la tabla derivada se
        // materializaría completa en instalaciones grandes.
        $sql = 'SELECT
                AVG(TIMESTAMPDIFF(MINUTE, t.created, e.first_response) / 60) AS frt,
                AVG(TIMESTAMPDIFF(MINUTE, t.created, t.closed) / 60) AS mttr
            FROM '.TICKET_TABLE.' t
            LEFT JOIN (
                SELECT th.object_id AS ticket_id,
                       MIN(te.created) AS first_response
                  FROM '.TICKET_TABLE.' t0
                  JOIN '.THREAD_TABLE.' th
                    ON th.object_id = t0.ticket_id AND th.object_type = \'T\'
                  JOIN '.THREAD_ENTRY_TABLE.' te
                    ON te.thread_id = th.id AND te.type = \'R\' AND te.staff_id > 0
                 WHERE t0.created BETWEEN '.$start.' AND '.$end.$dept0.'
                 GROUP BY th.object_id
            ) e ON e.ticket_id = t.ticket_id
            WHERE t.created BETWEEN '.$start.' AND '.$end.$dept;
        $times = db_fetch_array(db_query($sql)) ?: [];

        // SLA: comparación entre la fecha de vencimiento y el cierre
        $sql = 'SELECT
                SUM(t.duedate IS NOT NULL AND (t.closed IS NULL OR t.closed <= t.duedate)) AS met,
                SUM(t.duedate IS NOT NULL AND t.closed IS NOT NULL AND t.closed > t.duedate) AS breached
            FROM '.TICKET_TABLE.' t
            WHERE t.created BETWEEN '.$start.' AND '.$end.$dept;
        $sla = db_fetch_array(db_query($sql)) ?: [];

        // Tendencia: mitad reciente contra mitad anterior del periodo
        $mid = gmdate('Y-m-d H:i:s', (int) (($this->start + $this->end) / 2));
        $sql = 'SELECT
                SUM(t.created >= \''.$mid.'\') AS recent,
                SUM(t.created < \''.$mid.'\') AS previous
            FROM '.TICKET_TABLE.' t
            WHERE t.created BETWEEN '.$start.' AND '.$end.$dept;
        $trend = db_fetch_array(db_query($sql)) ?: [];

        $created = (int) ($row['created'] ?? 0);
        $closed = (int) ($row['closed'] ?? 0);
        $met = (int) ($sla['met'] ?? 0);
        $breached = (int) ($sla['breached'] ?? 0);
        $days = max(1, (int) ceil(($this->end - $this->start) / 86400));

        $recent = (int) ($trend['recent'] ?? 0);
        $previous = (int) ($trend['previous'] ?? 0);

        return [
            'created' => $created,
            'closed' => $closed,
            'open' => (int) ($current['open_now'] ?? 0),
            'overdue' => (int) ($current['overdue'] ?? 0),
            'unanswered' => (int) ($current['unanswered'] ?? 0),
            'reopened' => (int) ($row['reopened'] ?? 0),
            'first_response_h' => isset($times['frt']) ? round((float) $times['frt'], 1) : null,
            'resolution_h' => isset($times['mttr']) ? round((float) $times['mttr'], 1) : null,
            'sla_met' => $met,
            'sla_breached' => $breached,
            'sla_pct' => ($met + $breached) > 0
                ? round(100 * $met / ($met + $breached), 1) : null,
            'backlog_avg' => round($created / $days, 1),
            'per_day' => round($created / $days, 1),
            'trend' => $previous > 0
                ? round(100 * ($recent - $previous) / $previous, 1)
                : ($recent > 0 ? 100.0 : null),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Series temporales                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Serie diaria de creados y cerrados, rellenando los días sin datos.
     *
     * @return array{labels:array<string>,created:array<int>,closed:array<int>}
     */
    public function getDailySeries()
    {
        $start = $this->sqlStart();
        $end = $this->sqlEnd();
        $dept = $this->deptClause();

        $created = [];
        $res = db_query('SELECT DATE(t.created) AS d, COUNT(*) AS n
            FROM '.TICKET_TABLE.' t
            WHERE t.created BETWEEN '.$start.' AND '.$end.$dept.'
            GROUP BY DATE(t.created)');
        while ($row = db_fetch_array($res))
            $created[$row['d']] = (int) $row['n'];

        $closed = [];
        $res = db_query('SELECT DATE(t.closed) AS d, COUNT(*) AS n
            FROM '.TICKET_TABLE.' t
            WHERE t.closed BETWEEN '.$start.' AND '.$end.$dept.'
            GROUP BY DATE(t.closed)');
        while ($row = db_fetch_array($res))
            $closed[$row['d']] = (int) $row['n'];

        // Relleno continuo (imprescindible para que las gráficas no mientan)
        $labels = $c = $cl = [];
        $cursor = strtotime(gmdate('Y-m-d', $this->start));
        $last = strtotime(gmdate('Y-m-d', $this->end));
        while ($cursor <= $last) {
            $day = gmdate('Y-m-d', $cursor);
            $labels[] = $day;
            $c[] = $created[$day] ?? 0;
            $cl[] = $closed[$day] ?? 0;
            $cursor += 86400;
        }

        return ['labels' => $labels, 'created' => $c, 'closed' => $cl];
    }

    /**
     * Distribución por hora del día (0-23) según la creación del ticket.
     * Útil para dimensionar turnos.
     */
    public function getHourlyDistribution()
    {
        $dept = $this->deptClause();
        $buckets = array_fill(0, 24, 0);

        $res = db_query('SELECT HOUR(t.created) AS h, COUNT(*) AS n
            FROM '.TICKET_TABLE.' t
            WHERE t.created BETWEEN '.$this->sqlStart().' AND '.$this->sqlEnd().$dept.'
            GROUP BY HOUR(t.created)');
        while ($row = db_fetch_array($res))
            $buckets[(int) $row['h']] = (int) $row['n'];

        return $buckets;
    }

    /* ------------------------------------------------------------------ */
    /* Desgloses                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Rendimiento por agente.
     *
     * @param int $limit Número máximo de filas
     * @return array<int,array<string,mixed>>
     */
    public function getAgentBreakdown($limit = 20)
    {
        $dept = $this->deptClause();
        $rows = [];

        $sql = 'SELECT s.staff_id, CONCAT_WS(\' \', s.firstname, s.lastname) AS name,
                COUNT(t.ticket_id) AS assigned,
                SUM(t.closed IS NOT NULL AND t.closed BETWEEN '.$this->sqlStart()
                    .' AND '.$this->sqlEnd().') AS closed,
                SUM(t.isoverdue = 1) AS overdue,
                ROUND(AVG(TIMESTAMPDIFF(MINUTE, t.created, t.closed) / 60), 1) AS mttr
            FROM '.TICKET_TABLE.' t
            JOIN '.STAFF_TABLE.' s ON s.staff_id = t.staff_id
            WHERE t.created BETWEEN '.$this->sqlStart().' AND '.$this->sqlEnd().$dept.'
              AND t.staff_id > 0
            GROUP BY s.staff_id, s.firstname, s.lastname
            ORDER BY closed DESC, assigned DESC
            LIMIT '.(int) $limit;

        $res = db_query($sql);
        while ($row = db_fetch_array($res)) {
            $rows[] = [
                'id' => (int) $row['staff_id'],
                'name' => $row['name'],
                'assigned' => (int) $row['assigned'],
                'closed' => (int) $row['closed'],
                'overdue' => (int) $row['overdue'],
                'avg_resolution_h' => $row['mttr'] !== null ? (float) $row['mttr'] : null,
            ];
        }

        // Sin permiso para ver a otros agentes: sólo sus propias cifras
        if (!$this->seeAgents && $this->staffId) {
            $rows = array_values(array_filter($rows, function ($r) {
                return $r['id'] === (int) $this->staffId;
            }));
        }

        return $rows;
    }

    /**
     * Rendimiento por departamento.
     */
    public function getDepartmentBreakdown()
    {
        $dept = $this->deptClause('t.dept_id');
        $rows = [];

        $sql = 'SELECT d.id, d.name,
                COUNT(t.ticket_id) AS created,
                SUM(t.closed IS NOT NULL AND t.closed BETWEEN '.$this->sqlStart()
                    .' AND '.$this->sqlEnd().') AS closed,
                SUM(t.isoverdue = 1) AS overdue,
                SUM(t.isanswered = 0) AS unanswered,
                ROUND(AVG(TIMESTAMPDIFF(MINUTE, t.created, t.closed) / 60), 1) AS mttr
            FROM '.TICKET_TABLE.' t
            JOIN '.DEPT_TABLE.' d ON d.id = t.dept_id
            WHERE t.created BETWEEN '.$this->sqlStart().' AND '.$this->sqlEnd().$dept.'
            GROUP BY d.id, d.name
            ORDER BY created DESC';

        $res = db_query($sql);
        while ($row = db_fetch_array($res)) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'created' => (int) $row['created'],
                'closed' => (int) $row['closed'],
                'overdue' => (int) $row['overdue'],
                'unanswered' => (int) $row['unanswered'],
                'avg_resolution_h' => $row['mttr'] !== null ? (float) $row['mttr'] : null,
            ];
        }

        return $rows;
    }

    /**
     * Distribución por origen (Web, Email, API, Phone...).
     */
    public function getSourceBreakdown()
    {
        $dept = $this->deptClause();
        $rows = [];

        $res = db_query('SELECT t.source, COUNT(*) AS n
            FROM '.TICKET_TABLE.' t
            WHERE t.created BETWEEN '.$this->sqlStart().' AND '.$this->sqlEnd().$dept.'
            GROUP BY t.source ORDER BY n DESC');
        while ($row = db_fetch_array($res))
            $rows[] = ['source' => $row['source'] ?: 'Other', 'count' => (int) $row['n']];

        return $rows;
    }

    /**
     * Temas más frecuentes (help topics).
     */
    public function getTopicBreakdown($limit = 10)
    {
        $dept = $this->deptClause();
        $rows = [];

        $res = db_query('SELECT ht.topic AS name, COUNT(*) AS n
            FROM '.TICKET_TABLE.' t
            JOIN '.TOPIC_TABLE.' ht ON ht.topic_id = t.topic_id
            WHERE t.created BETWEEN '.$this->sqlStart().' AND '.$this->sqlEnd().$dept.'
            GROUP BY ht.topic ORDER BY n DESC LIMIT '.(int) $limit);
        while ($row = db_fetch_array($res))
            $rows[] = ['name' => $row['name'], 'count' => (int) $row['n']];

        return $rows;
    }

    /**
     * Antigüedad del trabajo pendiente: cuántos tickets abiertos llevan
     * menos de 1 día, 1-3, 3-7, 7-30 y más de 30 días sin cerrar.
     */
    public function getBacklogAge()
    {
        $dept = $this->deptClause();
        $buckets = [
            '< 1 day' => 0, '1-3 days' => 0, '3-7 days' => 0,
            '7-30 days' => 0, '> 30 days' => 0,
        ];

        $sql = 'SELECT
                SUM(DATEDIFF(NOW(), t.created) < 1) AS b1,
                SUM(DATEDIFF(NOW(), t.created) BETWEEN 1 AND 2) AS b2,
                SUM(DATEDIFF(NOW(), t.created) BETWEEN 3 AND 6) AS b3,
                SUM(DATEDIFF(NOW(), t.created) BETWEEN 7 AND 29) AS b4,
                SUM(DATEDIFF(NOW(), t.created) >= 30) AS b5
            FROM '.TICKET_TABLE.' t
            WHERE t.status_id IN (
                SELECT id FROM '.TABLE_PREFIX.'ticket_status
                 WHERE state IN (\'open\', \'in_progress\'))'.$dept;
        $row = db_fetch_array(db_query($sql)) ?: [];

        $keys = array_keys($buckets);
        $values = [(int) ($row['b1'] ?? 0), (int) ($row['b2'] ?? 0),
            (int) ($row['b3'] ?? 0), (int) ($row['b4'] ?? 0), (int) ($row['b5'] ?? 0)];
        $buckets = array_combine($keys, $values);

        return $buckets;
    }

    /**
     * Carga de trabajo actual por agente (tickets abiertos asignados).
     */
    public function getWorkload()
    {
        $dept = $this->deptClause();
        $rows = [];

        $sql = 'SELECT s.staff_id, CONCAT_WS(\' \', s.firstname, s.lastname) AS name,
                COUNT(t.ticket_id) AS open_tickets,
                SUM(t.isoverdue = 1) AS overdue
            FROM '.TICKET_TABLE.' t
            JOIN '.STAFF_TABLE.' s ON s.staff_id = t.staff_id
            WHERE t.status_id IN (
                    SELECT id FROM '.TABLE_PREFIX.'ticket_status
                     WHERE state IN (\'open\', \'in_progress\'))
              AND t.staff_id > 0'.$dept.'
            GROUP BY s.staff_id, s.firstname, s.lastname
            ORDER BY open_tickets DESC
            LIMIT 20';

        $res = db_query($sql);
        while ($row = db_fetch_array($res)) {
            $rows[] = [
                'id' => (int) $row['staff_id'],
                'name' => $row['name'],
                'open' => (int) $row['open_tickets'],
                'overdue' => (int) $row['overdue'],
            ];
        }

        return $rows;
    }

    /**
     * Exporta un desglose a CSV (mismas columnas que la tabla en pantalla).
     */
    public static function toCsv(array $rows, array $columns, $delimiter = ',')
    {
        $fh = fopen('php://output', 'w');
        // BOM para que Excel respete los acentos
        fwrite($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($fh, $columns, $delimiter, '"', '');
        foreach ($rows as $row)
            fputcsv($fh, array_values($row), $delimiter, '"', '');
        fclose($fh);
    }

    /**
     * Serializa todo el informe (para el endpoint JSON de la app móvil).
     */
    public function toArray()
    {
        return [
            'range' => [
                'start' => $this->getStartDate(),
                'end' => $this->getEndDate(),
            ],
            'summary' => $this->getSummary(),
            'daily' => $this->getDailySeries(),
            'hourly' => $this->getHourlyDistribution(),
            'agents' => $this->getAgentBreakdown(),
            'departments' => $this->getDepartmentBreakdown(),
            'sources' => $this->getSourceBreakdown(),
            'topics' => $this->getTopicBreakdown(),
            'backlog_age' => $this->getBacklogAge(),
            'workload' => $this->getWorkload(),
        ];
    }
}

/**
 * Gráficas en SVG generadas en el servidor.
 *
 * No depende de JavaScript ni de librerías externas: se ve igual en el
 * navegador, en el correo y en una app móvil que consuma el HTML.
 */
class AnalyticsChart
{
    /**
     * Gráfica de líneas con dos series (creados y cerrados).
     *
     * @param array $series Salida de Analytics::getDailySeries()
     * @param int   $w      Ancho del viewBox
     * @param int   $h      Alto del viewBox
     * @return string SVG
     */
    public static function lineSvg(array $series, $w = 720, $h = 220)
    {
        $labels = $series['labels'] ?? [];
        $a = $series['created'] ?? [];
        $b = $series['closed'] ?? [];
        $n = count($labels);

        if (!$n)
            return '<p class="an-empty">' . __('No data for the selected period') . '</p>';

        $padL = 34; $padR = 8; $padT = 10; $padB = 22;
        $plotW = max(1, $w - $padL - $padR);
        $plotH = max(1, $h - $padT - $padB);

        $max = max(1, max($a ?: [0]), max($b ?: [0]));
        // Redondeo hacia arriba a un valor "bonito" para el eje
        $step = max(1, (int) ceil($max / 4));
        $top = $step * 4;

        $x = function ($i) use ($padL, $plotW, $n) {
            return $n === 1 ? $padL + $plotW / 2 : $padL + ($plotW * $i / ($n - 1));
        };
        $y = function ($v) use ($padT, $plotH, $top) {
            return $padT + $plotH - ($plotH * min($v, $top) / $top);
        };

        $svg = sprintf('<svg class="an-chart" viewBox="0 0 %d %d" role="img" '
            . 'aria-label="%s">',
            $w, $h, htmlspecialchars(__('Tickets created vs. closed per day')));

        // Rejilla y etiquetas del eje Y
        for ($i = 0; $i <= 4; $i++) {
            $value = $step * $i;
            $yy = $y($value);
            $svg .= sprintf('<line class="grid" x1="%d" y1="%.1f" x2="%d" y2="%.1f" />',
                $padL, $yy, $w - $padR, $yy);
            $svg .= sprintf('<text class="axis" x="%d" y="%.1f" text-anchor="end">%d</text>',
                $padL - 6, $yy + 3, $value);
        }

        // Series
        $pointsA = $pointsB = [];
        foreach ($a as $i => $v)
            $pointsA[] = sprintf('%.1f,%.1f', $x($i), $y($v));
        foreach ($b as $i => $v)
            $pointsB[] = sprintf('%.1f,%.1f', $x($i), $y($v));

        // Relleno bajo la serie "creados": se cierra el polígono en la base
        $base = $padT + $plotH;
        if (count($pointsA) > 1) {
            $firstX = $x(0);
            $lastX = $x(count($pointsA) - 1);
            $svg .= sprintf(
                '<polygon class="area-created" points="%.1f,%.1f %.1f,%.1f %s" />',
                $firstX, $base, $lastX, $base, implode(' ', $pointsA));
        }
        if ($pointsB)
            $svg .= sprintf('<polyline class="line-closed" points="%s" />',
                implode(' ', $pointsB));
        if ($pointsA)
            $svg .= sprintf('<polyline class="line-created" points="%s" />',
                implode(' ', $pointsA));

        // Fechas del eje X (máximo 6 marcas para no saturar)
        $ticks = min(6, $n);
        for ($t = 0; $t < $ticks; $t++) {
            $idx = $ticks === 1 ? 0 : (int) round($t * ($n - 1) / ($ticks - 1));
            $label = $labels[$idx];
            // dd/mm en periodos cortos, mm/aa en largos
            $label = $n <= 45
                ? gmdate('d/m', strtotime($label . ' UTC'))
                : gmdate('m/y', strtotime($label . ' UTC'));
            $svg .= sprintf('<text class="axis" x="%.1f" y="%d" text-anchor="middle">%s</text>',
                $x($idx), $h - 6, $label);
        }

        return $svg . '</svg>';
    }

    /**
     * Histograma de 24 horas.
     *
     * @param array<int,int> $hours Conteo por hora (0-23)
     * @return string HTML
     */
    public static function hourBars(array $hours)
    {
        $max = max(1, max($hours ?: [0]));
        $peak = array_keys($hours, max($hours ?: [0]))[0] ?? null;
        $html = '<div class="an-hours" role="img" aria-label="'
            . htmlspecialchars(__('Tickets created by hour of day')) . '">';

        for ($i = 0; $i < 24; $i++) {
            $v = (int) ($hours[$i] ?? 0);
            $height = $v > 0 ? max(4, round(100 * $v / $max)) : 2;
            $title = sprintf('%02d:00 — %d', $i, $v);
            $html .= sprintf(
                '<span class="h%s" style="height:%d%%" title="%s" aria-label="%s"></span>',
                ($v > 0 && $i === $peak) ? ' is-peak' : '',
                $height, htmlspecialchars($title), htmlspecialchars($title));
        }
        $html .= '</div><div class="an-hour-axis" aria-hidden="true">';
        for ($i = 0; $i < 24; $i++)
            $html .= '<span>' . $i . '</span>';
        return $html . '</div>';
    }

    /**
     * Barras horizontales a partir de pares etiqueta/valor.
     *
     * @param array $rows  [['label'=>string,'value'=>int,'tone'=>?string], ...]
     * @param int   $total Denominador; si es null se usa el máximo
     * @return string HTML
     */
    public static function barList(array $rows, $total = null)
    {
        if (!$rows)
            return '<p class="an-empty">' . __('No data for the selected period') . '</p>';

        $values = array_map(function ($r) { return (int) $r['value']; }, $rows);
        $denom = $total ?: max(1, max($values));
        $html = '<div class="an-bars">';

        foreach ($rows as $row) {
            $value = (int) $row['value'];
            $pct = $denom > 0 ? min(100, round(100 * $value / $denom)) : 0;
            $tone = !empty($row['tone']) ? ' is-' . $row['tone'] : '';
            $html .= '<div class="an-bar-row">';
            $html .= '<span class="an-bar-label" title="' . htmlspecialchars($row['label'])
                . '">' . htmlspecialchars($row['label']) . '</span>';
            $html .= '<span class="an-bar-track"><span class="an-bar-fill' . $tone
                . '" style="width:' . $pct . '%"></span></span>';
            $html .= '<span class="an-bar-value">' . ($row['display'] ?? $value)
                . '</span>';
            $html .= '</div>';
        }

        return $html . '</div>';
    }
}
