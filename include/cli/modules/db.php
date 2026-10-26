<?php
/*********************************************************************
    include/cli/modules/db.php

    Mantenimiento de la base de datos: las tablas materializadas de los
    formularios dinámicos.

        php manage.php db --views           # cómo están ahora
        php manage.php db --rebuild-views    # las reconstruye

    Qué son esas tablas: `ost_ticket__cdata`, `ost_task__cdata`,
    `ost_user__cdata` y `ost_organization__cdata` no las escribe la aplicación
    fila a fila. Se crean con `CREATE TABLE … AS SELECT` sobre
    `form_entry_values` (`DynamicForm::buildDynamicDataView`), y la aplicación
    sólo las *actualiza* cuando se guarda una respuesta y la tabla ya existía.

    Por eso pueden quedarse a medias: si la tabla se creó cuando había pocos
    tickets, los anteriores no están; si el proceso se cortó, tampoco. El
    daño visible no es «faltan datos en un informe», es que **desaparecen
    tickets**: las columnas de las colas (Subject, Priority…) hacen un JOIN
    interno contra esa tabla, así que un ticket sin fila no se lista en
    ninguna cola, mientras el recuento cacheado —que no usa columnas— lo sigue
    contando. De ahí «Showing 1 - 23 of about 23» con la tabla vacía.

    Reconstruirlas es seguro y no toca los datos: se vuelven a derivar de
    `form_entry_values`, que es la fuente de la verdad.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

class DbMaintenance extends Module {

    var $prologue = 'Mantenimiento de la base: vistas materializadas de los formularios';

    var $arguments = array();

    var $options = array(
        'views' => array('-V', '--views', 'action' => 'store_true',
            'help' => 'Muestra el estado de las tablas materializadas (por omisión)'),
        'rebuild-views' => array('-R', '--rebuild-views', 'action' => 'store_true',
            'help' => 'Reconstruye las tablas materializadas desde form_entry_values'),
        'quiet' => array('-q', '--quiet', 'action' => 'store_true',
            'help' => 'Sólo avisa de los errores'),
    );

    var $quiet = false;

    /** Las cuatro vistas y qué las alimenta. */
    static function views() {
        return array(
            'ticket' => array('table' => TICKET_CDATA_TABLE,
                'source' => FORM_ENTRY_TABLE, 'type' => 'T',
                'class' => 'TicketForm'),
            'task' => array('table' => TASK_CDATA_TABLE,
                'source' => FORM_ENTRY_TABLE, 'type' => 'A',
                'class' => 'TaskForm'),
            'user' => array('table' => USER_CDATA_TABLE,
                'source' => FORM_ENTRY_TABLE, 'type' => 'U',
                'class' => 'UserForm'),
            'organization' => array('table' => ORGANIZATION_CDATA_TABLE,
                'source' => FORM_ENTRY_TABLE, 'type' => 'O',
                'class' => 'OrganizationForm'),
        );
    }

    function run($args, $options) {
        global $ost, $cfg;

        $this->quiet = (bool) $this->getOption('quiet');

        if (function_exists('mysqli_report') && defined('MYSQLI_REPORT_OFF'))
            mysqli_report(MYSQLI_REPORT_OFF);

        require_once INCLUDE_DIR . 'class.dynamic_forms.php';
        require_once INCLUDE_DIR . 'class.ticket.php';
        require_once INCLUDE_DIR . 'class.task.php';
        require_once INCLUDE_DIR . 'class.organization.php';

        try {
            Bootstrap::connect();
            $ost = osTicket::start();
        }
        catch (Throwable $e) {
            $this->err('No se pudo abrir la base de datos: ' . $e->getMessage());
            $this->err('  docker compose ps db');
            return 2;
        }

        if (!$ost || !($cfg = $ost->getConfig()))
            $this->fail('No se pudo leer la configuración de la base de datos');

        if ($this->getOption('rebuild-views'))
            return $this->rebuild();

        $this->report($this->state());
        $this->out('');
        $this->out('Reconstruir:  php manage.php db --rebuild-views');
        return 0;
    }

    /** Estado de cada vista: filas que tiene y filas que debería tener. */
    function state() {
        $state = array();
        foreach (self::views() as $name => $view) {
            $exists = $this->tableExists($view['table']);
            $state[$name] = array(
                'table' => $view['table'],
                'exists' => $exists,
                'rows' => $exists ? $this->scalar('SELECT COUNT(*) FROM '
                    . $view['table']) : null,
                'expected' => $this->scalar('SELECT COUNT(DISTINCT object_id) FROM '
                    . $view['source'] . " WHERE object_type = '"
                    . $view['type'] . "'"),
            );
        }

        return $state;
    }

    /** Cuántas filas faltan en total y en qué tablas. */
    static function deficit($state) {
        $missing = 0;
        $tables = array();
        foreach ($state as $view) {
            if ($view['rows'] === null || $view['expected'] === null)
                continue;
            if ($view['rows'] < $view['expected']) {
                $missing += $view['expected'] - $view['rows'];
                $tables[] = $view['table'];
            }
        }

        return array('rows' => $missing, 'tables' => $tables);
    }

    function report($state) {
        $rows = array();
        foreach ($state as $name => $view) {
            $rows[] = array(
                $view['table'],
                $view['exists'] ? 'sí' : 'NO',
                $view['rows'] === null ? '—' : $view['rows'],
                $view['expected'] === null ? '—' : $view['expected'],
                ($view['rows'] !== null && $view['expected'] !== null
                    && $view['rows'] < $view['expected']) ? 'faltan filas' : '',
            );
        }

        $this->out('');
        $this->out('Tablas materializadas de los formularios');
        $this->out('--------------------------------------');
        $this->table(array('Tabla', 'Existe', 'Filas', 'Debería tener', 'Aviso'),
            $rows);

        $deficit = self::deficit($state);

        if ($deficit['rows']) {
            $this->out('');
            $this->out('Faltan ' . $deficit['rows'] . ' filas. Los registros que no están en'
                .' estas tablas no');
            $this->out('se listan en las colas ni en los informes que las usan:');
            $this->out('  php manage.php db --rebuild-views');
        }
    }

    function rebuild() {
        $before = $this->state();

        if (!$this->quiet) {
            $this->out('Antes de reconstruir:');
            $this->report($before);
            $this->out('');
        }

        $this->out('Reconstruyendo las tablas materializadas…');
        // `rebuildDynamicDataViews()` vacía el caché de modelos, borra cada
        // tabla y la vuelve a crear desde `form_entry_values`.
        DynamicForm::rebuildDynamicDataViews();

        $after = $this->state();
        $this->out('');
        $this->report($after);

        $deficit = self::deficit($after);

        $this->out('');
        if ($deficit['rows']) {
            $this->out('Todavía faltan ' . $deficit['rows'] . ' filas en '
                . implode(', ', $deficit['tables']) . ':');
            $this->out('la fuente es `form_entry_values`, así que los registros sin');
            $this->out('formulario no se pueden recuperar (volver a crearlos).');
            return 1;
        }

        $this->out('Listo: las tablas coinciden con los formularios guardados.');
        return 0;
    }

    function tableExists($table) {
        try {
            return (bool) db_num_rows(db_query("SHOW TABLES LIKE '" . $table . "'", false));
        }
        catch (Throwable $e) {
            return false;
        }
    }

    function scalar($sql) {
        try {
            if (!($res = db_query($sql, false)))
                return null;
            $row = db_fetch_row($res);
            db_free_result($res);
            return (is_array($row) && isset($row[0])) ? (int) $row[0] : null;
        }
        catch (Throwable $e) {
            return null;
        }
    }

    function table($headers, $rows) {
        $widths = array();
        foreach ($headers as $i => $header)
            $widths[$i] = strlen($header);
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

    function out($text) {
        if (!$this->quiet)
            $this->stdout->write($text . "\n");
    }
}

Module::register('db', 'DbMaintenance');
