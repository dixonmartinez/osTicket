<?php
/*********************************************************************
    class.queue.filter.php

    Filtros de columna de una cola personalizada (enlaces, fechas) y los
    formularios que los configuran.

    Extraído de class.queue.php (frente 2 de docs/CODE_QUALITY.md). Este
    archivo se incluye desde class.queue.php, así que la API no cambia.

    Copyright (c)  2006-2015 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
/* Los formularios de configuración heredan de AbstractForm */
include_once(INCLUDE_DIR . 'class.forms.php');

abstract class QueueColumnFilter {
    static $registry;

    static $id = null;
    static $desc = null;

    static function register($filter, $group) {
        if (!isset($filter::$id))
            throw new Exception('QueueColumnFilter must define $id');
        if (isset(static::$registry[$filter::$id]))
            throw new Exception($filter::$id
                . ': QueueColumnFilter already registered under that id');
        if (!is_subclass_of($filter, get_called_class()))
            throw new Exception('Filter must extend QueueColumnFilter');

        static::$registry[$filter::$id] = array($group, $filter);
    }

    static function getFilters() {
        $list = static::$registry;
        $base = array();
        foreach ($list as $id=>$stuff) {
            list($group, $class) = $stuff;
            $base[$group][$id] = __($class::$desc);
        }
        return $base;
    }

    static function getInstance($id) {
        if (isset(static::$registry[$id])) {
            list(, $class) = @static::$registry[$id];
            if ($class && class_exists($class))
                return new $class();
        }
    }

    function mangleQuery($query, $column) { return $query; }

    abstract function filter($value, $row);
}

class TicketLinkFilter
extends QueueColumnFilter {
    static $id = 'link:ticket';
    static $desc = /* @trans */ "Ticket Link";

    function filter($text, $row) {
        if ($link = $this->getLink($row))
            return sprintf('<a style="display:inline" href="%s">%s</a>', $link, $text);
    }

    function mangleQuery($query, $column) {
        static $fields = array(
            'link:ticket'   => 'ticket_id',
            'link:ticketP'  => 'ticket_id',
            'link:user'     => 'user_id',
            'link:org'      => 'user__org_id',
        );

        if (isset($fields[static::$id])) {
            $query = $query->values($fields[static::$id]);
        }
        return $query;
    }

    function getLink($row) {
        return Ticket::getLink($row['ticket_id']);
    }
}

class UserLinkFilter
extends TicketLinkFilter {
    static $id = 'link:user';
    static $desc = /* @trans */ "User Link";

    function getLink($row) {
        return User::getLink($row['user_id']);
    }
}

class OrgLinkFilter
extends TicketLinkFilter {
    static $id = 'link:org';
    static $desc = /* @trans */ "Organization Link";

    function getLink($row) {
        return Organization::getLink($row['user__org_id']);
    }
}
QueueColumnFilter::register('TicketLinkFilter', __('Link'));
QueueColumnFilter::register('UserLinkFilter', __('Link'));
QueueColumnFilter::register('OrgLinkFilter', __('Link'));

class TicketLinkWithPreviewFilter
extends TicketLinkFilter {
    static $id = 'link:ticketP';
    static $desc = /* @trans */ "Ticket Link with Preview";

    function filter($text, $row) {
        $link = $this->getLink($row);
        return sprintf('<a style="display: inline" class="preview" data-preview="#tickets/%d/preview" href="%s">%s</a>',
            $row['ticket_id'], $link, $text);
    }
}
QueueColumnFilter::register('TicketLinkWithPreviewFilter', __('Link'));

class DateTimeFilter
extends QueueColumnFilter {
    static $id = 'date:full';
    static $desc = /* @trans */ "Date and Time";

    function filter($text, $row) {
        return $text ?
            $text->changeTo(Format::datetime($text->value)) : '';
    }
}

class HumanizedDateFilter
extends QueueColumnFilter {
    static $id = 'date:human';
    static $desc = /* @trans */ "Relative Date and Time";

    function filter($text, $row) {
        return sprintf(
            '<time class="relative" datetime="%s" title="%s">%s</time>',
            date(DateTime::W3C, Misc::db2gmtime($text->value) ?: 0),
            Format::daydatetime($text->value),
            Format::relativeTime(Misc::db2gmtime($text->value))
        );
    }
}
QueueColumnFilter::register('DateTimeFilter', __('Date Format'));
QueueColumnFilter::register('HumanizedDateFilter', __('Date Format'));

class QueueColDataConfigForm
extends AbstractForm {
    function buildFields() {
        return array(
            'primary' => new DataSourceField(array(
                'label' => __('Primary Data Source'),
                'required' => true,
                'configuration' => array(
                    'root' => 'Ticket',
                ),
                'layout' => new GridFluidCell(6),
            )),
            'secondary' => new DataSourceField(array(
                'label' => __('Secondary Data Source'),
                'configuration' => array(
                    'root' => 'Ticket',
                ),
                'layout' => new GridFluidCell(6),
            )),
            'name' => new TextboxField(array(
                'label' => __('Name'),
                'required' => true,
                'layout' => new GridFluidCell(4),
            )),
            'filter' => new ChoiceField(array(
                'label' => __('Filter'),
                'required' => false,
                'choices' => QueueColumnFilter::getFilters(),
                'layout' => new GridFluidCell(4),
            )),
            'truncate' => new ChoiceField(array(
                'label' => __('Text Overflow'),
                'choices' => array(
                    'wrap' => __("Wrap Lines"),
                    'ellipsis' => __("Add Ellipsis"),
                    'clip' => __("Clip Text"),
                    'lclip' => __("Clip Beginning Text"),
                ),
                'default' => 'wrap',
                'layout' => new GridFluidCell(4),
            )),
        );
    }
}

class QueueSortDataConfigForm
extends AbstractForm {
    function getInstructions() {
        return __('Add, and remove the fields in this list using the options below. Sorting can be performed on any field, whether displayed in the queue or not.');
    }

    function buildFields() {
        return array(
            'name' => new TextboxField(array(
                'required' => true,
                'layout' => new GridFluidCell(12),
                'translatable' => isset($this->options['id'])
                    ? _H('queuesort.name.'.$this->options['id']) : false,
                'configuration' => array(
                    'placeholder' => __('Sort Criteria Title'),
                ),
            )),
        );
    }
}

class QueueSortAdvancedConfigForm
extends AbstractForm {
    function getInstructions() {
        return __('If unsure, leave these options blank and unset');
    }

    function buildFields() {
        return array(
            'index' => new TextboxField(array(
                'label' => __('Database Index'),
                'hint' => __('Use this index when sorting on this column'),
                'required' => false,
                'layout' => new GridFluidCell(12),
                'configuration' => array(
                    'placeholder' => __('Automatic'),
                ),
            )),
        );
    }
}
