<?php
/*********************************************************************
    class.thread.objects.php

    Piezas del hilo que no son ni el hilo ni la entrada: el aviso cruzado a
    otro objeto, los hilos de objecto, las acciones de entrada y la actividad.

    Parte de include/class.thread.php (frente 6 de docs/CODE_QUALITY.md): el
    archivo tenia 2.351 lineas y once clases. Se movieron las clases enteras,
    sin tocar su codigo; el archivo original incluye este al final, cuando
    `Thread`, `ThreadEntry` y la interfaz `Threadable` ya estan declaradas.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/
class ThreadReferral extends VerySimpleModel {
    static $meta = array(
        'table' => THREAD_REFERRAL_TABLE,
        'pk' => array('id'),
        'joins' => array(
            'thread' => array(
                'constraint' => array('thread_id' => 'Thread.id'),
            ),
            'agent' => array(
                'constraint' => array(
                    'object_type' => "'S'",
                    'object_id' => 'Staff.staff_id',
                ),
            ),
            'team' => array(
                'constraint' => array(
                    'object_type' => "'E'",
                    'object_id' => 'Team.team_id',
                ),
            ),
            'dept' => array(
                'constraint' => array(
                    'object_type' => "'D'",
                    'object_id' => 'Dept.id',
                ),
            ),
          )
        );

    var $icons = array(
            'E' => 'group',
            'D' => 'sitemap',
            'S' => 'user'
            );

    var $_object = null;

    function getId() {
        return $this->id;
    }

    function getName() {
        return (string) $this->getObject();
    }

    function getObject() {

        if (!isset($this->_object)) {
            $this->_object = ObjectModel::lookup(
                    $this->object_id, $this->object_type);
        }

        return $this->_object;
    }

    function getIcon() {
        return $this->icons[$this->object_type];
    }

    function display() {
        return sprintf('<i class="icon-%s"></i> %s',
                $this->getIcon(), $this->getName());
    }

    static function create($vars) {

        $new = new self($vars);
        $new->created = SqlFunction::NOW();
        return $new->save();
    }
}

// Object specific thread utils.
class ObjectThread extends Thread
implements TemplateVariable {
    static $types = array(
        ObjectModel::OBJECT_TYPE_TASK => 'TaskThread',
        ObjectModel::OBJECT_TYPE_TICKET => 'TicketThread',
    );

    var $counts;

    function getCounts() {
        if (!isset($this->counts) && $this->getId()) {
            $this->counts = array();

            $stuff = $this->entries
                ->values_flat('type')
                ->annotate(array(
                    'count' => SqlAggregate::COUNT('id')
                ));

            foreach ($stuff as $row) {
                list($type, $count) = $row;
                $this->counts[$type] = $count;
            }
        }
        return $this->counts;
    }

    function getNumMessages() {
        $this->getCounts();
        return $this->counts[MessageThreadEntry::ENTRY_TYPE];
    }

    function getNumResponses() {
        $this->getCounts();
        return $this->counts[ResponseThreadEntry::ENTRY_TYPE];
    }

    function getNumNotes() {
        $this->getCounts();
        return $this->counts[NoteThreadEntry::ENTRY_TYPE];
    }


    function getLastMessage($criteria=false) {
        $entries = clone $this->getEntries();
        $entries->filter(array(
            'type' => MessageThreadEntry::ENTRY_TYPE
        ));

        if ($criteria)
            $entries->filter($criteria);

        $entries->order_by('-id');

        return $entries->first();
    }

    function getLastEmailMessage($criteria=array()) {

        $criteria += array(
                'source' => 'Email',
                'email_info__headers__isnull' => false);

        return $this->getLastMessage($criteria);
    }

    function getLastEmailMessageByUser($user) {

        $uid = is_numeric($user) ? $user : 0;
        if (!$uid && ($user instanceof EmailContact))
            $uid = $user->getUserId();

        return $uid
                ? $this->getLastEmailMessage(array('user_id' => $uid))
                : null;
    }

    function getEntry($criteria) {
        // XXX: PUNT
        if (is_numeric($criteria))
            return parent::getEntry($criteria);

        $entries = clone $this->getEntries();
        $entries->filter($criteria);
        return $entries->first();
    }

    function getMessages() {
        $entries = clone $this->getEntries();
        return $entries->filter(array(
            'type' => MessageThreadEntry::ENTRY_TYPE
        ));
    }

    function getResponses() {
        $entries = clone $this->getEntries();
        return $entries->filter(array(
            'type' => ResponseThreadEntry::ENTRY_TYPE
        ));
    }

    function getNotes() {
        $entries = clone $this->getEntries();
        return $entries->filter(array(
            'type' => NoteThreadEntry::ENTRY_TYPE
        ));
    }

    function addNote($vars, &$errors=array()) {
        //Add ticket Id.
        $vars['threadId'] = $this->getId();
        return NoteThreadEntry::add($vars, $errors);
    }

    function addMessage($vars, &$errors) {
        $vars['threadId'] = $this->getId();
        $vars['staffId'] = 0;

        if (!($message = MessageThreadEntry::add($vars, $errors)))
            return $message;

        $this->lastmessage = SqlFunction::NOW();
        $this->save(true);
        return $message;
    }

    function addResponse($vars, &$errors) {
        $vars['threadId'] = $this->getId();
        $vars['userId'] = 0;
        if ($message = $this->getLastMessage())
            $vars['pid'] = $message->getId();

        $vars['flags'] = 0;

        if (!($resp = ResponseThreadEntry::add($vars, $errors)))
            return $resp;

        $this->lastresponse = SqlFunction::NOW();
        $this->save(true);
        return $resp;
    }

    function __toString() {
        return $this->asVar();
    }

    function asVar() {
        return new ThreadEntries($this);
    }

    function getVar($name) {
        switch ($name) {
        case 'original':
            $entry = $this->entries->filter(array(
                'type' => MessageThreadEntry::ENTRY_TYPE,
                'flags__hasbit' => ThreadEntry::FLAG_ORIGINAL_MESSAGE,
                ))
                ->order_by('id')
                ->first();
            if ($entry)
                return $entry->getBody();

            break;
        case 'last_message':
        case 'lastmessage':
            $entry = $this->getLastMessage();
            if ($entry)
                return $entry->getBody();

            break;
        case 'complete':
            return $this->asVar();
            break;
        }
    }

    static function getVarScope() {
      return array(
        'complete' =>array('class' => 'ThreadEntries', 'desc' => __('Thread Correspondence')),
        'original' => array('class' => 'MessageThreadEntry', 'desc' => __('Original Message')),
        'lastmessage' => array('class' => 'MessageThreadEntry', 'desc' => __('Last Message')),
      );
    }

    static function lookup($criteria, $type=false) {
        if (!$type)
            return parent::lookup($criteria);

        $class = false;
        if (isset(self::$types[$type]))
            $class = self::$types[$type];
        if (!class_exists($class))
            $class = get_called_class();

        return $class::lookup($criteria);
    }
}

class ThreadEntries {
    var $thread;

    function __construct($thread) {
        $this->thread = $thread;
    }

    function __tostring() {
        return (string) $this->getVar();
    }

    function asVar() {
        return $this->getVar();
    }

    function getVar($name='') {

        $order = '';
        switch ($name) {
        case 'reversed':
            $order = '-';
        default:
            $content = '';
            $thread = $this->thread;
            ob_start();
            include INCLUDE_DIR.'client/templates/thread-export.tmpl.php';
            $content = ob_get_contents();
            ob_end_clean();
            return $content;
            break;
        }
    }

    static function getVarScope() {
      return array(
        'reversed' => sprintf('%s %s', __('Thread Correspondence'),
            __('in reversed order')),
      );
    }
}

// Ticket thread class
class TicketThread extends ObjectThread {
    static function create($ticket=false) {
        assert($ticket !== false);

        $id = is_object($ticket) ? $ticket->getId() : $ticket;
        $thread = parent::create(array(
                    'object_id' => $id,
                    'object_type' => ObjectModel::OBJECT_TYPE_TICKET
                    ));
        if ($thread->save())
            return $thread;
    }
}

/**
 * Class: ThreadEntryAction
 *
 * Defines a simple action to be performed on a thread entry item, such as
 * viewing the raw email headers used to generate the message, resend the
 * confirmation emails, etc.
 */
abstract class ThreadEntryAction {
    static $name;               // Friendly, translatable name
    static $id;                 // Unique identifier used for plumbing
    static $icon = 'cog';

    var $entry;

    function getName() {
        $class = get_class($this);
        return __($class::$name);
    }

    static function getId() {
        return static::$id;
    }

    function getIcon() {
        $class = get_class($this);
        return 'icon-' . $class::$icon;
    }

    function getObJectId() {
        return $this->entry->getThread()->getObjectId();
    }

    function __construct(ThreadEntry $thread) {
        $this->entry = $thread;
    }

    abstract function trigger();

    function isEnabled() {
        return $this->isVisible();
    }
    function isVisible() {
        return true;
    }

    /**
     * getJsStub
     *
     * Retrieves a small JavaScript snippet to insert into the rendered page
     * which should, via an AJAX callback, trigger this action to be
     * performed. The URL for this sort of activity is already provided for
     * you via the ::getAjaxUrl() method in this class.
     */
    abstract function getJsStub();

    /**
     * getAttributes
     *
     * Atributos con los que la plantilla abre la acción sin JavaScript en
     * línea (CSP sin 'unsafe-inline'). Por defecto es un diálogo con la URL
     * de la acción; las acciones con más lógica lo amplían.
     */
    function getAttributes() {
        return array('data-ost-dialog-url' => $this->getAjaxUrl());
    }

    /**
     * getAjaxUrl
     *
     * Generate a URL to be used as an AJAX callback. The URL can be used to
     * trigger this thread entry action via the callback.
     *
     * Parameters:
     * $dialog - (bool) used in conjunction with `$.dialog()` javascript
     *      function which assumes the `ajax.php/` should be replace a leading
     *      `#` in the url
     */
    function getAjaxUrl($dialog=false) {
        return sprintf('%s%s/%d/thread/%d/%s',
            $dialog ? '#' : 'ajax.php/',
            $this->entry->getThread()->getObjectType() == 'T' ? 'tickets' : 'tasks',
            $this->entry->getThread()->getObjectId(),
            $this->entry->getId(),
            static::getId()
        );
    }

    function getTicketsAPI() {
        return new TicketsAjaxAPI();
    }

    function getTasksAPI() {
        return new TasksAjaxAPI();
    }
}

/**
 * ThreadActivity
 *
 * Object to thread activity
 *
 */
class ThreadActivity implements TemplateVariable {
    var $title;
    var $desc;

    function __construct($title, $desc) {
        $this->title = $title;
        $this->desc = $desc;
    }

    function getTitle() {
        return $this->title;
    }

    function getDescription() {
        return $this->desc;
    }
    function asVar() {
        return (string) $this->getTitle();
    }

    function getVar($tag) {
        if ($tag && is_callable(array($this, 'get'.ucfirst($tag))))
            return call_user_func(array($this, 'get'.ucfirst($tag)));

        return false;
    }

    static function getVarScope() {
        return array(
          'title' => __('Activity Title'),
          'description' => __('Activity Description'),
        );
    }
}
