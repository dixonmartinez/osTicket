<?php
/*********************************************************************
    class.ticket.php

    The most important class! Don't play with fire please.

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
include_once(INCLUDE_DIR.'class.ticket.post.php');
include_once(INCLUDE_DIR.'class.ticket.edit.php');
include_once(INCLUDE_DIR.'class.model.rules.php');
include_once(INCLUDE_DIR.'class.ticket.create.php');
/* Avisos y eventos del ticket: ver class.ticket.events.php */
include_once(INCLUDE_DIR.'class.ticket.events.php');
/* Variables de plantilla y campos buscables: ver class.ticket.variables.php */
include_once(INCLUDE_DIR.'class.ticket.variables.php');
/* Alta, edicion y cierre del ticket: ver class.ticket.lifecycle.php */
include_once(INCLUDE_DIR.'class.ticket.lifecycle.php');
/* Respuestas y colaboradores del hilo: ver class.ticket.replies.php */
include_once(INCLUDE_DIR.'class.ticket.replies.php');
include_once(INCLUDE_DIR.'class.thread.php');
include_once(INCLUDE_DIR.'class.staff.php');
include_once(INCLUDE_DIR.'class.client.php');
include_once(INCLUDE_DIR.'class.team.php');
include_once(INCLUDE_DIR.'class.email.php');
include_once(INCLUDE_DIR.'class.dept.php');
include_once(INCLUDE_DIR.'class.topic.php');
include_once(INCLUDE_DIR.'class.lock.php');
include_once(INCLUDE_DIR.'class.file.php');
include_once(INCLUDE_DIR.'class.export.php');
include_once(INCLUDE_DIR.'class.attachment.php');
include_once(INCLUDE_DIR.'class.banlist.php');
include_once(INCLUDE_DIR.'class.template.php');
include_once(INCLUDE_DIR.'class.variable.php');
include_once(INCLUDE_DIR.'class.priority.php');
include_once(INCLUDE_DIR.'class.sla.php');
include_once(INCLUDE_DIR.'class.canned.php');
require_once(INCLUDE_DIR.'class.dynamic_forms.php');
require_once(INCLUDE_DIR.'class.user.php');
require_once(INCLUDE_DIR.'class.collaborator.php');
require_once(INCLUDE_DIR.'class.task.php');
require_once(INCLUDE_DIR.'class.faq.php');

class Ticket extends VerySimpleModel
implements RestrictedAccess, Threadable, Searchable {
    use TicketEvents;
    use TicketVariables;
    use TicketLifecycle;
    use TicketReplies;




    static $meta = array(
        'table' => TICKET_TABLE,
        'pk' => array('ticket_id'),
        'select_related' => array('topic', 'staff', 'user', 'team', 'dept',
            'sla', 'thread', 'child_thread', 'user__default_email', 'status'),
        'joins' => array(
            'user' => array(
                'constraint' => array('user_id' => 'User.id'),
                'null' => true,
            ),
            'status' => array(
                'constraint' => array('status_id' => 'TicketStatus.id')
            ),
            'lock' => array(
                'constraint' => array('lock_id' => 'Lock.lock_id'),
                'null' => true,
            ),
            'dept' => array(
                'constraint' => array('dept_id' => 'Dept.id'),
                'null' => true,
            ),
            'sla' => array(
                'constraint' => array('sla_id' => 'Sla.id'),
                'null' => true,
            ),
            'staff' => array(
                'constraint' => array('staff_id' => 'Staff.staff_id'),
                'null' => true,
            ),
            'tasks' => array(
                'reverse' => 'Task.ticket',
            ),
            'team' => array(
                'constraint' => array('team_id' => 'Team.team_id'),
                'null' => true,
            ),
            'topic' => array(
                'constraint' => array('topic_id' => 'Topic.topic_id'),
                'null' => true,
            ),
            'thread' => array(
                'reverse' => 'TicketThread.ticket',
                'list' => false,
                'null' => true,
            ),
            'child_thread' => array(
                'constraint' => array(
                    'ticket_id'  => 'TicketThread.object_id',
                    "'C'" => 'TicketThread.object_type',
                ),
                'searchable' => false,
                'null' => true,
            ),
            'cdata' => array(
                'reverse' => 'TicketCData.ticket',
                'list' => false,
            ),
            'entries' => array(
                'constraint' => array(
                    "'T'" => 'DynamicFormEntry.object_type',
                    'ticket_id' => 'DynamicFormEntry.object_id',
                ),
                'list' => true,
            ),
        )
    );

    const PERM_CREATE   = 'ticket.create';
    const PERM_EDIT     = 'ticket.edit';
    const PERM_ASSIGN   = 'ticket.assign';
    const PERM_RELEASE  = 'ticket.release';
    const PERM_TRANSFER = 'ticket.transfer';
    const PERM_REFER    = 'ticket.refer';
    const PERM_MERGE    = 'ticket.merge';
    const PERM_LINK     = 'ticket.link';
    const PERM_REPLY    = 'ticket.reply';
    const PERM_MARKANSWERED = 'ticket.markanswered';
    const PERM_CLOSE    = 'ticket.close';
    const PERM_DELETE   = 'ticket.delete';

    const FLAG_COMBINE_THREADS     = 0x0001;
    const FLAG_SEPARATE_THREADS    = 0x0002;
    const FLAG_LINKED              = 0x0008;
    const FLAG_PARENT              = 0x0010;

    static protected $perms = array(
            self::PERM_CREATE => array(
                'title' =>
                /* @trans */ 'Create',
                'desc'  =>
                /* @trans */ 'Ability to open tickets on behalf of users'),
            self::PERM_EDIT => array(
                'title' =>
                /* @trans */ 'Edit',
                'desc'  =>
                /* @trans */ 'Ability to edit tickets'),
            self::PERM_ASSIGN => array(
                'title' =>
                /* @trans */ 'Assign',
                'desc'  =>
                /* @trans */ 'Ability to assign tickets to agents or teams'),
            self::PERM_RELEASE => array(
                'title' =>
                /* @trans */ 'Release',
                'desc'  =>
                /* @trans */ 'Ability to release ticket assignment'),
            self::PERM_TRANSFER => array(
                'title' =>
                /* @trans */ 'Transfer',
                'desc'  =>
                /* @trans */ 'Ability to transfer tickets between departments'),
            self::PERM_REFER => array(
                'title' =>
                /* @trans */ 'Refer',
                'desc'  =>
                /* @trans */ 'Ability to manage ticket referrals'),
            self::PERM_MERGE => array(
                'title' =>
                /* @trans */ 'Merge',
                'desc'  =>
                /* @trans */ 'Ability to merge tickets'),
            self::PERM_LINK => array(
                'title' =>
                /* @trans */ 'Link',
                'desc'  =>
                /* @trans */ 'Ability to link tickets'),
            self::PERM_REPLY => array(
                'title' =>
                /* @trans */ 'Post Reply',
                'desc'  =>
                /* @trans */ 'Ability to post a ticket reply'),
            self::PERM_MARKANSWERED => array(
                'title' =>
                /* @trans */ 'Mark as Answered',
                'desc'  =>
                /* @trans */ 'Ability to mark a ticket as Answered/Unanswered'),
            self::PERM_CLOSE => array(
                'title' =>
                /* @trans */ 'Close',
                'desc'  =>
                /* @trans */ 'Ability to close tickets'),
            self::PERM_DELETE => array(
                'title' =>
                /* @trans */ 'Delete',
                'desc'  =>
                /* @trans */ 'Ability to delete tickets'),
            );

    // Ticket Sources
    static protected $sources =  array(
            'Phone' =>
            /* @trans */ 'Phone',
            'Email' =>
            /* @trans */ 'Email',

            'Web' =>
            /* @trans */ 'Web',
            'API' =>
            /* @trans */ 'API',
            'Other' =>
            /* @trans */ 'Other',
            );

    var $lastMsgId;
    var $last_message;

    var $owner;     // TicketOwner
    var $_user;      // EndUser
    var $_answers;
    var $collaborators;
    var $active_collaborators;
    var $recipients;
    var $lastrespondent;
    var $lastuserrespondent;
    var $_children;

    function loadDynamicData($force=false) {
        if (!isset($this->_answers) || $force) {
            $this->_answers = array();
            foreach (DynamicFormEntryAnswer::objects()
                ->filter(array(
                    'entry__object_id' => $this->getId(),
                    'entry__object_type' => 'T'
                )) as $answer
            ) {
                $tag = mb_strtolower($answer->field->name)
                    ?: 'field.' . $answer->field->id;
                    $this->_answers[$tag] = $answer;
            }
        }
        return $this->_answers;
    }

    function getAnswer($field, $form=null) {
        // TODO: Prefer CDATA ORM relationship if already loaded
        $this->loadDynamicData();
        return $this->_answers[$field];
    }

    function getId() {
        return $this->ticket_id;
    }

    function getPid() {
        return $this->ticket_pid;
    }

    function getChildren() {
        if (!isset($this->_children) && $this->isParent())
            $this->_children = self::getChildTickets($this->getId());

        return $this->_children ?: array();
    }

    static function getMergeTypeByFlag($flag) {
        if (($flag & self::FLAG_COMBINE_THREADS) != 0)
            return 'combine';
        if (($flag & self::FLAG_SEPARATE_THREADS) != 0)
            return 'separate';
        else
            return 'visual';
        return 'visual';
    }

    function getMergeType() {
        if ($this->hasFlag(self::FLAG_COMBINE_THREADS))
            return 'combine';
        if ($this->hasFlag(self::FLAG_SEPARATE_THREADS))
            return 'separate';
        else
            return 'visual';
        return 'visual';
    }

    function isMerged() {
        if (!is_null($this->getPid()) || $this->isParent())
            return true;

        return false;
    }

    function isParent() {
        if ($this->hasFlag(self::FLAG_PARENT))
            return true;

        return false;
    }

    static function isParentStatic($flag=false) {
        if (is_numeric($flag) && ($flag & self::FLAG_PARENT) != 0)
            return true;

        return false;
    }

    function hasFlag($flag) {
        return ($this->get('flags', 0) & $flag) != 0;
    }

    function isChild($pid=false) {
        return ($this->getPid() ? true : false);
    }

    function hasState($state) {
        return  strcasecmp($this->getState(), $state) == 0;
    }

    function isOpen() {
        return $this->hasState('open');
    }

    function isReopened() {
        return null !== $this->getReopenDate();
    }

    function isReopenable() {
        return ($this->getStatus()->isReopenable() && $this->getDept()->allowsReopen()
        && ($this->getTopic() ? $this->getTopic()->allowsReopen() : true));
    }

    function isClosed() {
         return $this->hasState('closed');
    }

    function isCloseable() {
        global $cfg;

        if ($this->isClosed())
            return true;

        $warning = null;
        if (self::getMissingRequiredFields($this)) {
            $warning = sprintf(
                    __( '%1$s is missing data on %2$s one or more required fields %3$s and cannot be closed'),
                    __('This ticket'),
                    '', '');
        } elseif (($num=$this->getNumOpenTasks())) {
            $warning = sprintf(__('%1$s has %2$d open tasks and cannot be closed'),
                    __('This ticket'), $num);
        } elseif ($cfg->requireTopicToClose() && !$this->getTopicId()) {
            $warning = sprintf(
                    __( '%1$s is missing a %2$s and cannot be closed'),
                    __('This ticket'), __('Help Topic'), '');
        }

        return $warning ?: true;
    }

    function isArchived() {
         return $this->hasState('archived');
    }

    function isDeleted() {
         return $this->hasState('deleted');
    }

    function isAssigned($to=null) {
        if (!$this->isOpen())
            return false;

        if (is_null($to))
            return ($this->getStaffId() || $this->getTeamId());

        switch (true) {
        case $to instanceof Staff:
            return ($to->getId() == $this->getStaffId() ||
                    $to->isTeamMember($this->getTeamId()));
            break;
        case $to instanceof Team:
            return ($to->getId() == $this->getTeamId());
            break;
        }

        return false;
    }

    function isOverdue() {
        return $this->ht['isoverdue'];
    }

    function isAnswered() {
       return $this->ht['isanswered'];
    }

    function isLocked() {
        return null !== $this->getLock();
    }

    function getRole($staff) {
        if (!$staff instanceof Staff)
            return null;

        return $staff->getRole($this->getDept(), $this->isAssigned($staff));
    }

    function checkStaffPerm($staff, $perm=null) {

        // Must be a valid staff
        if ((!$staff instanceof Staff) && !($staff=Staff::lookup($staff)))
            return false;

        // check department access first
        if (!$staff->canAccessDept($this->getDept())
                // check assignment
                && !$this->isAssigned($staff)
                // check referral
                && !$this->getThread()->isReferred($staff))
            return false;

        // At this point staff has view access unless a specific permission is
        // requested
        if ($perm === null)
            return true;

        // Permission check requested -- get role if any
        if (!($role=$this->getRole($staff)))
            return false;

        // Check permission based on the effective role
        return $role->hasPerm($perm);
    }

    function checkUserAccess($user) {
        if (!$user || !($user instanceof EndUser))
            return false;

        // Ticket Owner
        if ($user->getId() == $this->getUserId())
            return true;

        // Organization
        if ($user->canSeeOrgTickets()
            && ($U = $this->getUser())
            && ($U->getOrgId() == $user->getOrgId())
        ) {
            // The owner of this ticket is in the same organization as the
            // user in question, and the organization is configured to allow
            // the user in question to see other tickets in the
            // organization.
            return true;
        }

        // Collaborator?
        // 1) If the user was authorized via this ticket.
        if ($user->getTicketId() == $this->getId()
            && !strcasecmp($user->getUserType(), 'collaborator')
        ) {
            return true;
        }
        // 2) Query the database to check for expanded access...
        if (Collaborator::lookup(array(
            'user_id' => $user->getId(),
            'thread_id' => $this->getThreadId()))
        ) {
            return true;
        }
        // 3) If the ticket is a child of a merge
        if ($this->isParent() && $this->getMergeType() != 'visual') {
            $children = Ticket::objects()
                    ->filter(array('ticket_pid'=>$this->getId()))
                    ->order_by('sort');

            foreach ($children as $child)
                if ($child->checkUserAccess($user))
                    return true;
        }

        return false;
    }

    // Getters
    function getNumber() {
        return $this->number;
    }

    function getOwnerId() {
        return $this->user_id;
    }

    function getOwner() {
        if (!isset($this->owner)) {
            $this->owner = new TicketOwner(new EndUser($this->user), $this);
        }
        return $this->owner;
    }

    function getEmail() {
        if ($o = $this->getOwner()) {
            return $o->getEmail();
        }
        return null;
    }

    function getReplyToEmail() {
        //TODO: Determine the email to use (once we enable multi-email support)
        return $this->getEmail();
    }

    // Deprecated
    function getOldAuthToken() {
        # XXX: Support variable email address (for CCs)
        return md5($this->getId() . strtolower($this->getEmail()) . SECRET_SALT);
    }

    function getName(){
        if ($o = $this->getOwner()) {
            return $o->getName();
        }
        return null;
    }

    function getSubject() {
        return (string) $this->getAnswer('subject');
    }

    /* Help topic title  - NOT object -> $topic */
    function getHelpTopic() {
        if ($this->topic)
            return $this->topic->getFullName();
    }

    function getCreateDate() {
        return $this->created;
    }

    function getOpenDate() {
        return $this->getCreateDate();
    }

    function getReopenDate() {
        return $this->reopened;
    }

    function getUpdateDate() {
        return $this->updated;
    }

    function getEffectiveDate() {
        return $this->lastupdate;
    }

    function getDueDate() {
        return $this->duedate;
    }

    function getSLADueDate($recompute=false) {
        global $cfg;

        if (!$recompute && $this->est_duedate)
            return $this->est_duedate;

        if (($sla = $this->getSLA()) && $sla->isActive()) {
            $schedule = $this->getDept()->getSchedule();
            $tz = new DateTimeZone($cfg->getDbTimezone());
            $dt = new DateTime($this->getReopenDate() ?:
                    $this->getCreateDate(), $tz);
            $dt = $sla->addGracePeriod($dt, $schedule);
            // Make sure time is in DB timezone
            $dt->setTimezone($tz);
            return $dt->format('Y-m-d H:i:s');
        }
    }

    function updateEstDueDate($clearOverdue=true) {
        if ($this->isOverdue() && $clearOverdue)
            $this->clearOverdue(false);

        $this->est_duedate = $this->getSLADueDate(true) ?: null;

        return $this->save();
    }

    function getEstDueDate() {
        // Real due date or  sla due date (If ANY)
        return $this->getDueDate() ?: $this->getSLADueDate();
    }


    function getCloseDate() {
        return $this->closed;
    }

    function getStatusId() {
        return $this->status_id;
    }

    /**
     * setStatusId
     *
     * Forceably set the ticket status ID to the received status ID. No
     * checks are made. Use ::setStatus() to change the ticket status
     */
    // XXX: Use ::setStatus to change the status. This can be used as a
    //      fallback if the logic in ::setStatus fails.
    function setStatusId($id) {
        $this->status_id = $id;
        return $this->save();
    }

    function getStatus() {
        return $this->status;
    }

    function getState() {
        if (!$this->getStatus()) {
            return '';
        }
        return $this->getStatus()->getState();
    }

    function getDeptId() {
       return $this->dept_id;
    }

    function getDeptName() {
        if ($this->dept instanceof Dept)
            return $this->dept->getFullName();
    }

    function getPriorityId() {
        global $cfg;

        if (($priority = $this->getPriority()))
            return $priority->getId();

        return $cfg->getDefaultPriorityId();
    }

    function getPriority() {
        if (($a = $this->getAnswer('priority')))
            return $a->getValue();

        return null;
    }

    function getPriorityField() {
        if (($a = $this->getAnswer('priority')))
            return $a->getField();

        return TicketForm::getInstance()->getField('priority');
    }

    function getPhoneNumber() {
        return (string)$this->getOwner()->getPhoneNumber();
    }

    function getSource() {
        $sources = $this->getSources();
        return $sources[$this->source] ?: $this->source;
    }

    function getIP() {
        return $this->ip_address;
    }

    function getHashtable() {
        return $this->ht;
    }

    function getUpdateInfo() {
        global $cfg;

        return array(
            'source'    => $this->source,
            'topicId'   => $this->getTopicId(),
            'slaId'     => $this->getSLAId(),
            'user_id'   => $this->getOwnerId(),
            'duedate'   => Misc::db2gmtime($this->getDueDate()),
        );
    }

    function getLock() {
        $lock = $this->lock;
        if ($lock && !$lock->isExpired())
            return $lock;
    }

    function acquireLock($staffId, $lockTime=null) {
        global $cfg;

        if (!isset($lockTime))
            $lockTime = $cfg->getLockTime();

        if (!$staffId or !$lockTime) //Lockig disabled?
            return null;

        // Check if the ticket is already locked.
        if (($lock = $this->getLock()) && !$lock->isExpired()) {
            if ($lock->getStaffId() != $staffId) //someone else locked the ticket.
                return null;

            //Lock already exits...renew it
            $lock->renew($lockTime); //New clock baby.

            return $lock;
        }
        // No lock on the ticket or it is expired
        $this->lock = Lock::acquire($staffId, $lockTime); //Create a new lock..

        if ($this->lock) {
            $this->save();
        }

        // load and return the newly created lock if any!
        return $this->lock;
    }

    function releaseLock($staffId=false) {
        if (!($lock = $this->getLock()))
            return false;

        if ($staffId && $lock->staff_id != $staffId)
            return false;

        if (!$lock->delete())
            return false;

        $this->lock = 0;
        return $this->save();
    }

    function getDept() {
        global $cfg;

        return $this->dept ?: $cfg->getDefaultDept();
    }

    function getUserId() {
        return $this->getOwnerId();
    }

    function getUser() {
        if (!isset($this->_user) && $this->user) {
            $this->_user = new EndUser($this->user);
        }
        return $this->_user;
    }

    function getStaffId() {
        return $this->staff_id;
    }

    function getStaff() {
        return $this->staff;
    }

    function getTeamId() {
        return $this->team_id;
    }

    function getTeam() {
        return $this->team;
    }

    function getAssigneeId() {

        if (!($assignee=$this->getAssignee()))
            return null;

        $id = '';
        if ($assignee instanceof Staff)
            $id = 's'.$assignee->getId();
        elseif ($assignee instanceof Team)
            $id = 't'.$assignee->getId();

        return $id;
    }

    function getAssignee() {

        if (!$this->isOpen() || !$this->isAssigned())
            return false;

        if ($this->staff)
            return $this->staff;

        if ($this->team)
            return $this->team;

        return null;
    }

    function getAssignees() {

        $assignees = array();
        if ($staff = $this->getStaff())
            $assignees[] = $staff->getName();

        if ($team = $this->getTeam())
            $assignees[] = $team->getName();

        return $assignees;
    }

    function getAssigned($glue='/') {
        $assignees = $this->getAssignees();
        return $assignees ? implode($glue, $assignees) : '';
    }

    function getTopicId() {
        return $this->topic_id;
    }

    function getTopic() {
        return $this->topic;
    }


    function getSLAId() {
        return $this->sla_id;
    }

    function getSLA() {
        return $this->sla;
    }

    function getLastRespondent() {
        if (!isset($this->lastrespondent)) {
            if (!$this->getThread() || !$this->getThread()->entries)
                return $this->lastrespondent = false;
            $this->lastrespondent = Staff::objects()
                ->filter(array(
                'staff_id' => $this->getThread()->entries
                    ->filter(array(
                        'type' => 'R',
                        'staff_id__gt' => 0,
                    ))
                    ->values_flat('staff_id')
                    ->order_by('-id')
                    ->limit('1,1')
                ))
                ->first()
                ?: false;
        }
        return $this->lastrespondent;
    }

    function getLastUserRespondent() {
        if (!isset($this->$lastuserrespondent)) {
            if (!$this->getThread() || !$this->getThread()->entries)
                return $this->$lastuserrespondent = false;
            $this->$lastuserrespondent = User::objects()
                ->filter(array(
                'id' => $this->getThread()->entries
                    ->filter(array(
                        'user_id__gt' => 0,
                    ))
                    ->values_flat('user_id')
                    ->order_by('-id')
                    ->limit(1)
                ))
                ->first()
                ?: false;
        }
        return $this->$lastuserrespondent;
    }

    function getLastMessageDate() {
        return $this->getThread()->lastmessage;
    }

    function getLastMsgDate() {
        return $this->getLastMessageDate();
    }

    function getLastResponseDate() {
        return $this->getThread()->lastresponse;
    }

    function getLastRespDate() {
        return $this->getLastResponseDate();
    }

    function getLastMsgId() {
        return $this->lastMsgId;
    }

    function getLastMessage() {
        if (!isset($this->last_message)) {
            if ($this->getLastMsgId())
                $this->last_message = MessageThreadEntry::lookup(
                    $this->getLastMsgId(), $this->getThreadId());

            if (!$this->last_message)
                $this->last_message = $this->getThread() ? $this->getThread()->getLastMessage() : '';
        }
        return $this->last_message;
    }

    function getNumTasks() {
        // FIXME: Implement this after merging Tasks
        return count($this->tasks);
    }

    function getNumOpenTasks() {
        return count($this->tasks->filter(array(
                        'flags__hasbit' => TaskModel::ISOPEN)));
    }


    function getThreadId() {
        if ($this->getThread())
            return $this->getThread()->getId();
    }

    function getThread() {
        if (is_null($this->thread) && $this->child_thread)
            return $this->child_thread;

        return $this->thread;
    }

    function getThreadCount() {
        return $this->getClientThread()->count();
    }

    function getNumMessages() {
        return $this->getThread()->getNumMessages();
    }

    function getNumResponses() {
        return $this->getThread()->getNumResponses();
    }

    function getNumNotes() {
        return $this->getThread()->getNumNotes();
    }

    function getMessages() {
        return $this->getThreadEntries(array('M'));
    }

    function getResponses() {
        return $this->getThreadEntries(array('R'));
    }

    function getNotes() {
        return $this->getThreadEntries(array('N'));
    }

    function getClientThread() {
        return $this->getThreadEntries(array('M', 'R'));
    }

    function getThreadEntry($id) {
        return $this->getThread()->getEntry($id);
    }

    function getThreadEntries($type=false) {
        if ($this->getThread()) {
            $entries = $this->getThread()->getEntries();
            if ($type && is_array($type))
                $entries->filter(array('type__in' => $type));
        }

        return $entries;
    }

    // MailingList of participants  (owner + collaborators)
    function getRecipients($who='all', $whitelist=array(), $active=true) {
        $list = new MailingList();
        switch (strtolower($who)) {
            case 'user':
                $list->addTo($this->getOwner());
                break;
            case 'all':
                $list->addTo($this->getOwner());
                // Fall-trough
            case 'collabs':
                if (($collabs = $active ?  $this->getActiveCollaborators() :
                    $this->getCollaborators())) {
                    foreach ($collabs as $c)
                        if (!$whitelist || in_array($c->getUserId(),
                                    $whitelist))
                            $list->addCc($c);
                }
                break;
            default:
                return null;
        }
        return $list;
    }

    function getCollaborators() {
        return $this->getThread() ? $this->getThread()->getCollaborators() : '';
    }

    function getNumCollaborators() {
        return $this->getThread() ? $this->getThread()->getNumCollaborators() : '';
    }

    function getActiveCollaborators() {
        return $this->getThread() ? $this->getThread()->getActiveCollaborators() : '';
    }

    function getNumActiveCollaborators() {
        return $this->getThread() ? $this->getThread()->getNumActiveCollaborators() : '';
    }

    function getAssignmentForm($source=null, $options=array()) {
        global $thisstaff;

        $prompt = $assignee = '';
        // Possible assignees
        $dept = $this->getDept();
        switch (strtolower($options['target'])) {
            case 'agents':
                if (!$source && $this->isOpen() && $this->staff)
                    $assignee = sprintf('s%d', $this->staff->getId());
                $prompt = __('Select an Agent');
                break;
            case 'teams':
                if (!$source && $this->isOpen() && $this->team)
                    $assignee = sprintf('t%d', $this->team->getId());
                $prompt = __('Select a Team');
                break;
        }

        // Default to current assignee if source is not set
        if (!$source)
            $source = array('assignee' => array($assignee));

        $form = AssignmentForm::instantiate($source, $options);

        if (($refer = $form->getField('refer'))) {
            if (!$assignee) {
                $visibility = new VisibilityConstraint(
                        new Q(array()), VisibilityConstraint::HIDDEN);
                $refer->set('visibility', $visibility);
            } else {
                $refer->configure('desc', sprintf(__('Maintain referral access to %s'),
                        $this->getAssigned()));
            }
        }

        // Field configurations
        if ($f=$form->getField('assignee')) {
            $f->configure('dept', $dept);
            $f->configure('staff', $thisstaff);
            if ($prompt)
                $f->configure('prompt', $prompt);
            if ($options['target'])
                $f->configure('target', $options['target']);
        }

        return $form;
    }

    function getReferralForm($source=null, $options=array()) {
        global $thisstaff;

        $form = ReferralForm::instantiate($source, $options);
        $dept = $this->getDept();
        // Agents
        $staff = Staff::objects()->filter(array(
         'isactive' => 1,
         ))
         ->filter(Q::not(array('dept_id' => $dept->getId())));

        $staff = Staff::nsort($staff);
        $agents = array();
        foreach ($staff as $s)
          $agents[$s->getId()] = $s;
        $form->setChoices('agent', $agents);
        // Teams
        $form->setChoices('team', Team::getActiveTeams());
        // Depts
        $form->setChoices('dept', Dept::getActiveDepartments());

        // Field configurations
        if ($f=$form->getField('agent')) {
            $f->configure('dept', $dept);
            $f->configure('staff', $thisstaff);
        }

        if ($f = $form->getField('dept'))
            $f->configure('hideDisabled', true);

        return $form;
    }

    function getClaimForm($source=null, $options=array()) {
        global $thisstaff;

        $id = sprintf('s%d', $thisstaff->getId());
        if(!$source)
            $source = array('assignee' => array($id));

        $form = ClaimForm::instantiate($source, $options);
        $form->setAssignees(array($id => $thisstaff->getName()));

        return $form;

    }

    function getTransferForm($source=null) {
        global $thisstaff;

        if (!$source)
            $source = array('dept' => array($this->getDeptId()),
                    'refer' => false);

        $form = TransferForm::instantiate($source);

        $form->hideDisabled();

        return $form;
    }

    function getField($fid) {

        if (is_numeric($fid))
            return $this->getDynamicFieldById($fid);

        // Special fields
        switch ($fid) {
        case 'priority':
            return $this->getPriorityField();
            break;
        case 'sla':
            return SLAField::init(array(
                        'id' => $fid,
                        'name' => "{$fid}_id",
                        'label' => __('SLA Plan'),
                        'default' => $this->getSLAId(),
                        'choices' => SLA::getSLAs()
                        ));
            break;
        case 'topic':
            $current = array();
            if ($topic = $this->getTopic())
                $current = array($topic->getId());
            $choices = Topic::getHelpTopics(false, $topic ? (Topic::DISPLAY_DISABLED) : false, true, $current);
            return TopicField::init(array(
                        'id' => $fid,
                        'name' => "{$fid}_id",
                        'label' => __('Help Topic'),
                        'default' => $this->getTopicId(),
                        'choices' => $choices
                        ));
            break;
        case 'source':
            return ChoiceField::init(array(
                        'id' => $fid,
                        'name' => 'source',
                        'label' => __('Ticket Source'),
                        'default' => $this->source,
                        'choices' => Ticket::getSources()
                        ));
            break;
        case 'duedate':

            $hint = sprintf(__('Setting a %s will override %s'),
                    __('Due Date'), __('SLA Plan'));
            return DateTimeField::init(array(
                'id' => $fid,
                'name' => $fid,
                'default' => Misc::db2gmtime($this->getDueDate()),
                'label' => __('Due Date'),
                'hint' => $hint,
                'configuration' => array(
                    'min' => Misc::gmtime(),
                    'time' => true,
                    'gmt' => false,
                    'future' => true,
                    )
                ));
        }
    }

    function getDynamicFieldById($fid) {
        foreach (DynamicFormEntry::forTicket($this->getId()) as $form) {
            foreach ($form->getFields() as $field)
                if ($field->getId() == $fid) {
                    // This is to prevent SimpleForm using index name as
                    // field name when one is not set.
                    if (!$field->get('name'))
                        $field->set('name', "field_$fid");

                    return $field;
                }
        }
    }

    function getDynamicFields($criteria=array()) {

        $fields = DynamicFormField::objects()->filter(array(
                    'id__in' => $this->entries
                    ->filter($criteria)
                ->values_flat('answers__field_id')));

        return ($fields && count($fields)) ? $fields : array();
    }

    function hasClientEditableFields() {
        $forms = DynamicFormEntry::forTicket($this->getId());
        foreach ($forms as $form) {
            foreach ($form->getFields() as $field) {
                if ($field->isEditableToUsers())
                    return true;
            }
        }
    }

    //if ids passed, function returns only the ids of fields disabled by help topic
    static function getMissingRequiredFields($ticket, $ids=false) {
        // Check for fields disabled by Help Topic
        $disabled = array();
        foreach (($ticket->getTopic() ? $ticket->getTopic()->forms : $ticket->entries) as $f) {
            $extra = JsonDataParser::decode($f->extra);

            if (!empty($extra['disable']))
                $disabled[] = $extra['disable'];
        }

        $disabled = !empty($disabled) ? call_user_func_array('array_merge', $disabled) : NULL;

        if ($ids)
          return $disabled;

        $criteria = array(
                    'answers__field__flags__hasbit' => DynamicFormField::FLAG_ENABLED,
                    'answers__field__flags__hasbit' => DynamicFormField::FLAG_CLOSE_REQUIRED,
                    'answers__value__isnull' => true,
                    );

        // If there are disabled fields then exclude them
        if ($disabled)
            array_push($criteria, Q::not(array('answers__field__id__in' => $disabled)));

        return $ticket->getDynamicFields($criteria);
    }

    function getMissingRequiredField() {
        $fields = self::getMissingRequiredFields($this);
        return $fields ? $fields[0] : null;
    }

    function addCollaborator($user, $vars, &$errors, $event=true) {

        if ($user && $user->getId() == $this->getOwnerId())
            $errors['err'] = __('Ticket Owner cannot be a Collaborator');

        if ($user && !$errors
                && ($c = $this->getThread()->addCollaborator($user, $vars,
                        $errors, $event))) {
            $c->setCc($c->active);
            $this->collaborators = null;
            $this->recipients = null;
            return $c;
        }

        return null;
    }

    function addCollaborators($users, $vars, &$errors, $event=true) {

        if (!$users || !is_array($users))
            return null;

        $collabs = $this->getCollaborators();
        $new = array();
        foreach ($users as $user) {
            if (!($user instanceof User)
                    && !($user = User::lookup($user)))
                continue;
            if ($collabs->findFirst(array('user_id' => $user->getId())))
                continue;
            if ($user->getId() == $this->getOwnerId())
                continue;

            if ($c=$this->addCollaborator($user, $vars, $errors, $event))
                $new[] = $c;
        }
        return $new;
    }

    
    function getAuthToken($user, $algo=1) {

        //Format: // <user type><algo id used>x<pack of uid & tid><hash of the algo>
        $authtoken = sprintf('%s%dx%s',
                ($user->getId() == $this->getOwnerId() ? 'o' : 'c'),
                $algo,
                Base32::encode(pack('VV',$user->getId(), $this->getId())));

        switch($algo) {
            case 1:
                $authtoken .= substr(base64_encode(
                            md5($user->getId().$this->getCreateDate().$this->getId().SECRET_SALT, true)), 8);
                break;
            default:
                return null;
        }

        return $authtoken;
    }

    function sendAccessLink($user) {
        global $ost;

        if (!($email = $ost->getConfig()->getDefaultEmail())
            || !($content = Page::lookupByType('access-link')))
            return;

        $vars = array(
            'url' => $ost->getConfig()->getBaseUrl(),
            'ticket' => $this,
            'user' => $user,
            'recipient' => $user,
            // Get ticket link, with authcode, directly to bypass collabs
            // check
            'recipient.ticket_link' => $user->getTicketLink(),
        );

        $lang = $user->getLanguage(UserAccount::LANG_MAILOUTS);
        $msg = $ost->replaceTemplateVariables(array(
            'subj' => $content->getLocalName($lang),
            'body' => $content->getLocalBody($lang),
        ), $vars);

        $email->send($user, Format::striptags($msg['subj']),
            $msg['body']);
    }


    /* -------------------- Setters --------------------- */
    public function setFlag($flag, $val) {

        if ($val)
            $this->flags |= $flag;
        else
            $this->flags &= ~$flag;
    }

    function setMergeType($combine=false, $parent=false) {
        //for $combine, 0 = separate, 1 = combine, 2 = link, 3 = regular ticket
        $flags = array(Ticket::FLAG_SEPARATE_THREADS, Ticket::FLAG_COMBINE_THREADS, Ticket::FLAG_LINKED);
        foreach ($flags as $key => $flag) {
            if ($combine == $key)
                $this->setFlag($flag, true);
            else
                $this->setFlag($flag, false);
        }
        if ($parent)
            $this->setFlag(Ticket::FLAG_PARENT, true);
        else
            $this->setFlag(Ticket::FLAG_PARENT, false);

        $this->save();
    }

    function setPid($pid) {
        return $this->ticket_pid = $this->getId() != $pid ? $pid : NULL;
    }

    function setSort($sort) {
        return $this->sort=$sort;
    }

    function setLastMsgId($msgid) {
        return $this->lastMsgId=$msgid;
    }
    function setLastMessage($message) {
        $this->last_message = $message;
        $this->setLastMsgId($message->getId());
    }

    //DeptId can NOT be 0. No orphans please!
    function setDeptId($deptId) {
        // Make sure it's a valid department
        if ($deptId == $this->getDeptId() || !($dept=Dept::lookup($deptId))) {
            return false;
        }
        $this->dept = $dept;
        return $this->save();
    }

    // Set staff ID...assign/unassign/release (id can be 0)
    function setStaffId($staffId) {
        if (!is_numeric($staffId))
            return false;

        $this->staff = Staff::lookup($staffId);
        return $this->save();
    }

    function setSLAId($slaId) {
        if ($slaId == $this->getSLAId())
            return true;

        $sla = null;
        if ($slaId && !($sla = Sla::lookup($slaId)))
            return false;

        $this->sla = $sla;
        return $this->save();
    }
    /**
     * Selects the appropriate service-level-agreement plan for this ticket.
     * When tickets are transfered between departments, the SLA of the new
     * department should be applied to the ticket. This would be useful,
     * for instance, if the ticket is transferred to a different department
     * which has a shorter grace period, the ticket should be considered
     * overdue in the shorter window now that it is owned by the new
     * department.
     *
     * $trump - if received, should trump any other possible SLA source.
     *          This is used in the case of email filters, where the SLA
     *          specified in the filter should trump any other SLA to be
     *          considered.
     */
    function selectSLAId($trump=null) {
        global $cfg;
        # XXX Should the SLA be overridden if it was originally set via an
        #     email filter? This method doesn't consider such a case
        if ($trump && is_numeric($trump)) {
            $slaId = $trump;
        } elseif ($this->getDept() && $this->getDept()->getSLAId()) {
            $slaId = $this->getDept()->getSLAId();
        } elseif ($this->getTopic() && $this->getTopic()->getSLAId()) {
            $slaId = $this->getTopic()->getSLAId();
        } else {
            $slaId = $cfg->getDefaultSLAId();
        }

        return ($slaId && $this->setSLAId($slaId)) ? $slaId : false;
    }

    //Set team ID...assign/unassign/release (id can be 0)
    function setTeamId($teamId) {
        if (!is_numeric($teamId))
            return false;

        $this->team = Team::lookup($teamId);
        return $this->save();
    }

    // Ticket Status helper.
    function setStatus($status, $comments='', &$errors=array(), $set_closing_agent=true, $force_close=false) {
        global $cfg, $thisstaff;

        if ($thisstaff && !($role=$this->getRole($thisstaff)))
            return false;

        if ((!$status instanceof TicketStatus)
                && !($status = TicketStatus::lookup($status)))
            return false;

        // Double check permissions (when changing status). Qué permiso pide
        // cada estado lo dice `ModelRules`, que es donde se puede probar.
        if ($role && $this->getStatusId()) {
            switch (ModelRules::statusPermission($status->getState())) {
            case 'close':
                if (!($role->hasPerm(Ticket::PERM_CLOSE)))
                    return false;
                break;
            case 'delete':
                // XXX: intercept deleted status and do hard delete TODO: soft deletes
                if ($role->hasPerm(Ticket::PERM_DELETE))
                    return $this->delete($comments);
                // Agent doesn't have permission to delete  tickets
                return false;
            }
        }

        $hadStatus = $this->getStatusId();
        if ($this->getStatusId() == $status->getId())
            return true;

        // Perform checks on the *new* status, _before_ the status changes
        $ecb = $refer = null;
        switch ($status->getState()) {
            case 'closed':
                // Check if ticket is closeable
                $closeable = $force_close ? true : $this->isCloseable();
                if ($closeable !== true)
                    $errors['err'] = $closeable ?: sprintf(__('%s cannot be closed'), __('This ticket'));

                if ($errors)
                    return false;

                $refer = $this->staff ?: $thisstaff;
                $this->closed = $this->lastupdate = SqlFunction::NOW();
                if ($thisstaff && $set_closing_agent)
                    $this->staff = $thisstaff;
                // Clear overdue flags & due dates
                $this->clearOverdue(false);

                $ecb = function($t) use ($status) {
                    $t->logEvent('closed', array('status' => array($status->getId(), $status->getName())), null, 'closed');
                    $t->deleteDrafts();
                };
                break;
            case 'open':
                if ($this->isClosed() && $this->isReopenable()) {
                    // Auto-assign to closing staff or the last respondent if the
                    // agent is available and has access. Otherwise, put the ticket back
                    // to unassigned pool.
                    $dept = $this->getDept();
                    $staff = $this->getStaff() ?: $this->getLastRespondent();
                    // Se reasigna sólo si el departamento lo permite y el
                    // agente existe, está disponible y tiene acceso; si no, el
                    // ticket vuelve al montón.
                    $this->setStaffId(ModelRules::reopenAssignee(
                        !$dept->disableReopenAutoAssign(),
                        $staff ? $staff->getId() : 0,
                        // Is agent on vacation ?
                        $staff && $staff->isAvailable(),
                        // Does the agent have access to dept?
                        $staff && $staff->canAccessDept($dept)));
                }

                if ($this->isClosed()) {
                    $this->closed = null;
                    $this->lastupdate = $this->reopened = SqlFunction::NOW();
                    $ecb = function ($t) {
                        $t->logEvent('reopened', false, null, 'closed');
                        // Set new sla duedate if any
                        $t->updateEstDueDate();
                    };
                }

                // If the ticket is not open then clear answered flag
                if (!$this->isOpen())
                    $this->isanswered = 0;
                break;
            default:
                return false;

        }

        $this->status = $status;
        if (!$this->save(true))
            return false;

        // Refer thread to previously assigned or closing agent
        if ($refer && $cfg->autoReferTicketsOnClose())
            $this->getThread()->refer($refer);

        // Nota con el motivo del cambio y evento en el historial. Va en su
        // propio método porque el orden importa: primero la nota (que puede
        // avisar al usuario) y después el evento.
        $this->logStatusChange($status, $hadStatus, $comments, $ecb);

        return true;
    }

    /**
     * Deja constancia del cambio de estado.
     *
     * La nota sólo se escribe si ya había estado —al abrir el ticket el estado
     * inicial no se anota— y el evento se delega en el cierre que armó
     * `setStatus()` para el estado nuevo (`$ecb`), salvo que no haya ninguno,
     * en cuyo caso se registra un «editado» genérico.
     *
     * @param TicketStatus $status    estado nuevo
     * @param bool         $hadStatus si el ticket ya tenía estado
     * @param string       $comments  comentarios del agente
     * @param callable     $ecb       cierre que registra el evento del estado
     */
    private function logStatusChange($status, $hadStatus, $comments, $ecb) {
        global $thisstaff;

        // Log status change b4 reload — if currently has a status. (On new
        // ticket, the ticket is opened and thereafter the status is set to
        // the requested status).
        if ($hadStatus) {
            $alert = false;
            if ($comments = ThreadEntryBody::clean($comments)) {
                // Send out alerts if comments are included
                $alert = true;
                $this->logNote(__('Status Changed'), $comments, $thisstaff, $alert);
            }
        }
        // Log events via callback
        if ($ecb)
            $ecb($this);
        elseif ($hadStatus)
            // Don't log the initial status change
            $this->logEvent('edited', array('status' => $status->getId()));
    }

    function setState($state, $alerts=false) {
        switch (strtolower($state)) {
        case 'open':
            return $this->setStatus('open');
        case 'closed':
            return $this->setStatus('closed');
        case 'answered':
            return $this->setAnsweredState(1);
        case 'unanswered':
            return $this->setAnsweredState(0);
        case 'overdue':
            return $this->markOverdue();
        case 'notdue':
            return $this->clearOverdue();
        case 'unassined':
            return $this->unassign();
        }
        // FIXME: Throw and excception and add test cases
        return false;
    }

    function setAnsweredState($isanswered) {
        $this->isanswered = $isanswered;
        return $this->save();
    }

    function reopen() {
        global $cfg;

        if (!$this->isClosed())
            return false;

        // Set status to open based on current closed status settings
        // If the closed status doesn't have configured "reopen" status then use the
        // the default ticket status.
        if (!($status=$this->getStatus()->getReopenStatus()))
            $status = $cfg->getDefaultTicketStatusId();

        return $status ? $this->setStatus($status) : false;
    }

    
    function onOpenLimit($sendNotice=true) {
        global $ost, $cfg;

        //Log the limit notice as a warning for admin.
        $msg=sprintf(_S('Maximum open tickets (%1$d) reached for %2$s'),
            $cfg->getMaxOpenTickets(), $this->getEmail());
        $ost->logWarning(sprintf(_S('Maximum Open Tickets Limit (%s)'),$this->getEmail()),
            $msg);

        if (!$sendNotice || !$cfg->sendOverLimitNotice())
            return true;

        //Send notice to user.
        if (($dept = $this->getDept())
            && ($tpl=$dept->getTemplate())
            && ($msg=$tpl->getOverlimitMsgTemplate())
            && ($email=$dept->getAutoRespEmail())
        ) {
            $msg = $this->replaceVars(
                $msg->asArray(),
                array('signature' => ($dept && $dept->isPublic())?$dept->getSignature():'')
            );

            $email->sendAutoReply($this->getOwner(), $msg['subj'], $msg['body']);
        }

        $user = $this->getOwner();

        // Alert admin...this might be spammy (no option to disable)...but it is helpful..I think.
        $alert=sprintf(__('Maximum open tickets reached for %s.'), $this->getEmail())."\n"
              .sprintf(__('Open tickets: %d'), $user->getNumOpenTickets())."\n"
              .sprintf(__('Max allowed: %d'), $cfg->getMaxOpenTickets())
              ."\n\n".__("Notice sent to the user.");

        $ost->alertAdmin(__('Overlimit Notice'), $alert);

        return true;
    }

    function onResponse($response, $options=array()) {
        $this->isanswered = 1;
        $this->save();

        $vars = array_merge($options,
            array(
                'activity' => _S('New Response'),
                'threadentry' => $response
            )
        );
        $this->onActivity($vars);
    }

    
    
    
    
   
    // TemplateVariable interface
    function asVar() {
       return $this->getNumber();
    }

    
    
    
    static function supportsCustomData() {
        return true;
    }

    //Replace base variables.
    function replaceVars($input, $vars = array()) {
        global $ost;

        $vars = array_merge($vars, array('ticket' => $this));
        return $ost->replaceTemplateVariables($input, $vars);
    }

    function markUnAnswered() {
        return (!$this->isAnswered() || $this->setAnsweredState(0));
    }

    function markAnswered() {
        return ($this->isAnswered() || $this->setAnsweredState(1));
    }

    function markOverdue($whine=true) {
        global $cfg;

        // Only open tickets can be marked overdue
        if (!$this->isOpen())
            return false;

        if ($this->isOverdue())
            return true;

        $this->isoverdue = 1;
        if (!$this->save())
            return false;

        $this->logEvent('overdue');
        $this->onOverdue($whine);

        return true;
    }

    function clearOverdue($save=true) {

        //NOTE: Previously logged overdue event is NOT annuled.
        if ($this->isOverdue())
            $this->isoverdue = 0;

        // clear due date if it's in the past
        if ($this->getDueDate() && Misc::db2gmtime($this->getDueDate()) <= Misc::gmtime())
            $this->duedate = null;

        // Clear SLA if est. due date is in the past
        if ($this->getSLADueDate() && Misc::db2gmtime($this->getSLADueDate()) <= Misc::gmtime())
            $this->est_duedate = null;

        return $save ? $this->save() : true;
    }

    function unlinkChild($parent) {
        $this->setPid(NULL);
        $this->setSort(1);
        $this->setFlag(Ticket::FLAG_LINKED, false);
        $this->save();
        $this->logEvent('unlinked', array('ticket' => sprintf('Ticket #%s', $parent->getNumber()), 'id' => $parent->getId()));
        $parent->logEvent('unlinked', array('ticket' => sprintf('Ticket #%s', $this->getNumber()), 'id' => $this->getId()));
    }

    function unlink() {
        $pid = $this->isChild() ? $this->getPid() : $this->getId();
        $parent = $this->isParent() ? $this : (Ticket::lookup($pid));
        $child = $this->isChild() ? $this : '';
        $children = $this->getChildren();
        $count = count($children);

        if ($children) {
            foreach ($children as $child) {
                $child = Ticket::lookup($child[0]);
                $child->unlinkChild($parent);
                $count--;
            }
        } elseif ($child)
            $child->unlinkChild($parent);

        if ($this->isParent() && $count == 0) {
            $parent->setFlag(Ticket::FLAG_LINKED, false);
            $parent->setFlag(Ticket::FLAG_PARENT, false);
            $parent->save();
        }

        return true;
    }

    static function manageMerge($tickets) {
        global $thisstaff;

        $permission = ($tickets['title'] && $tickets['title'] == 'link') ? (Ticket::PERM_LINK) : (Ticket::PERM_MERGE);
        $eventName = ($tickets['title'] && $tickets['title'] == 'link') ? 'linked' : 'merged';
        //see if any tickets should be unlinked
        if ($tickets['dtids']) {
            foreach($tickets['dtids'] as $key => $value) {
                if (is_numeric($key) && $ticket = Ticket::lookup($value))
                    $ticket->unlink();
            }
            return true;
        } elseif ($tickets['tids']) { //see if any tickets should be merged
            $ticketObjects = array();
            foreach($tickets['tids'] as $key => $value) {
                if ($ticket = Ticket::lookupByNumber($value)) {
                    $ticketObjects[] = $ticket;
                    if (!$ticket->checkStaffPerm($thisstaff, $permission) && !$ticket->getThread()->isReferred())
                       return false;

                    if ($key == 0)
                        $parent = $ticket;
                    //changing from link to merge
                    if (($ticket->isParent() || $ticket->isChild()) &&
                         $ticket->getMergeType() == 'visual' && $tickets['combine'] != 2 ||
                        ($tickets['combine'] == 2 && !$parent->isParent() && $parent->isChild())) { //changing link parent
                            $ticket->unlink();
                            $changeParent = true;
                    }

                    if ($ticket->getMergeType() == 'visual') {
                        $ticket->setSort($key);
                        $ticket->save();
                    }

                    if ($parent && $parent->getId() != $ticket->getId()) {
                        if (($changeParent) || ($parent->isParent() && $ticket->getMergeType() == 'visual' && !$ticket->isChild()) || //adding to link/merge
                           (!$parent->isParent() && !$ticket->isChild())) { //creating fresh link/merge
                               $parent->logEvent($eventName, array('ticket' => sprintf('Ticket #%s', $ticket->getNumber()),  'id' => $ticket->getId()));
                               $ticket->logEvent($eventName, array('ticket' => sprintf('Ticket #%s', $parent->getNumber()),  'id' => $parent->getId()));
                               if ($ticket->getPid() != $parent->getId())
                                   $ticket->setPid($parent->getId());
                               $parent->setMergeType($tickets['combine'], true);
                               $ticket->setMergeType($tickets['combine']);

                               //referrals for merged tickets
                               if ($parent->getDeptId() != ($ticketDeptId = $ticket->getDeptId()) && $tickets['combine'] != 2) {
                                   $refDept = $ticket->getDept();
                                   $parent->getThread()->refer($refDept);
                                   $evd = array('dept' => $ticketDeptId);
                                   $parent->logEvent('referred', $evd);
                               }
                        }
                    //switch between combine and separate
                    } elseif ($parent->isParent() && $ticket->getMergeType() != 'visual' && $parent->getId() != $ticket->getId()) {
                        $ticket->setMergeType($tickets['combine']);
                    } elseif ($parent->isParent() && $ticket->getMergeType() != 'visual' && $parent->getId() == $ticket->getId())
                        $parent->setMergeType($tickets['combine'], true);
                }
            }
        }
        return $ticketObjects;
    }

    static function merge($tickets) {
        $options = $tickets;
        if (!$tickets = self::manageMerge($tickets))
            return false;
        if (is_bool($tickets))
            return true;

        $children = array();
        foreach ($tickets as $ticket) {
            if ($ticket->isParent())
                $parent = $ticket;
            else
                $children[] = $ticket;
        }

        if ($parent && $parent->getMergeType() != 'visual') {
            $errors = array();
            foreach ($children as $child) {
                if ($options['participants'] == 'all' && $collabs = $child->getCollaborators()) {
                    foreach ($collabs as $collab) {
                        $collab = $collab->getUser();
                        if ($collab->getId() != $parent->getOwnerId())
                            $parent->addCollaborator($collab, array(), $errors);
                    }
                }
                $cUser = $child->getUser();
                if ($cUser->getId() != $parent->getOwnerId())
                    $parent->addCollaborator($cUser, array(), $errors);
                $parentThread = $parent->getThread();

                $deletedChild = Thread::objects()
                    ->filter(array('extra__contains'=>'"ticket_id":'.$child->getId()))
                    ->values_flat('id', 'extra')
                    ->first();
                if ($deletedChild) {
                    $extraThread = Thread::lookup($deletedChild[0]);
                    $extraThread->setExtra($parentThread, array('extra' => $deletedChild[1], 'threadId' => $extraThread->getId()));
                }

                if ($child->getThread())
                    $child->getThread()->setExtra($parentThread);

                $child->setMergeType($options['combine']);
                $child->setStatus(intval($options['childStatusId']), false, $errors, true, true); //force close status for children

                if ($options['parentStatusId'])
                    $parent->setStatus(intval($options['parentStatusId']));

                if ($options['delete-child'] || $options['move-tasks']) {
                    if ($tasks = Task::objects()
                        ->filter(array('object_id' => $child->getId()))
                        ->values_flat('id')) {
                        foreach ($tasks as $key => $tid) {
                            $task = Task::lookup($tid[0]);
                            $task->object_id = $parent->getId();
                            $task->save();
                        }
                    }
                }

                if ($options['delete-child'])
                     $child->delete();
            }
            return $parent;
        }
        return false;
    }

    function getRelatedTickets() {
        return sprintf('<tr>
            <td width="8px">&nbsp;</td>
            <td>
                <a class="Icon strtolower(%s) Ticket preview"
                   data-preview="#tickets/%d/preview"
                   href="tickets.php?id=%d">%s</a>
            </td>
            <td>%s</td>
            <td>%s</td>
            <td>%s</td>
            <td>%s</td>
        </tr>',
        strtolower($this->getSource()), $this->getId(), $this->getId(), $this->getNumber(), $this->getSubject(),
            $this->getDeptName(), $this->getAssignee(), Format::datetime($this->getCreateDate()));
    }

    function hasReferral($object, $type) {
        if (($referral=$this->getThread()->getReferral($object->getId(), $type)))
            return $referral;

        return false;
    }

    //Dept Transfer...with alert.. done by staff
    function transfer(TransferForm $form, &$errors, $alert=true) {
        global $thisstaff, $cfg;

        // Check if staff can do the transfer
        if (!$this->checkStaffPerm($thisstaff, Ticket::PERM_TRANSFER))
            return false;

        $cdept = $this->getDept(); // Current department
        $dept = $form->getDept(); // Target department
        if (!$dept || !($dept instanceof Dept))
            $errors['dept'] = __('Department selection required');
        elseif ($dept->getid() == $this->getDeptId())
            $errors['dept'] = sprintf(
                    __('%s already in the department'), __('Ticket'));
        else {
            $this->dept_id = $dept->getId();

            // Make sure the new department allows assignment to the
            // currently assigned agent (if any)
            if ($this->isAssigned()
                && ($staff=$this->getStaff())
                && $dept->assignMembersOnly()
                && !$dept->isMember($staff)
            ) {
                $this->staff_id = 0;
            }
        }

        if ($errors || !$this->save(true))
            return false;

        // Reopen ticket if closed
        if ($this->isClosed())
            $this->reopen();

        // Set SLA of the new department
        if (!$this->getSLAId() || $this->getSLA()->isTransient())
            if (($slaId=$this->getDept()->getSLAId()))
                $this->selectSLAId($slaId);

        // Log transfer event
        $this->logEvent('transferred', array('dept' => $dept->getName()));

        if (($referral=$this->hasReferral($dept,ObjectModel::OBJECT_TYPE_DEPT)))
            $referral->delete();

        // Post internal note if any
        $note = null;
        $comments = $form->getField('comments')->getClean();
        if ($comments) {
            $title = sprintf(__('%1$s transferred from %2$s to %3$s'),
                    __('Ticket'),
                   $cdept->getName(),
                    $dept->getName());

            $_errors = array();
            $note = $this->postNote(
                    array('note' => $comments, 'title' => $title),
                    $_errors, $thisstaff, false);
        }

        if ($form->refer() && $cdept)
            $this->getThread()->refer($cdept);

        //Send out alerts if enabled AND requested
        if (!$alert || !$cfg->alertONTransfer() || !$dept->getNumMembersForAlerts())
            return true; //no alerts!!

         if (($email = $dept->getAlertEmail())
             && ($tpl = $dept->getTemplate())
             && ($msg=$tpl->getTransferAlertMsgTemplate())
         ) {
            $msg = $this->replaceVars($msg->asArray(),
                array('comments' => $note ?: '', 'staff' => $thisstaff));
            // Recipients
            $recipients = array();
            // Assigned staff or team... if any
            if($this->isAssigned() && $cfg->alertAssignedONTransfer()) {
                if($this->getStaffId())
                    $recipients[] = $this->getStaff();
                elseif ($this->getTeamId()
                    && ($team=$this->getTeam())
                    && ($members=$team->getMembersForAlerts())
                ) {
                    $recipients = array_merge($recipients, $members);
                }
            }
            elseif ($cfg->alertDeptMembersONTransfer() && !$this->isAssigned()) {
                // Only alerts dept members if the ticket is NOT assigned.
                foreach ($dept->getMembersForAlerts() as $M)
                    $recipients[] = $M;
            }

            // Always alert dept manager??
            if ($cfg->alertDeptManagerONTransfer()
                && $dept
                && ($manager=$dept->getManager())
            ) {
                $recipients[] = $manager;
            }
            $sentlist = $options = array();
            if ($note) {
                $options += array('thread'=>$note);
            }
            foreach ($recipients as $k=>$staff) {
                if (!is_object($staff)
                    || !$staff->isAvailable()
                    || in_array($staff->getEmail(), $sentlist)
                ) {
                    continue;
                }
                $alert = $this->replaceVars($msg, array('recipient' => $staff));
                $email->sendAlert($staff, $alert['subj'], $alert['body'], null, $options);
                $sentlist[] = $staff->getEmail();
            }
         }

         return true;
    }

    function claim(ClaimForm $form, &$errors) {
        global $thisstaff;

        $dept = $this->getDept();
        $assignee = $form->getAssignee();
        if (!($assignee instanceof Staff)
                || !$thisstaff
                || $thisstaff->getId() != $assignee->getId()) {
            $errors['err'] = __('Unknown assignee');
        } elseif (!$assignee->isAvailable()) {
            $errors['err'] = __('Agent is unavailable for assignment');
        } elseif (!$dept->canAssign($assignee)) {
            $errors['err'] = __('Permission denied');
        }

        if ($errors)
            return false;

        return $this->assignToStaff($assignee, $form->getComments(), false);
    }

    function assignToStaff($staff, $note, $alert=true, $user=null) {

        if(!is_object($staff) && !($staff = Staff::lookup($staff)))
            return false;

        if (!$staff->isAvailable() || !$this->setStaffId($staff->getId()))
            return false;

        $this->onAssign($staff, $note, $alert);

        global $thisstaff;
        $data = array();
        if ($thisstaff && $staff->getId() == $thisstaff->getId())
            $data['claim'] = true;
        else
            $data['staff'] = $staff->getId();

        $this->logEvent('assigned', $data, $user);

        $key = $data['claim'] ? 'claim' : 'auto';
        $type = array('type' => 'assigned', $key => true);
        Signal::send('object.edited', $this, $type);

        if (($referral=$this->hasReferral($staff,ObjectModel::OBJECT_TYPE_STAFF)))
            $referral->delete();

        return true;
    }

    function assignToTeam($team, $note, $alert=true, $user=null) {

        if(!is_object($team) && !($team = Team::lookup($team)))
            return false;

        if (!$team->isActive() || !$this->setTeamId($team->getId()))
            return false;

        //Clear - staff if it's a closed ticket
        //  staff_id is overloaded -> assigned to & closed by.
        if ($this->isClosed())
            $this->setStaffId(0);

        $this->onAssign($team, $note, $alert);
        $this->logEvent('assigned', array('team' => $team->getId()), $user);

        if (($referral=$this->hasReferral($team,ObjectModel::OBJECT_TYPE_TEAM)))
            $referral->delete();

        return true;
    }

    function assign(AssignmentForm $form, &$errors, $alert=true) {
        global $thisstaff;

        $evd = array();
        $audit = array();
        $refer = null;
        $dept = $this->getDept();
        $assignee = $form->getAssignee();
        if ($assignee instanceof Staff) {
            if ($this->getStaffId() == $assignee->getId()) {
                $errors['assignee'] = sprintf(__('%s already assigned to %s'),
                        __('Ticket'),
                        __('the agent')
                        );
            } elseif (!$assignee->isAvailable()) {
                $errors['assignee'] = __('Agent is unavailable for assignment');
            } elseif (!$dept->canAssign($assignee)) {
                $errors['err'] = __('Permission denied');
            } else {
                $refer = $this->staff ?: null;
                $this->staff_id = $assignee->getId();
                if ($thisstaff && $thisstaff->getId() == $assignee->getId()) {
                    $alert = false;
                    $evd['claim'] = true;
                    $audit = array('staff' => $assignee->getName()->name,'claim' => true);
                } else {
                    $evd['staff'] = array($assignee->getId(), (string) $assignee->getName()->getOriginal());
                    $audit = array('staff' => $assignee->getName()->name);
                }

                if (($referral=$this->hasReferral($assignee,ObjectModel::OBJECT_TYPE_STAFF)))
                    $referral->delete();
            }
        } elseif ($assignee instanceof Team) {
            if ($this->getTeamId() == $assignee->getId()) {
                $errors['assignee'] = sprintf(__('%s already assigned to %s'),
                        __('Ticket'),
                        __('the team')
                        );
            } elseif (!$dept->canAssign($assignee)) {
                $errors['err'] = __('Permission denied');
            } else {
                $refer = $this->team ?: null;
                $this->team_id = $assignee->getId();
                $evd = array('team' => $assignee->getId());
                $audit = array('team' => $assignee->getName());
                if (($referral=$this->hasReferral($assignee,ObjectModel::OBJECT_TYPE_TEAM)))
                    $referral->delete();
            }
        } else {
            $errors['assignee'] = __('Unknown assignee');
        }

        if ($errors || !$this->save(true))
            return false;

        $this->logEvent('assigned', $evd);

        $type = array('type' => 'assigned');
        $type += $audit;
        Signal::send('object.edited', $this, $type);

        $this->onAssign($assignee, $form->getComments(), $alert);

        if ($refer && $form->refer())
            $this->getThread()->refer($refer);

        return true;
    }

    // Unassign primary assignee
    function unassign() {
        // We can't release what is not assigned buddy!
        if (!$this->isAssigned())
            return true;

        // We can only unassigned OPEN tickets.
        if ($this->isClosed())
            return false;

        // Unassign staff (if any)
        if ($this->getStaffId() && !$this->setStaffId(0))
            return false;

        // Unassign team (if any)
        if ($this->getTeamId() && !$this->setTeamId(0))
            return false;

        return true;
    }

    function release(?array $info=array(), &$errors=null) {
        if (isset($info['sid']) && isset($info['tid']))
            return $this->unassign();
        elseif (isset($info['sid']) && $this->setStaffId(0))
            return true;
        elseif (isset($info['tid']) && $this->setTeamId(0))
            return true;

        return false;
    }

    function refer(ReferralForm $form, &$errors, $alert=true) {
        global $thisstaff;

        $evd = array();
        $audit = array();
        $referee = $form->getReferee();
        switch (true) {
        case $referee instanceof Staff:
            $dept = $this->getDept();
            if ($this->getStaffId() == $referee->getId()) {
                $errors['agent'] = sprintf(__('%s is assigned to %s'),
                        __('Ticket'),
                        __('the agent')
                        );
            } elseif(!$referee->isAvailable()) {
                $errors['agent'] = sprintf(__('Agent is unavailable for %s'),
                        __('referral'));
            } else {
                $evd['staff'] = array($referee->getId(), (string) $referee->getName()->getOriginal());
                $audit = array('staff' => $referee->getName()->name);
            }
            break;
        case $referee instanceof Team:
            if ($this->getTeamId() == $referee->getId()) {
                $errors['team'] = sprintf(__('%s is assigned to %s'),
                        __('Ticket'),
                        __('the team')
                        );
            } else {
                //TODO::
                $evd = array('team' => $referee->getId());
                $audit = array('team' => $referee->getName());
            }
            break;
        case $referee instanceof Dept:
            if ($this->getDeptId() == $referee->getId()) {
                $errors['dept'] = sprintf(__('%s is already in %s'),
                        __('Ticket'),
                        __('the department')
                        );
            } else {
                //TODO::
                $evd = array('dept' => $referee->getId());
                $audit = array('dept' => $referee->getName());
            }
            break;
        default:
            $errors['target'] = __('Unknown referral');
        }

        if (!$errors && !$this->getThread()->refer($referee))
            $errors['err'] = __('Unable to refer ticket');

        if ($errors)
            return false;

        $this->logEvent('referred', $evd);

        $type = array('type' => 'referred');
        $type += $audit;
        Signal::send('object.edited', $this, $type);

        return true;
    }

    function systemReferral($emails) {
        global $cfg;

        if (!$thread = $this->getThread())
            return;

        $eventEmails = array();
        $events = ThreadEvent::objects()
            ->filter(array('thread_id' => $thread->getId(),
                           'event__name' => 'transferred'));
        if ($events) {
            foreach ($events as $e) {
                $emailId = Dept::getEmailIdById($e->dept_id) ?: $cfg->getDefaultEmailId();
                if (!in_array($emailId, $eventEmails))
                    $eventEmails[] = $emailId;
            }
        }

        foreach ($emails as $id) {
            $refer = $eventEmails ? !in_array($id, $eventEmails) : true;
            if ($id != $this->email_id
                    && $refer
                    && ($email=Email::lookup($id))
                    && $this->getDeptId() != $email->getDeptId()
                    && ($dept=Dept::lookup($email->getDeptId()))
                    && $this->getThread()->refer($dept)
                    )
                $this->logEvent('referred',
                            array('dept' => $dept->getId()));
        }

    }

    //Change ownership
    function changeOwner($user) {
        global $thisstaff;

        if (!$user
            || ($user->getId() == $this->getOwnerId())
            || !($this->checkStaffPerm($thisstaff,
                Ticket::PERM_EDIT))
        ) {
            return false;
        }

        $this->user_id = $user->getId();
        if (!$this->save())
            return false;

        unset($this->user);
        $this->collaborators = null;
        $this->recipients = null;

        // Remove the new owner from list of collaborators
        $c = Collaborator::lookup(array(
            'user_id' => $user->getId(),
            'thread_id' => $this->getThreadId()
        ));
        if ($c)
            $c->delete();

        $this->logEvent('edited', array('owner' => $user->getId(), 'fields' => array('Ticket Owner' => $user->getName()->name)));

        return true;
    }

    
    
    
    //Activity log - saved as internal notes WHEN enabled!!
    function logActivity($title, $note) {
        return $this->logNote($title, $note, 'SYSTEM', false);
    }

    // History log -- used for statistics generation (pretty reports)
    function logEvent($state, $data=null, $user=null, $annul=null) {
        switch ($state) {
            case 'collab':
            case 'transferred':
                $type = $data;
                $type['type'] = $state;
                break;
            case 'edited':
                $type = array('type' => $state, 'fields' => $data['fields'] ? $data['fields'] : $data);
                break;
            case 'assigned':
            case 'referred':
                break;
            default:
                $type = array('type' => $state);
                break;
        }
        if ($type)
            Signal::send('object.created', $this, $type);
        if ($this->getThread())
            $this->getThread()->getEvents()->log($this, $state, $data, $user, $annul);
    }

    //Insert Internal Notes
    function logNote($title, $note, $poster='SYSTEM', $alert=true) {
        // Unless specified otherwise, assume HTML
        if ($note && is_string($note))
            $note = new HtmlThreadEntryBody($note);

        $errors = array();
        return $this->postNote(
            array(
                'title' => $title,
                'note' => $note,
            ),
            $errors,
            $poster,
            $alert
        );
    }

    
    // Threadable interface
    function postThreadEntry($type, $vars, $options=array()) {
        $errors = array();
        switch ($type) {
        case 'M':
            return $this->postMessage($vars, $vars['origin']);
        case 'N':
            return $this->postNote($vars, $errors);
        case 'R':
            return $this->postReply($vars, $errors);
        }
    }

    // Print ticket... export the ticket thread as PDF.
    function pdfExport($psize='Letter', $notes=false, $events=false) {
        global $thisstaff;

        require_once(INCLUDE_DIR.'class.pdf.php');
        if (!is_string($psize)) {
            if ($_SESSION['PAPER_SIZE'])
                $psize = $_SESSION['PAPER_SIZE'];
            elseif (!$thisstaff || !($psize = $thisstaff->getDefaultPaperSize()))
                $psize = 'Letter';
        }

        $pdf = new Ticket2PDF($this, $psize, $notes, $events);
        $name = 'Ticket-'.$this->getNumber().'.pdf';
        Http::download($name, 'application/pdf', $pdf->output($name, 'S'));
        //Remember what the user selected - for autoselect on the next print.
        $_SESSION['PAPER_SIZE'] = $psize;
        exit;
    }

    function zipExport($notes=true, $tasks=false) {
        $exporter = new TicketZipExporter($this);
        $exporter->download(['notes'=>$notes, 'tasks'=>$tasks]);
        exit;
    }

    
    function deleteDrafts() {
        Draft::deleteForNamespace('ticket.%.' . $this->getId());
    }

    function save($refetch=false) {
        if ($this->dirty) {
            $this->updated = SqlFunction::NOW();
            if (isset($this->dirty['status_id']) && PHP_SAPI !== 'cli')
                // Refetch the queue counts
                SavedQueue::clearCounts();
        }
        return parent::save($this->dirty || $refetch);
    }

    
   
   /*============== Static functions. Use Ticket::function(params); =============nolint*/
    static function getIdByNumber($number, $email=null, $ticket=false) {

        if (!$number)
            return 0;

        $query = static::objects()
            ->filter(array('number' => $number));

        if ($email)
            $query->filter(Q::any(array(
                'user__emails__address' => $email,
                'thread__collaborators__user__emails__address' => $email
            )));


        if (!$ticket) {
            $query = $query->values_flat('ticket_id');
            if ($row = $query->first())
                return $row[0];
        }
        else {
            return $query->first();
        }
    }

    static function lookupByNumber($number, $email=null) {
        return static::getIdByNumber($number, $email, true);
    }

    static function isTicketNumberUnique($number) {
        $num = static::objects()
            ->filter(array('number' => $number))
        ->count();

    return ($num === 0);
    }

    static function getChildTickets($pid) {
        return Ticket::objects()
                ->filter(array('ticket_pid'=>$pid))
                ->values_flat('ticket_id', 'number', 'ticket_pid', 'sort', 'thread__id', 'user_id', 'cdata__subject', 'user__name', 'flags')
                ->annotate(array('tasks' => SqlAggregate::COUNT('tasks__id', true),
                                 'collaborators' => SqlAggregate::COUNT('thread__collaborators__id'),
                                 'entries' => SqlAggregate::COUNT('thread__entries__id'),))
                ->order_by('sort');
    }

    /* Quick client's tickets stats
       @email - valid email.
     */
    function getUserStats($user) {
        if(!$user || !($user instanceof EndUser))
            return null;

        // El SQL original unía la tabla consigo misma y filtraba por
        // `ticket.status`, columna que desapareció en 1.10 (hoy el estado es
        // `status_id` con `ticket_status.state`). Dos conteos equivalentes.
        $stats = array('open' => 0, 'closed' => 0);
        foreach (array_keys($stats) as $state)
            $stats[$state] = Ticket::objects()
                ->filter(array(
                    'user_id' => $user->getId(),
                    'status__state' => $state,
                ))
                ->count();

        return $stats;
    }

    
    
    
    static function checkOverdue() {
        $overdue = static::objects()
            ->filter(array(
                'isoverdue' => 0,
                'status__state' => 'open',
                Q::any(array(
                    Q::all(array(
                        'duedate__isnull' => true,
                        'est_duedate__isnull' => false,
                        'est_duedate__lt' => SqlFunction::NOW())
                        ),
                    Q::all(array(
                        'duedate__isnull' => false,
                        'duedate__lt' => SqlFunction::NOW())
                        )
                    ))
                ))
            ->limit(100);

        foreach ($overdue as $ticket)
            $ticket->markOverdue();

    }

    static function agentActions($agent, $options=array()) {
        if (!$agent)
            return;

        require STAFFINC_DIR.'templates/tickets-actions.tmpl.php';
    }

    static function getLink($id) {
        global $thisstaff;

        switch (true) {
        case ($thisstaff instanceof Staff):
            return ROOT_PATH . sprintf('scp/tickets.php?id=%s', $id);
        }
    }

    static function getPermissions() {
        return self::$perms;
    }

    static function getSources() {
        static $translated = false;
        if (!$translated) {
            foreach (static::$sources as $k=>$v)
                static::$sources[$k] = __($v);
        }

        return static::$sources;
    }

    // TODO: Create internal Form for internal fields
    static function duedateField($name, $default='', $hint='') {
        return DateTimeField::init(array(
            'id' => $name,
            'name' => $name,
            'default' => $default ?: false,
            'label' => __('Due Date'),
            'hint' => $hint,
            'configuration' => array(
                'min' => Misc::gmtime(),
                'time' => true,
                'gmt' => false,
                'future' => true,
                )
            ));
    }

    }
RolePermission::register(/* @trans */ 'Tickets', Ticket::getPermissions(), true);

class TicketCData extends VerySimpleModel {
    static $meta = array(
        'pk' => array('ticket_id'),
        'joins' => array(
            'ticket' => array(
                'constraint' => array('ticket_id' => 'Ticket.ticket_id'),
            ),
            ':priority' => array(
                'constraint' => array('priority' => 'Priority.priority_id'),
                'null' => true,
            ),
        ),
    );
}
TicketCData::$meta['table'] = TABLE_PREFIX . 'ticket__cdata';
