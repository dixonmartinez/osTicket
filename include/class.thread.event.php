<?php
/*********************************************************************
    class.thread.event.php

    Eventos del hilo: asignación, transferencia, cierre, reapertura,
    colaboradores, edición, avisos y enlaces entre tickets. Cada evento sabe
    cómo describirse en el historial del ticket.

    Extraído de class.thread.php (frente 6 de docs/CODE_QUALITY.md) para que
    el archivo del hilo no mezcle la entrada de correo con el historial de
    cambios. Se incluye desde class.thread.php en el mismo punto en el que
    estaban estas clases, así que el orden de declaración no cambia.

    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/


class ThreadEvent extends VerySimpleModel {
    static $meta = array(
        'table' => THREAD_EVENT_TABLE,
        'pk' => array('id'),
        // `thread-entry.tmpl.php` pide el autor de cada aviso
        // (`getUserName()`, `getAvatar()`, que leen `$event->user` y
        // `$event->agent`). Sin esto, cada aviso con autor costaba una
        // consulta propia: en el perfil real del ticket salía atribuida a
        // `class.thread.event.php:112`, dos veces por carga.
        'select_related' => array('user', 'agent'),
        'joins' => array(
            // Originator of activity
            'agent' => array(
                'constraint' => array(
                    'uid' => 'Staff.staff_id',
                ),
                'null' => true,
            ),
            // Agent assignee
            'staff' => array(
                'constraint' => array(
                    'staff_id' => 'Staff.staff_id',
                ),
                'null' => true,
            ),
            'team' => array(
                'constraint' => array(
                    'team_id' => 'Team.team_id',
                ),
                'null' => true,
            ),
            'thread' => array(
                'constraint' => array('thread_id' => 'Thread.id'),
            ),
            'user' => array(
                'constraint' => array(
                    'uid' => 'User.id',
                ),
                'null' => true,
            ),
            'dept' => array(
                'constraint' => array(
                    'dept_id' => 'Dept.id',
                ),
                'null' => true,
            ),
            'topic' => array(
                'constraint' => array(
                    'topic_id' => 'Topic.topic_id',
                ),
                'null' => true,
            ),
            'event' => array(
                'constraint' => array(
                    'event_id' => 'Event.id',
                ),
                'null' => true,
            ),
        ),
    );

    // Valid events for database storage
    const ASSIGNED  = 'assigned';
    const RELEASED  = 'released';
    const CLOSED    = 'closed';
    const CREATED   = 'created';
    const COLLAB    = 'collab';
    const EDITED    = 'edited';
    const ERROR     = 'error';
    const OVERDUE   = 'overdue';
    const REOPENED  = 'reopened';
    const STATUS    = 'status';
    const TRANSFERRED = 'transferred';
    const REFERRED = 'referred';
    const VIEWED    = 'viewed';
    const MERGED    = 'merged';
    const UNLINKED    = 'unlinked';

    const MODE_STAFF = 1;
    const MODE_CLIENT = 2;

    var $_data;

    function getAvatar($size=null) {
        if ($this->uid && $this->uid_type == 'S')
            return $this->agent ? $this->agent->getAvatar($size) : '';
        if ($this->uid && $this->uid_type == 'U')
            return $this->user ? $this->user->getAvatar($size) : '';
    }

    function getUserName() {
        if ($this->uid && $this->uid_type == 'S')
            return $this->agent ? $this->agent->getName() : $this->username;
        if ($this->uid && $this->uid_type == 'U')
            return $this->user ? $this->user->getName() : $this->username;
        return $this->username;
    }

    function getIcon() {
        $icons = array(
            'assigned'    => 'hand-right',
            'released'    => 'unlock',
            'collab'      => 'group',
            'created'     => 'magic',
            'overdue'     => 'time',
            'transferred' => 'share-alt',
            'referred'    => 'exchange',
            'edited'      => 'pencil',
            'closed'      => 'thumbs-up-alt',
            'reopened'    => 'rotate-right',
            'resent'      => 'reply-all icon-flip-horizontal',
            'merged'      => 'code-fork',
            'linked'      => 'link',
            'unlinked'    => 'unlink',
        );
        return @$icons[$this->state] ?: 'chevron-sign-right';
    }

    function getDescription($mode=self::MODE_STAFF) {
        // Abstract description
        return $this->template(sprintf(
            __('%s by {somebody} {timestamp}'),
            $this->state
        ), $mode);
    }

    function template($description, $mode=self::MODE_STAFF) {
        global $thisstaff, $cfg;

        $self = $this;
        $hideName = $cfg->hideStaffName();
        return preg_replace_callback('/\{(<(?P<type>([^>]+))>)?(?P<key>[^}.]+)(\.(?P<data>[^}]+))?\}/',
            function ($m) use ($self, $thisstaff, $cfg, $hideName, $mode) {
                switch ($m['key']) {
                case 'assignees':
                    $assignees = array();
                    if ($S = $self->staff) {
                        $avatar = '';
                        if ($cfg->isAvatarsEnabled())
                            $avatar = $S->getAvatar();
                        $assignees[] =
                            $avatar.$S->getName();
                    }
                    if ($T = $self->team) {
                        $assignees[] = $T->getLocalName();
                    }
                    return implode('/', $assignees);
                case 'somebody':
                    if ($hideName && $self->agent && $mode == self::MODE_CLIENT)
                        $name = __('Staff');
                    else
                        $name = $self->getUserName();
                    if ($cfg->isAvatarsEnabled()
                            && ($avatar = $self->getAvatar()))
                        $name = $avatar.$name;
                    return $name;
                case 'timestamp':
                    $timeFormat = null;
                    if ($mode != self::MODE_CLIENT && $thisstaff
                            && (!isset($_REQUEST['a']) || $_REQUEST['a']!='print')
                            && !strcasecmp($thisstaff->datetime_format,
                                'relative')) {
                        $timeFormat = function ($timestamp) {
                            return Format::relativeTime(Misc::db2gmtime($timestamp));
                        };
                    }

                    return sprintf('<time %s datetime="%s"
                            data-toggle="tooltip" title="%s">%s</time>',
                        $timeFormat ? 'class="relative"' : '',
                        date(DateTime::W3C, Misc::db2gmtime($self->timestamp)),
                        Format::daydatetime($self->timestamp),
                        $timeFormat ? $timeFormat($self->timestamp) :
                        Format::datetime($self->timestamp)
                    );
                case 'agent':
                    $name = $self->agent->getName();
                    if ($cfg->isAvatarsEnabled()
                            && ($avatar = $self->getAvatar()))
                        $name = $avatar.$name;
                    return $name;
                case 'dept':
                    if ($dept = $self->getDept())
                        return $dept->getLocalName();
                    return __('None');
                case 'data':
                    $val = $self->getData($m['data']);
                    if (is_array($val))
                        list($val, $fallback) = $val;
                    if ($m['type'] && class_exists($m['type']))
                        $val = $m['type']::lookup($val);
                    if (!$val && $fallback)
                        $val = $fallback;
                    return Format::htmlchars((string) $val);
                }
                return $m[0];
            },
            $description
        );
    }

    function getDept() {
        return $this->dept;
    }

    function getData($key=false) {
        if (!isset($this->_data))
            $this->_data = JsonDataParser::decode($this->data);
        return ($key) ? @$this->_data[$key] : $this->_data;
    }

    function render($mode) {
        $inc = ($mode == self::MODE_STAFF) ? STAFFINC_DIR : CLIENTINC_DIR;
        $event = $this->getTypedEvent();
        include $inc . 'templates/thread-event.tmpl.php';
    }

    static function create($ht=false, $user=false) {
        $inst = new static($ht);
        $inst->timestamp = SqlFunction::NOW();

        global $thisstaff, $thisclient;
        $user = (is_object($user) ? $user : $thisstaff) ?: $thisclient;
        if ($user instanceof Staff) {
            $inst->uid_type = 'S';
            $inst->uid = $user->getId();
        }
        elseif ($user instanceof User) {
            $inst->uid_type = 'U';
            $inst->uid = $user->getId();
        }

        return $inst;
    }

    static function forTicket($ticket, $state, $user=false) {
      global $thisstaff;

      if($thisstaff && !$ticket->getStaffId())
        $staff = $thisstaff->getId();
      else
        $staff = $ticket->getStaffId();

        $inst = self::create(array(
            'thread_type' => ObjectModel::OBJECT_TYPE_TICKET,
            'staff_id' => $staff ?: 0,
            'team_id' => $ticket->getTeamId() ?: 0,
            'dept_id' => $ticket->getDeptId(),
            'topic_id' => $ticket->getTopicId(),
        ), $user);
        return $inst;
    }

    static function forTask($task, $state, $user=false) {
        $inst = self::create(array(
            'thread_type' => ObjectModel::OBJECT_TYPE_TASK,
            'staff_id' => $task->getStaffId() ?: 0,
            'team_id' => $task->getTeamId() ?: 0,
            'dept_id' => $task->getDeptId(),
        ), $user);
        return $inst;
    }

    function getTypedEvent() {
        static $subclasses;

        if (!isset($subclasses)) {
            $parent = get_class($this);
            $subclasses = array();
            foreach (get_declared_classes() as $class) {
                if (is_subclass_of($class, $parent))
                    $subclasses[$class::$state] = $class;
            }
        }
        $this->state = Event::getNameById($this->event_id);
        if (!($class = $subclasses[$this->state]))
            return $this;
        return new $class($this->ht);
    }
}

class Event extends VerySimpleModel {
    static $meta = array(
        'table' => EVENT_TABLE,
        'pk' => array('id'),
    );

    function getInfo() {
        return $this->ht;
    }

    function getId() {
        return $this->id;
    }

    function getName() {
        return $this->name;
    }

    function getDescription() {
        return $this->description;
    }

    static function getNameById($id) {
        return array_search($id, self::getIds());
    }

    static function getIdByName($name) {
         $ids =  self::getIds();
         return $ids[$name] ?: 0;
    }

    static function getIds() {
        static $ids;

        if (!isset($ids)) {
            $ids = array();
            $events = self::objects()->values_flat('id', 'name');
            foreach ($events as $row) {
                list($id, $name) = $row;
                $ids[$name] = $id;
            }
        }

        return $ids;
    }

    static function getStates($dropdown=false) {
        $names = array();
        if ($dropdown)
            $names = array('All');

        $events = self::objects()->values_flat('name');
        foreach ($events as $val)
            $names[] = ucfirst($val[0]);

        return $names;
    }

    static function create($vars=false, &$errors=array()) {
        $event = new static($vars);
        return $event;
    }

    static function __create($vars, &$errors=array()) {
        $event = self::create($vars);
        $event->save();
        return $event;
    }

    function save($refetch=false) {
        return parent::save($refetch);
    }
}

class ThreadEvents extends InstrumentedList {
    function annul($event) {
        $event_id = Event::getIdByName($event);
        $this->queryset
            ->filter(array('event_id' => $event_id))
            ->update(array('annulled' => 1));
    }

    /**
     * Add an event to the thread activity log.
     *
     * Parameters:
     * $object - Object to log activity for
     * $state - State name of the activity (one of 'created', 'edited',
     *      'deleted', 'closed', 'reopened', 'error', 'collab', 'resent',
     *      'assigned', 'released', 'transferred')
     * $data - (array?) Details about the state change
     * $user - (string|User|Staff) user triggering the state change
     * $annul - (state) a corresponding state change that is annulled by
     *      this event
     */
    function log($object, $state, $data=null, $user=null, $annul=null) {
        global $thisstaff, $thisclient;

        if ($object && ($object instanceof Ticket))
            // TODO: Use $object->createEvent() (nolint)
            $event = ThreadEvent::forTicket($object, $state, $user);
        elseif ($object && ($object instanceof Task))
            $event = ThreadEvent::forTask($object, $state, $user);

        if (is_null($event))
            return;

        # Annul previous entries if requested (for instance, reopening a
        # ticket will annul an 'closed' entry). This will be useful to
        # easily prevent repeated statistics.
        if ($annul) {
            $this->annul($annul);
        }

        $username = $user;
        $user = (is_object($user) ? $user : $thisclient) ?: $thisstaff;
        if (!is_string($username)) {
            if ($user instanceof Staff) {
                $username = $user->getUserName();
            }
            // XXX: Use $user here
            elseif ($thisclient) {
                if ($thisclient->hasAccount())
                    $username = $thisclient->getFullName();
                if (!$username)
                    $username = $thisclient->getEmail();
            }
            else {
                # XXX: Security Violation ?
                $username = 'SYSTEM';
            }
        }
        $event->username = $username;
        $event->event_id = Event::getIdByName($state);

        if ($data) {
            if (is_array($data))
                $data = JsonDataEncoder::encode($data);
            if (!is_string($data))
                throw new InvalidArgumentException('Data must be string or array');
            $event->data = $data;
        }

        $this->add($event);

        // Save event immediately
        return $event->save();
    }
}

class AssignmentEvent extends ThreadEvent {
    static $icon = 'hand-right';
    static $state = 'assigned';

    function getDescription($mode=self::MODE_STAFF) {
        $data = $this->getData();
        switch (true) {
        case !is_array($data):
        default:
            $desc = __('Assignee changed by <b>{somebody}</b> to <strong>{assignees}</strong> {timestamp}');
            break;
        case isset($data['staff']):
            $desc = __('<b>{somebody}</b> assigned this to <strong>{<Staff>data.staff}</strong> {timestamp}');
            break;
        case isset($data['team']):
            $desc = __('<b>{somebody}</b> assigned this to <strong>{<Team>data.team}</strong> {timestamp}');
            break;
        case isset($data['claim']):
            $desc = __('<b>{somebody}</b> claimed this {timestamp}');
            break;
        }
        return $this->template($desc, $mode);
    }
}

class ReleaseEvent extends ThreadEvent {
    static $icon = 'unlock';
    static $state = 'released';

    function getDescription($mode=self::MODE_STAFF) {
        $data = $this->getData();
        switch (true) {
        case isset($data['staff'], $data['team']):
            $desc = __('Ticket released from <strong>{<Team>data.team}</strong> and <strong>{<Staff>data.staff}</strong> by <b>{somebody}</b> {timestamp}');
            break;
        case isset($data['staff']):
            $desc = __('Ticket released from <strong>{<Staff>data.staff}</strong> by <b>{somebody}</b> {timestamp}');
            break;
        case isset($data['team']):
            $desc = __('Ticket released from <strong>{<Team>data.team}</strong> by <b>{somebody}</b> {timestamp}');
            break;
        default:
            $desc = __('<b>{somebody}</b> released ticket assignment {timestamp}');
            break;
        }
        return $this->template($desc, $mode);
    }
}

class ReferralEvent extends ThreadEvent {
    static $icon = 'exchange';
    static $state = 'referred';

    function getDescription($mode=self::MODE_STAFF) {
        $data = $this->getData();
        switch (true) {
        case isset($data['staff']):
            $desc = __('<b>{somebody}</b> referred this to <strong>{<Staff>data.staff}</strong> {timestamp}');
            break;
        case isset($data['team']):
            $desc = __('<b>{somebody}</b> referred this to <strong>{<Team>data.team}</strong> {timestamp}');
            break;
        case isset($data['dept']):
            $desc = __('<b>{somebody}</b> referred this to <strong>{<Dept>data.dept}</strong> {timestamp}');
            break;
        }
        return $this->template($desc, $mode);
    }
}

class CloseEvent extends ThreadEvent {
    static $icon = 'thumbs-up-alt';
    static $state = 'closed';

    function getDescription($mode=self::MODE_STAFF) {
        if ($this->getData('status'))
            return $this->template(__('Closed by <b>{somebody}</b> with status of {<TicketStatus>data.status} {timestamp}'), $mode);
        else
            return $this->template(__('Closed by <b>{somebody}</b> {timestamp}'), $mode);
    }
}

class CollaboratorEvent extends ThreadEvent {
    static $icon = 'group';
    static $state = 'collab';

    function getDescription($mode=self::MODE_STAFF) {
        $data = $this->getData();
        switch (true) {
        case isset($data['org']):
            $desc = __('Collaborators for {<Organization>data.org} organization added');
            break;
        case isset($data['del']):
            $base = __('<b>{somebody}</b> removed <strong>%s</strong> from the collaborators {timestamp}');
            $collabs = array();
            $users = User::objects()->filter(array('id__in' => array_keys($data['del'])));
            foreach ($data['del'] as $id=>$c) {
                $U = false;
                foreach ($users as $user) {
                    if ($user->id == $id) {
                        $U = $user;
                        break;
                    }
                }
                $collabs[] = Format::htmlchars($U ? $U->getName() : (@$c['name'] ?: $c));
            }
            $desc = sprintf($base, implode(', ', $collabs));
            break;
        case isset($data['add']):
            $base = __('<b>{somebody}</b> added <strong>%s</strong> as collaborators {timestamp}');
            $collabs = array();
            if ($data['add']) {
                $users = User::objects()->filter(array('id__in' => array_keys($data['add'])));
                foreach ($data['add'] as $id=>$c) {
                    $U = false;
                    foreach ($users as $user) {
                        if ($user->id == $id) {
                            $U = $user;
                            break;
                        }
                    }
                    $c = sprintf("%s %s",
                        Format::htmlchars($U ? $U->getName() : (@$c['name'] ?: $c)),
                        $c['src'] ? sprintf(__('via %s'
                            /* e.g. "Added collab "Me <me@company.me>" via Email (to)" */
                            ), $c['src']) : ''
                    );
                    $collabs[] = $c;
                }
            }
            $desc = $collabs
                ? sprintf($base, implode(', ', $collabs))
                : 'somebody';
            break;
        }
        return $this->template($desc, $mode);
    }
}

class CreationEvent extends ThreadEvent {
    static $icon = 'magic';
    static $state = 'created';

    function getDescription($mode=self::MODE_STAFF) {
        return $this->template(__('Created by <b>{somebody}</b> {timestamp}'), $mode);
    }
}

class EditEvent extends ThreadEvent {
    static $icon = 'pencil';
    static $state = 'edited';

    function getDescription($mode=self::MODE_STAFF) {
        $data = $this->getData();
        switch (true) {
        case isset($data['filter']):
            $desc = sprintf(__('%s set %s %s {timestamp}'),
                    '<b>' . $data['filter'] . '</b> Filter ',
                    __($data['type']),
                    $data['value'] ? 'to <strong>' . $data['value'] . '</strong>' :  '');
            break;
        case isset($data['owner']):
            $desc = __('<b>{somebody}</b> changed ownership to {<User>data.owner} {timestamp}');
            break;
        case isset($data['status']):
            $desc = __('<b>{somebody}</b> changed the status to <strong>{<TicketStatus>data.status}</strong> {timestamp}');
            break;
        case isset($data['fields']):
            $fields = $changes = array();
            foreach (DynamicFormField::objects()->filter(array(
                'id__in' => array_keys($data['fields'])
            )) as $F) {
                $fields[$F->id] = $F;
            }
            foreach ($data['fields'] as $id=>$f) {
                if (!($field = $fields[$id]))
                   continue;
                if ($mode == self::MODE_CLIENT &&  !$field->isVisibleToUsers())
                    continue;
                list($old, $new) = $f;
                $impl = $field->getImpl($field);
                $before = $impl->to_php($old);
                $after = $impl->to_php($new);
                $changes[] = sprintf('<strong>%s</strong> %s',
                    $field->getLocal('label'), $impl->whatChanged($before, $after));
            }
            // Fallthrough to other editable fields
        case isset($data['topic_id']):
        case isset($data['sla_id']):
        case isset($data['source']):
        case isset($data['user_id']):
        case isset($data['duedate']):
            $base = __('Updated by <b>{somebody}</b> {timestamp} — %s');
            foreach (array(
                'topic_id' => array(__('Help Topic'), array('Topic', 'getTopicName')),
                'sla_id' => array(__('SLA'), array('SLA', 'getSLAName')),
                'duedate' => array(__('Due Date'), array('Format', 'date')),
                'user_id' => array(__('Ticket Owner'), array('User', 'getNameById')),
                'source' => array(__('Source'), null)
            ) as $f => $info) {
                if (isset($data[$f])) {
                    list($name, $desc) = $info;
                    list($old, $new) = $data[$f];
                    if ($desc && is_callable($desc)) {
                        $new = call_user_func($desc, $new);
                        if ($old)
                            $old = call_user_func($desc, $old);
                    }
                    if ($old and $new) {
                        $changes[] = sprintf(
                            __('<strong>%1$s</strong> changed from <strong>%2$s</strong> to <strong>%3$s</strong>'),
                            Format::htmlchars($name), Format::htmlchars($old), Format::htmlchars($new)
                        );
                    }
                    elseif ($new) {
                        $changes[] = sprintf(
                            __('<strong>%1$s</strong> set to <strong>%2$s</strong>'),
                            Format::htmlchars($name), Format::htmlchars($new)
                        );
                    }
                    else {
                        $changes[] = sprintf(
                            __('unset <strong>%1$s</strong>'),
                            Format::htmlchars($name)
                        );
                    }
                }
            }
            $desc = $changes
                ? sprintf($base, implode(', ', $changes)) : '';
            break;
        }

        return $this->template($desc, $mode);
    }
}

class OverdueEvent extends ThreadEvent {
    static $icon = 'time';
    static $state = 'overdue';

    function getDescription($mode=self::MODE_STAFF) {
        return $this->template(__('Flagged as overdue by the system {timestamp}'), $mode);
    }
}

class ReopenEvent extends ThreadEvent {
    static $icon = 'rotate-right';
    static $state = 'reopened';

    function getDescription($mode=self::MODE_STAFF) {
        return $this->template(__('Reopened by <b>{somebody}</b> {timestamp}'), $mode);
    }
}

class ResendEvent extends ThreadEvent {
    static $icon = 'reply-all icon-flip-horizontal';
    static $state = 'resent';

    function getDescription($mode=self::MODE_STAFF) {
        return $this->template(__('<b>{somebody}</b> resent <strong><a href="#thread-entry-{data.entry}">a previous response</a></strong> {timestamp}'), $mode);
    }
}

class TransferEvent extends ThreadEvent {
    static $icon = 'share-alt';
    static $state = 'transferred';

    function getDescription($mode=self::MODE_STAFF) {
        return $this->template(__('<b>{somebody}</b> transferred this to <strong>{dept}</strong> {timestamp}'), $mode);
    }
}

class ViewEvent extends ThreadEvent {
    static $state = 'viewed';
}

class MergedEvent extends ThreadEvent {
    static $icon = 'code-fork';
    static $state = 'merged';

    function getDescription($mode=self::MODE_STAFF) {
        return sprintf($this->template(__('<b>{somebody}</b> merged this ticket with %s{data.id}%s<b>{data.ticket}</b>%s {timestamp}'), $mode),
                '<a href="tickets.php?id=', '">', '</a>');
    }
}

class LinkedEvent extends ThreadEvent {
    static $icon = 'link';
    static $state = 'linked';

    function getDescription($mode=self::MODE_STAFF) {
        return sprintf($this->template(__('<b>{somebody}</b> linked this ticket with %s{data.id}%s<b>{data.ticket}</b>%s {timestamp}'), $mode),
                '<a href="tickets.php?id=', '">', '</a>');
    }
}

class UnlinkEvent extends ThreadEvent {
    static $icon = 'unlink';
    static $state = 'unlinked';

    function getDescription($mode=self::MODE_STAFF) {
        return sprintf($this->template(__('<b>{somebody}</b> unlinked this ticket from %s{data.id}%s<b>{data.ticket}</b>%s {timestamp}'), $mode),
                '<a href="tickets.php?id=', '">', '</a>');
    }
}
