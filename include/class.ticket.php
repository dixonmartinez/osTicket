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
/* Mover, asignar y combinar tickets: ver class.ticket.assignment.php */
include_once(INCLUDE_DIR.'class.ticket.assignment.php');
/* Formularios y campos del ticket: ver class.ticket.forms.php */
include_once(INCLUDE_DIR.'class.ticket.forms.php');
/* Vencimientos y planes de servicio: ver class.ticket.sla.php */
include_once(INCLUDE_DIR.'class.ticket.sla.php');
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
    use TicketAssignment;
    use TicketForms;
    use TicketSla;






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


    //Set team ID...assign/unassign/release (id can be 0)
    function setTeamId($teamId) {
        if (!is_numeric($teamId))
            return false;

        $this->team = Team::lookup($teamId);
        return $this->save();
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
