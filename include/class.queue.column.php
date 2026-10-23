<?php
/*********************************************************************
    class.queue.column.php

    Columnas de una cola personalizada: decoraciones (iconos y contadores),
    fuentes de datos, condiciones y el contenedor perezoso de valores.

    Extraído de class.queue.php (frente 2 de docs/CODE_QUALITY.md). Este
    archivo se incluye desde class.queue.php, así que la API no cambia:
    las clases siguen siendo globales y con el mismo nombre.

    Copyright (c)  2006-2015 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
/* QueueColumnAnnotation y sus hijas usan ChoiceField, y DataSourceField y
   QueueColumnConditionProperty heredan de él. Antes esta dependencia era
   implícita (funcionaba si algún otro archivo había cargado los formularios
   primero); aquí queda declarada. */
include_once(INCLUDE_DIR . 'class.forms.php');

abstract class QueueColumnAnnotation {
    static $icon = false;
    static $desc = '';

    var $config;

    function __construct($config) {
        $this->config = $config;
    }

    static function fromJson($config) {
        $class = $config['c'];
        if (class_exists($class))
            return new $class($config);
    }

    static function getDescription() {
        return __(static::$desc);
    }
    static function getIcon() {
        return static::$icon;
    }
    static function getPositions() {
        return array(
            "<" => __('Start'),
            "b" => __('Before'),
            "a" => __('After'),
            ">" => __('End'),
        );
    }

    function decorate($text, $dec) {
        static $positions = array(
            '<' => '<span class="pull-left">%2$s</span>%1$s',
            '>' => '<span class="pull-right">%2$s</span>%1$s',
            'a' => '%1$s%2$s',
            'b' => '%2$s%1$s',
        );

        $pos = $this->getPosition();
        if (!isset($positions[$pos]))
            return $text;

        return sprintf($positions[$pos], $text, $dec);
    }

    // Render the annotation with the database record $row. $text is the
    // text of the cell before annotations were applied.
    function render($row, $cell) {
        if ($decoration = $this->getDecoration($row, $cell))
            return $this->decorate($cell, $decoration);

        return $cell;
    }

    // Add the annotation to a QuerySet
    abstract static function annotate($query, $name);

    // Fetch some HTML to render the decoration on the page. This function
    // can return boolean FALSE to indicate no decoration should be applied
    abstract function getDecoration($row, $text);

    function getPosition() {
        return strtolower($this->config['p']) ?: 'a';
    }

    function getClassName() {
        return @$this->config['c'] ?: get_class($this);
    }

    static function getAnnotations($root) {
        // Ticket annotations
        static $annotations;
        if (!isset($annotations[$root])) {
            foreach (get_declared_classes() as $class)
                if (is_subclass_of($class, get_called_class()))
                    $annotations[$root][] = $class;
        }
        return $annotations[$root];
    }

    /**
     * Estimate the width of the rendered annotation in pixels
     */
    function getWidth($row) {
        return $this->isVisible($row) ? 25 : 0;
    }

    function isVisible($row) {
        return true;
    }

    static function addToQuery($query, $name=false) {
        $name = $name ?: static::$qname;
        $annotation = new Static(array());
        return $annotation->annotate($query, $name);
    }

    static function from_query($row, $name=false) {
        $name = $name ?: static::$qname;
        return $row[$name];
    }
}

class TicketThreadCount
extends QueueColumnAnnotation {
    static $icon = 'comments-alt';
    static $qname = '_thread_count';
    static $desc = /* @trans */ 'Thread Count';

    static function annotate($query, $name=false) {
        $name = $name ?: static::$qname;
        return $query->annotate(array(
            $name => TicketThread::objects()
            ->filter(array('ticket__ticket_id' => new SqlField('ticket_id', 1)))
            ->exclude(array('entries__flags__hasbit' => ThreadEntry::FLAG_HIDDEN))
            ->aggregate(array('count' => SqlAggregate::COUNT('entries__id')))
        ));
    }

    function getDecoration($row, $text) {
        $threadcount = $row[static::$qname];
        if ($threadcount > 1) {
            return sprintf(
                '<small class="faded-more"><i class="icon-comments-alt"></i> %s</small>',
                $threadcount
            );
        }
    }

    function isVisible($row) {
        return $row[static::$qname] > 1;
    }
}

class TicketReopenCount
extends QueueColumnAnnotation {
    static $icon = 'folder-open-alt';
    static $qname = '_reopen_count';
    static $desc = /* @trans */ 'Reopen Count';

    static function annotate($query, $name=false) {
        $name = $name ?: static::$qname;
        return $query->annotate(array(
            $name => TicketThread::objects()
            ->filter(array('ticket__ticket_id' => new SqlField('ticket_id', 1)))
            ->filter(array('events__annulled' => 0, 'events__event_id' => Event::getIdByName('reopened')))
            ->aggregate(array('count' => SqlAggregate::COUNT('events__id')))
        ));
    }

    function getDecoration($row, $text) {
        $reopencount = $row[static::$qname];
        if ($reopencount) {
            return sprintf(
                '&nbsp;<small class="faded-more"><i class="icon-%s"></i> %s</small>',
                static::$icon,
                $reopencount > 1 ? $reopencount : ''
            );
        }
    }

    function isVisible($row) {
        return $row[static::$qname];
    }
}

class ThreadAttachmentCount
extends QueueColumnAnnotation {
    static $icon = 'paperclip';
    static $qname = '_att_count';
    static $desc = /* @trans */ 'Attachment Count';

    static function annotate($query, $name=false) {
        // TODO: Convert to Thread attachments
        $name = $name ?: static::$qname;
        return $query->annotate(array(
            $name => TicketThread::objects()
            ->filter(array('ticket__ticket_id' => new SqlField('ticket_id', 1)))
            ->filter(array('entries__attachments__inline' => 0))
            ->aggregate(array('count' => SqlAggregate::COUNT('entries__attachments__id')))
        ));
    }

    function getDecoration($row, $text) {
        $count = $row[static::$qname];
        if ($count) {
            return sprintf(
                '<i class="small icon-paperclip icon-flip-horizontal" data-toggle="tooltip" title="%s"></i>',
                $count);
        }
    }

    function isVisible($row) {
        return $row[static::$qname] > 0;
    }
}

class TicketTasksCount
extends QueueColumnAnnotation {
    static $icon = 'list-ol';
    static $qname = '_task_count';
    static $desc = /* @trans */ 'Tasks Count';

    static function annotate($query, $name=false) {
        $name = $name ?: static::$qname;
        return $query->annotate(array(
            $name => Task::objects()
            ->filter(array('ticket__ticket_id' => new SqlField('ticket_id', 1)))
            ->aggregate(array('count' => SqlAggregate::COUNT('id')))
        ));
    }

    function getDecoration($row, $text) {
        $count = $row[static::$qname];
        if ($count) {
            return sprintf(
                '<small class="faded-more"><i class="icon-%s"></i> %s</small>',
                static::$icon, $count);
        }
    }

    function isVisible($row) {
        return $row[static::$qname];
    }
}

class ThreadCollaboratorCount
extends QueueColumnAnnotation {
    static $icon = 'group';
    static $qname = '_collabs';
    static $desc = /* @trans */ 'Collaborator Count';

    static function annotate($query, $name=false) {
        $name = $name ?: static::$qname;
        return $query->annotate(array(
            $name => TicketThread::objects()
            ->filter(array('ticket__ticket_id' => new SqlField('ticket_id', 1)))
            ->aggregate(array('count' => SqlAggregate::COUNT('collaborators__id')))
        ));
    }

    function getDecoration($row, $text) {
        $count = $row[static::$qname];
        if ($count) {
            return sprintf(
                '<span class="pull-right faded-more" data-toggle="tooltip" title="%d"><i class="icon-group"></i></span>',
                $count);
        }
    }

    function isVisible($row) {
        return $row[static::$qname] > 0;
    }
}

class OverdueFlagDecoration
extends QueueColumnAnnotation {
    static $icon = 'exclamation';
    static $desc = /* @trans */ 'Overdue Icon';

    static function annotate($query, $name=false) {
        return $query->values('isoverdue');
    }

    function getDecoration($row, $text) {
        if ($row['isoverdue'])
            return '<span class="Icon overdueTicket"></span>';
    }

    function isVisible($row) {
        return $row['isoverdue'];
    }
}

class MergedFlagDecoration
extends QueueColumnAnnotation {
    static $icon = 'code-fork';
    static $desc = /* @trans */ 'Merged Icon';

    static function annotate($query, $name=false) {
        return $query->values('ticket_pid', 'flags');
    }

    function getDecoration($row, $text) {
        $flags = $row['flags'];
        $combine = ($flags & Ticket::FLAG_COMBINE_THREADS) != 0;
        $separate = ($flags & Ticket::FLAG_SEPARATE_THREADS) != 0;
        $linked = ($flags & Ticket::FLAG_LINKED) != 0;

        if ($combine || $separate) {
            return sprintf('<a data-placement="bottom" data-toggle="tooltip" title="%s" <i class="icon-code-fork"></i></a>',
                           $combine ? __('Combine') : __('Separate'));
        } elseif ($linked)
            return '<i class="icon-link"></i>';
    }

    function isVisible($row) {
        return $row['ticket_pid'];
    }
}

class LinkedFlagDecoration
extends QueueColumnAnnotation {
    static $icon = 'link';
    static $desc = /* @trans */ 'Linked Icon';

    static function annotate($query, $name=false) {
        return $query->values('ticket_pid', 'flags');
    }

    function getDecoration($row, $text) {
        $flags = $row['flags'];
        $linked = ($flags & Ticket::FLAG_LINKED) != 0;
        if ($linked)
            return '<i class="icon-link"></i>';
    }

    function isVisible($row) {
        return $row['ticket_pid'];
    }
}

class TicketSourceDecoration
extends QueueColumnAnnotation {
    static $icon = 'phone';
    static $desc = /* @trans */ 'Ticket Source';

    static function annotate($query, $name=false) {
        return $query->values('source');
    }

    function getDecoration($row, $text) {
        return sprintf('<span class="Icon %sTicket"></span>',
            strtolower($row['source']));
    }
}

class LockDecoration
extends QueueColumnAnnotation {
    static $icon = "lock";
    static $desc = /* @trans */ 'Locked';

    static function annotate($query, $name=false) {
        global $thisstaff;

        return $query
            ->annotate(array(
                '_locked' => new SqlExpr(new Q(array(
                    'lock__expire__gt' => SqlFunction::NOW(),
                    Q::not(array('lock__staff_id' => $thisstaff->getId())),
                )))
            ));
    }

    function getDecoration($row, $text) {
        if ($row['_locked'])
            return sprintf('<span class="Icon lockedTicket"></span>');
    }

    function isVisible($row) {
        return $row['_locked'];
    }
}

class AssigneeAvatarDecoration
extends QueueColumnAnnotation {
    static $icon = "user";
    static $desc = /* @trans */ 'Assignee Avatar';

    static function annotate($query, $name=false) {
        return $query->values('staff_id', 'team_id');
    }

    function getDecoration($row, $text) {
        if ($row['staff_id'] && ($staff = Staff::lookup($row['staff_id'])))
            return sprintf('<span class="avatar">%s</span>',
                $staff->getAvatar(16));
        elseif ($row['team_id'] && ($team = Team::lookup($row['team_id']))) {
            $avatars = [];
            foreach ($team->getMembers() as $T)
                $avatars[] = $T->getAvatar(16);
            return sprintf('<span class="avatar group %s">%s</span>',
                count($avatars), implode('', $avatars));
        }
    }

    function isVisible($row) {
        return $row['staff_id'] + $row['team_id'] > 0;
    }

    function getWidth($row) {
        if (!$this->isVisible($row))
            return 0;

        // If assigned to a team with no members, return 0 width
        $width = 10;
        if ($row['team_id'] && ($team = Team::lookup($row['team_id'])))
            $width += (count($team->getMembers()) - 1) * 10;

        return $width ? $width + 10 : $width;
    }
}

class UserAvatarDecoration
extends QueueColumnAnnotation {
    static $icon = "user";
    static $desc = /* @trans */ 'User Avatar';

    static function annotate($query, $name=false) {
        return $query->values('user_id');
    }

    function getDecoration($row, $text) {
        if ($row['user_id'] && ($user = User::lookup($row['user_id'])))
            return sprintf('<span class="avatar">%s</span>',
                $user->getAvatar(16));
    }

    function isVisible($row) {
        return $row['user_id'] > 0;
    }
}

class DataSourceField
extends ChoiceField {
    function getChoices($verbose=false, $options=array()) {
        $config = $this->getConfiguration();
        $root = $config['root'];
        $fields = array();
        foreach (CustomQueue::getSearchableFields($root) as $path=>$f) {
            list($label,) = $f;
            $fields[$path] = $label;
        }
        return $fields;
    }
}

class QueueColumnCondition {
    var $config;
    var $queue;
    var $properties = array();

    static $uid = 1;

    function __construct($config, $queue=null) {
        $this->config = $config;
        $this->queue = $queue;
        if (is_array($config['prop']))
            $this->properties = $config['prop'];
    }

    function getProperties() {
        return $this->properties;
    }

    // Add the annotation to a QuerySet
    function annotate($query) {
        if (!($Q = $this->getSearchQ($query)))
            return $query;

        // Add an annotation to the query
        return $query->annotate(array(
            $this->getAnnotationName() => new SqlExpr(array($Q))
        ));
    }

    function getField($name=null) {
        // FIXME
        #$root = $this->getColumn()->getRoot();
        $root = 'Ticket';
        $searchable = CustomQueue::getSearchableFields($root);

        if (!isset($name))
            list($name) = $this->config['crit'];

        // Lookup the field to search this condition
        if (isset($searchable[$name])) {
            return $searchable[$name];
        }
    }

    function getFieldName() {
        list($name) = $this->config['crit'];
        return $name;
    }

    function getCriteria() {
        return $this->config['crit'];
    }

    function getSearchQ($query) {
        list($name, $method, $value) = $this->config['crit'];

        // XXX: Move getOrmPath to be more of a utility
        // Ensure the special join is created to support custom data joins
        $name = @CustomQueue::getOrmPath($name, $query);

        $name2 = null;
        if (preg_match('/__answers!\d+__/', $name)) {
            // Ensure that only one record is returned from the join through
            // the entry and answers joins
            $name2 = $this->getAnnotationName().'2';
            $query->annotate(array($name2 => SqlAggregate::MAX($name)));
        }

        // Fetch a criteria Q for the query
        if (list(,$field) = $this->getField($name))
            return $field->getSearchQ($method, $value, $name2 ?: $name);
    }

    /**
     * Take the criteria from the SavedSearch fields setup and isolate the
     * field name being search, the method used for searhing, and the method-
     * specific data entered in the UI.
     */
    static function isolateCriteria($criteria, $base='Ticket') {
        $searchable = CustomQueue::getSearchableFields($base);
        foreach ($criteria as $k=>$v) {
            if (substr($k, -7) === '+method') {
                list($name,) = explode('+', $k, 2);
                if (!isset($searchable[$name]))
                    continue;

                // Lookup the field to search this condition
                list($label, $field) = $searchable[$name];

                // Get the search method and value
                $method = $v;
                // Not all search methods require a value
                $value = $criteria["{$name}+{$method}"];

                return array($name, $method, $value);
            }
        }
    }

    function render($row, $text, &$styles=array()) {
        if ($V = $row[$this->getAnnotationName()]) {
            foreach ($this->getProperties() as $css=>$value) {
                $field = QueueColumnConditionProperty::getField($css);
                $field->value = $value;
                $V = $field->getClean();
                if (is_array($V))
                    $V = current($V);
                $styles[$css] = $V;
            }
        }
        return $text;
    }

    function getAnnotationName() {
        // This should be predictable based on the criteria so that the
        // query can deduplicate the same annotations used in different
        // conditions
        if (!isset($this->annotation_name)) {
            $this->annotation_name = $this->getShortHash();
        }
        return $this->annotation_name;
    }

    function __toString() {
        list($name, $method, $value) = $this->config['crit'];
        if (is_array($value))
            $value = implode('+', $value);

        return "{$name} {$method} {$value}";
    }

    function getHash($binary=false) {
        return sha1($this->__toString(), $binary);
    }

    function getShortHash() {
        return substr(base64_encode($this->getHash(true)), 0, 7);
    }

    static function getUid() {
        return static::$uid++;
    }

    static function fromJson($config, $queue=null) {
        if (is_string($config))
            $config = JsonDataParser::decode($config);
        if (!is_array($config))
            throw new BadMethodCallException('$config must be string or array');

        return new static($config, $queue);
    }
}

class QueueColumnConditionProperty
extends ChoiceField {
    static $properties = array(
        'background-color' => 'ColorChoiceField',
        'color' => 'ColorChoiceField',
        'font-family' => array(
            'monospace', 'serif', 'sans-serif', 'cursive', 'fantasy',
        ),
        'font-size' => array(
            'small', 'medium', 'large', 'smaller', 'larger',
        ),
        'font-style' => array(
            'normal', 'italic', 'oblique',
        ),
        'font-weight' => array(
            'lighter', 'normal', 'bold', 'bolder',
        ),
        'text-decoration' => array(
            'none', 'underline',
        ),
        'text-transform' => array(
            'uppercase', 'lowercase', 'captalize',
        ),
    );

    function __construct($property) {
        $this->property = $property;
    }

    static function getProperties() {
        return array_keys(static::$properties);
    }

    static function getField($prop) {
        $choices = static::$properties[$prop];
        if (!isset($choices))
            return null;
        if (is_array($choices))
            return new ChoiceField(array(
                'name' => $prop,
                'choices' => array_combine($choices, $choices),
            ));
        elseif (class_exists($choices))
            return new $choices(array('name' => $prop));
    }

    function getChoices($verbose=false, $options=array()) {
        if (isset($this->property))
            return static::$properties[$this->property];

        $keys = array_keys(static::$properties);
        return array_combine($keys, $keys);
    }
}

class LazyDisplayWrapper {
    function __construct($field, $value) {
        $this->field = $field;
        $this->value = $value;
        $this->safe = false;
    }

    /**
     * Allow a filter to change the value of this to a "safe" value which
     * will not be automatically encoded with htmlchars()
     */
    function changeTo($what, $safe=false) {
        $this->field = null;
        $this->value = $what;
        $this->safe = $safe;
    }

    function __toString() {
        return $this->display();
    }

    function display(&$styles=array()) {
        if (isset($this->field))
            return $this->field->display(
                $this->field->to_php($this->value), $styles);
        if ($this->safe)
            return $this->value;
        return Format::htmlchars($this->value);
    }
}
