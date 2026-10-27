<?php
/*********************************************************************
    class.search.mysql.php

    El backend de busqueda de MySQL (tabla FULLTEXT, indice por lotes) y su
    configuracion.

    Parte de include/class.search.php (frente 6 de docs/CODE_QUALITY.md): el
    archivo tenia 1.955 lineas y esta clase sola ocupaba 480. Se movieron las
    dos clases enteras, sin tocar su codigo, y con ellas el registro del
    backend (`Signal::connect()` y `MysqlSearchBackend::register()`), que tiene
    que seguir ocurriendo al cargar el archivo. `class.search.php` incluye este
    al final, porque `MysqlSearchBackend` hereda de `SearchBackend`, que sigue
    alli.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

require_once(INCLUDE_DIR.'class.config.php');

class MySqlSearchConfig extends Config {
    var $table = CONFIG_TABLE;

    function __construct() {
        parent::__construct("mysqlsearch");
    }
}

class MysqlSearchBackend extends SearchBackend {
    static $id = 'mysql';
    static $BATCH_SIZE = 30;

    // Only index 20 batches per cron run
    var $max_batches = 60;
    var $_reindexed = 0;
    var $SEARCH_TABLE;

    function __construct() {
        $this->SEARCH_TABLE = TABLE_PREFIX . '_search';
    }

    function getConfig() {
        if (!isset($this->config))
            $this->config = new MySqlSearchConfig();
        return $this->config;
    }


    function bootstrap() {
        if ($this->getConfig()->get('reindex', true))
            Signal::connect('cron', array($this, 'IndexOldStuff'));
    }

    function update($model, $id, $content, $new=false, $attrs=array()) {
        if (!($type=ObjectModel::getType($model)))
            return;

        if ($model instanceof Ticket)
            $attrs['title'] = $attrs['number'].' '.$attrs['title'];
        elseif ($model instanceof User)
            $content .=' '.implode("\n", $attrs['emails']);

        $title = $attrs['title'] ?: '';

        if (!$content && !$title)
            return;
        if (!$id)
            return;

        $sql = 'REPLACE INTO '.$this->SEARCH_TABLE
            . ' SET object_type='.db_input($type)
            . ', object_id='.db_input($id)
            . ', content='.db_input($content)
            . ', title='.db_input($title);
        // sql-audit:legacy índice FULLTEXT, fuera del ORM
        return db_query($sql, false);
    }

    function delete($model) {
        switch (true) {
        case $model instanceof Thread:
            $sql = 'DELETE s.* FROM '.$this->SEARCH_TABLE
                . " s JOIN ".THREAD_ENTRY_TABLE." h ON (h.id = s.object_id) "
                . " WHERE s.object_type='H'"
                . ' AND h.thread_id='.db_input($model->getId());
            // sql-audit:legacy borra del índice FULLTEXT con JOIN, sin modelo
            return db_query($sql);

        default:
            if (!($type = ObjectModel::getType($model)))
                return;

            $sql = 'DELETE FROM '.$this->SEARCH_TABLE
                . ' WHERE object_type='.db_input($type)
                . ' AND object_id='.db_input($model->getId());
            // sql-audit:legacy borra del índice FULLTEXT, sin modelo
            return db_query($sql);
        }
    }

    // Quote things like email addresses
    function quote($query) {
        $parts = array();
        if (!preg_match_all('`(?:([^\s"\']+)|"[^"]*"|\'[^\']*\')(\s*)`', $query, $parts,
                PREG_SET_ORDER))
            return $query;

        $results = array();
        foreach ($parts as $m) {
            // Check for quoting
            if ($m[1] // Already quoted?
                && preg_match('`@`u', $m[0])
            ) {
                $char = strpos($m[1], '"') ? "'" : '"';
                $m[0] = $char . $m[0] . $char;
            }
            $results[] = $m[0].$m[2];
        }
        return implode('', $results);
    }

    function find($query, QuerySet $criteria, $addRelevance=true, $options=array()) {
        global $thisstaff;

        // MySQL usually doesn't handle words shorter than three letters
        // (except with special configuration)
        if (strlen($query) < 3)
            return $criteria;

        $criteria = clone $criteria;

        $mode = ' IN NATURAL LANGUAGE MODE';

        // Allow boolean full-text syntax to be disabled
        $allow_boolean = !array_key_exists('boolean', $options)
            || $options['boolean'];

        // According to the MySQL full text boolean mode, this grammar is
        // assumed:
        // see http://dev.mysql.com/doc/refman/5.6/en/fulltext-boolean.html
        //
        // PREOP    = [<>~+-]
        // POSTOP   = [*]
        // WORD     = [\w][\w-]*
        // TERM     = PREOP? WORD POSTOP?
        // QWORD    = " [^"]+ "
        // PARENS   = \( { { TERM | QWORD } { \s+ { TERM | QWORD } }+ } \)
        // EXPR     = { PREOP? PARENS | TERM | QWORD }
        // BOOLEAN  = EXPR { \s+ EXPR }*
        //
        // Changing '{' for (?: and '}' for ')', collapsing whitespace, we
        // have this regular expression
        $BOOLEAN = '(?:[<>~+-]?\((?:(?:[<>~+-]?[\w][\w-]*[*]?|"[^"]+")(?:\s+(?:[<>~+-]?[\w][\w-]*[*]?|"[^"]+"))+)\)|[<>~+-]?[\w][\w-]*[*]?|"[^"]+")(?:\s+(?:[<>~+-]?\((?:(?:[<>~+-]?[\w][\w-]*[*]?|"[^"]+")(?:\s+(?:[<>~+-]?[\w][\w-]*[*]?|"[^"]+"))+)\)|[<>~+-]?[\w][\w-]*[*]?|"[^"]+"))*';

        // Require the use of at least one operator and conform to the
        // boolean mode grammar
        $T = array();
        if ($allow_boolean
            && preg_match('`(^|\s)["()<>~+-]`u', $query, $T)
            && preg_match("`^{$BOOLEAN}$`u", $query, $T)
        ) {
            // If using boolean operators, search in boolean mode. This regex
            // will ensure proper placement of operators, whitespace, and quotes
            // in an effort to avoid crashing the query at MySQL
            $query = $this->quote($query);
            $mode = ' IN BOOLEAN MODE';
        }
        #elseif (count(explode(' ', $query)) == 1)
        #    $mode = ' WITH QUERY EXPANSION';

        // Sanitize query to avoid possible SQL injection via parameter markers
        // This regex matches one or more colons followed by one or more digits,
        // and then replaces the match with only the digits (i.e. stripping the colon(s)).
        $query = preg_replace('/:+(\d+)/', '$1', $query);

        // escape query and using it as search
        $search = 'MATCH (Z1.title, Z1.content) AGAINST ('.db_input($query).$mode.')';

        switch ($criteria->model) {
        case false:
        case 'Ticket':
            if ($addRelevance) {
                $criteria = $criteria->extra(array(
                    'select' => array(
                        '__relevance__' => 'Z1.`relevance`',
                    ),
                ));
            }
            $criteria->extra(array(
                'tables' => array(
                    str_replace(array(':', '{}'), array(TABLE_PREFIX, $search),
                    "(SELECT COALESCE(Z3.`object_id`, Z5.`ticket_id`, Z8.`ticket_id`) as `ticket_id`, Z1.relevance FROM (SELECT Z1.`object_id`, Z1.`object_type`, {} AS `relevance` FROM `:_search` Z1 WHERE {} ORDER BY relevance DESC) Z1 LEFT JOIN `:thread_entry` Z2 ON (Z1.`object_type` = 'H' AND Z1.`object_id` = Z2.`id`) LEFT JOIN `:thread` Z3 ON (Z2.`thread_id` = Z3.`id` AND (Z3.`object_type` = 'T' OR Z3.`object_type` = 'C')) LEFT JOIN `:ticket` Z5 ON (Z1.`object_type` = 'T' AND Z1.`object_id` = Z5.`ticket_id`) LEFT JOIN `:user` Z6 ON (Z6.`id` = Z1.`object_id` and Z1.`object_type` = 'U') LEFT JOIN `:organization` Z7 ON (Z7.`id` = Z1.`object_id` AND Z7.`id` = Z6.`org_id` AND Z1.`object_type` = 'O') LEFT JOIN `:ticket` Z8 ON (Z8.`user_id` = Z6.`id`)) Z1"),
                ),
            ));
            $criteria->extra(array('order_by' => array(array(new SqlCode('Z1.relevance', 'DESC')))));

            $criteria->filter(array('ticket_id'=>new SqlCode('Z1.`ticket_id`')));
            break;

        case 'User':
            $criteria->extra(array(
                'select' => array(
                    '__relevance__' => 'Z1.`relevance`',
                ),
                'tables' => array(
                    str_replace(array(':', '{}'), array(TABLE_PREFIX, $search),
                    "(SELECT Z6.`id` as `user_id`, {} AS `relevance` FROM `:_search` Z1 LEFT JOIN `:user` Z6 ON (Z6.`id` = Z1.`object_id` and Z1.`object_type` = 'U') LEFT JOIN `:organization` Z7 ON (Z7.`id` = Z1.`object_id` AND Z7.`id` = Z6.`org_id` AND Z1.`object_type` = 'O') WHERE {}) Z1"),
                )
            ));
            $criteria->filter(array('id'=>new SqlCode('Z1.`user_id`')));
            break;

        case 'Organization':
            $criteria->extra(array(
                'select' => array(
                    '__relevance__' => 'Z1.`relevance`',
                ),
                'tables' => array(
                    str_replace(array(':', '{}'), array(TABLE_PREFIX, $search),
                    "(SELECT Z2.`id` as `org_id`, {} AS `relevance` FROM `:_search` Z1 LEFT JOIN `:organization` Z2 ON (Z2.`id` = Z1.`object_id` AND Z1.`object_type` = 'O') WHERE {}) Z1"),
                )
            ));
            $criteria->filter(array('id'=>new SqlCode('Z1.`org_id`')));
            break;
        }

        // TODO: Ensure search table exists;
        if (false) {
            // TODO: Create the search table automatically
            // $class::createSearchTable();
        }
        return $criteria;
    }

    static function createSearchTable() {
        // Use InnoDB with Galera, MyISAM with v5.5, and the database
        // default otherwise
        $sql = "select count(*) from information_schema.tables where
            table_schema='information_schema' and table_name =
            'INNODB_FT_CONFIG'";
        // sql-audit:legacy pregunta por el motor (MySQL 5.6 y Galera)
        $mysql56 = db_result(db_query($sql));

        $sql = "show status like 'wsrep_local_state'";
        // sql-audit:legacy SHOW STATUS, no hay modelo
        $galera = db_result(db_query($sql));

        if ($galera && !$mysql56)
            throw new Exception('Galera cannot be used with MyISAM tables. Upgrade to MariaDB 10 / MySQL 5.6 is required');
        $engine = $galera ? 'InnodB' : ($mysql56 ? '' : 'MyISAM');
        if ($engine)
            $engine = 'ENGINE='.$engine;

        $sql = 'CREATE TABLE IF NOT EXISTS '.TABLE_PREFIX."_search (
            `object_type` varchar(8) not null,
            `object_id` int(11) unsigned not null,
            `title` text collate utf8_general_ci,
            `content` text collate utf8_general_ci,
            primary key `object` (`object_type`, `object_id`),
            fulltext key `search` (`title`, `content`)
        ) $engine CHARSET=utf8";
        // sql-audit:legacy CREATE TABLE del índice de búsqueda
        if (!db_query($sql))
            return false;

        // Start rebuilding the index
        $config = new MySqlSearchConfig();
        $config->set('reindex', 1);
        return true;
    }

    /**
     * Cooperates with the cron system to automatically find content that is
     * not indexed in the _search table and add it to the index.
     */
    /**
     * Indexa lo que falte, un tipo de contenido por vez.
     *
     * Estaba en un solo método de 137 líneas con cinco bloques copiados: la
     * misma consulta «lo que no está en el índice», el mismo bucle y el mismo
     * guardado, cambiando sólo qué se lee y cómo se arma el registro. La
     * composición del registro vive en `SearchRecord` (con sus pruebas); aquí
     * quedan las consultas y el guardado.
     */
    function IndexOldStuff() {
        $class = get_class($this);
        $auto_create = function($db_error) use ($class) {

            if ($db_error != 1146)
                // Perform the standard error handling
                return true;

            // Create the search table automatically
            $class::__init();

        };

        // Cada tipo devuelve `false` cuando hay que parar: la consulta falló o
        // se acabó el número de lotes. En los dos casos se sale sin vaciar la
        // cola, como antes (el valor de retorno no lo usa nadie).
        if (!$this->indexThreadEntries($auto_create)
                || !$this->indexTickets($auto_create)
                || !$this->indexUsers($auto_create)
                || !$this->indexOrganizations($auto_create)
                || !$this->indexFaqs($auto_create))
            return false;

        // Flush non-full batch of records
        $this->__index(null, true);

        if (!$this->_reindexed) {
            // Stop rebuilding the index
            $this->getConfig()->set('reindex', 0);
        }
    }

    /**
     * Entradas del hilo que no están en el índice.
     *
     * @param callable $auto_create  lo que se hace si falta la tabla
     * @return bool  false para parar (fallo o lotes agotados)
     */
    private function indexThreadEntries($auto_create) {
        $sql = "SELECT A1.`id`, A1.`title`, A1.`body`, A1.`format` FROM `".THREAD_ENTRY_TABLE."` A1
            LEFT JOIN `".TABLE_PREFIX."_search` A2 ON (A1.`id` = A2.`object_id` AND A2.`object_type`='H')
            WHERE A2.`object_id` IS NULL AND (A1.poster <> 'SYSTEM')
            AND (IFNULL(LENGTH(A1.`title`), 0) + IFNULL(LENGTH(A1.`body`), 0) > 0)
            ORDER BY A1.`id` DESC LIMIT 500";
        if (!($res = db_query_unbuffered($sql, $auto_create)))
            return false;

        while ($row = db_fetch_row($res)) {
            $body = ThreadEntryBody::fromFormattedText($row[2], $row[3]);
            $record = SearchRecord::entry($row[0],
                Format::searchable($row[1]), $body->getSearchable());
            if (!$record)
                continue;
            if (!$this->__index($record))
                return false;
        }

        return true;
    }

    /**
     * Tickets que no están en el índice.
     *
     * @param callable $auto_create
     * @return bool  false para parar
     */
    private function indexTickets($auto_create) {
        $sql = "SELECT A1.`ticket_id` FROM `".TICKET_TABLE."` A1
            LEFT JOIN `".TABLE_PREFIX."_search` A2 ON (A1.`ticket_id` = A2.`object_id` AND A2.`object_type`='T')
            WHERE A2.`object_id` IS NULL
            ORDER BY A1.`ticket_id` DESC LIMIT 300";
        if (!($res = db_query_unbuffered($sql, $auto_create)))
            return false;

        while ($row = db_fetch_row($res)) {
            if (!($ticket = Ticket::lookup($row[0])))
                continue;
            $cdata = $ticket->loadDynamicData();
            $content = array();
            foreach ($cdata as $k=>$a)
                if ($k != 'subject' && ($v = $a->getSearchable()))
                    $content[] = $v;
            $record = SearchRecord::ticket($ticket->getId(), $ticket->getNumber(),
                $ticket->getSubject(), $content, array('Format', 'searchable'));
            if (!$this->__index($record))
                return false;
        }

        return true;
    }

    /**
     * Usuarios que no están en el índice.
     *
     * @param callable $auto_create
     * @return bool  false para parar
     */
    private function indexUsers($auto_create) {
        $sql = "SELECT A1.`id` FROM `".USER_TABLE."` A1
            LEFT JOIN `".TABLE_PREFIX."_search` A2 ON (A1.`id` = A2.`object_id` AND A2.`object_type`='U')
            WHERE A2.`object_id` IS NULL";
        if (!($res = db_query_unbuffered($sql, $auto_create)))
            return false;

        while ($row = db_fetch_row($res)) {
            $user = User::lookup($row[0]);
            $content = array();
            foreach ($user->emails as $e)
                $content[] = $e->address;
            foreach ($user->getDynamicData() as $entry)
                foreach ($entry->getAnswers() as $a)
                    if ($c = $a->getSearchable())
                        $content[] = $c;
            $record = SearchRecord::user($user->getId(), $user->getFullName(),
                array(), $content, array('Format', 'searchable'));
            if (!$this->__index($record))
                return false;
        }

        return true;
    }

    /**
     * Organizaciones que no están en el índice.
     *
     * @param callable $auto_create
     * @return bool  false para parar
     */
    private function indexOrganizations($auto_create) {
        $sql = "SELECT A1.`id` FROM `".ORGANIZATION_TABLE."` A1
            LEFT JOIN `".TABLE_PREFIX."_search` A2 ON (A1.`id` = A2.`object_id` AND A2.`object_type`='O')
            WHERE A2.`object_id` IS NULL";
        if (!($res = db_query_unbuffered($sql, $auto_create)))
            return false;

        while ($row = db_fetch_row($res)) {
            $org = Organization::lookup($row[0]);
            $content = array();
            foreach ($org->getDynamicData() as $entry)
                foreach ($entry->getAnswers() as $a)
                    if ($c = $a->getSearchable())
                        $content[] = $c;
            $record = SearchRecord::organization($org->getId(), $org->getName(),
                $content, array('Format', 'searchable'));
            if (!$this->__index($record))
                return false;
        }

        return true;
    }

    /**
     * Artículos de la base de conocimiento que no están en el índice.
     *
     * @param callable $auto_create
     * @return bool  false para parar
     */
    private function indexFaqs($auto_create) {
        require_once INCLUDE_DIR . 'class.faq.php';

        $sql = "SELECT A1.`faq_id` FROM `".FAQ_TABLE."` A1
            LEFT JOIN `".TABLE_PREFIX."_search` A2 ON (A1.`faq_id` = A2.`object_id` AND A2.`object_type`='K')
            WHERE A2.`object_id` IS NULL";
        if (!($res = db_query_unbuffered($sql, $auto_create)))
            return false;

        while ($row = db_fetch_row($res)) {
            if (!($faq = FAQ::lookup($row[0])))
               continue;
            $record = SearchRecord::faq($faq->getId(), $faq->getQuestion(),
                $faq->getKeywords(), $faq->getSearchableAnswer(),
                array('Format', 'searchable'));
            if (!$this->__index($record))
                return false;
        }

        return true;
    }

    function __index($record, $force_flush=false) {
        static $queue = array();

        if ($record)
            $queue[] = $record;
        elseif (!$queue)
            return;

        if (!$force_flush && count($queue) < $this::$BATCH_SIZE)
            return true;

        foreach ($queue as &$r)
            $r = sprintf('(%s)', implode(',', db_input($r)));
        unset($r);

        $sql = 'INSERT INTO `'.TABLE_PREFIX.'_search` (`object_type`, `object_id`, `title`, `content`)
            VALUES '.implode(',', $queue);
        // sql-audit:legacy INSERT por lotes en el índice FULLTEXT
        if (!db_query($sql, false) || count($queue) != db_affected_rows())
            throw new Exception('Unable to index content');

        $this->_reindexed += count($queue);
        $queue = array();

        if (!--$this->max_batches)
            return null;

        return true;
    }

    static function __init() {
        self::createSearchTable();
    }

}

Signal::connect('system.install',
        array('MysqlSearchBackend', '__init'));

MysqlSearchBackend::register();
