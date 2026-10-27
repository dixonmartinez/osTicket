<?php
/*********************************************************************
    class.ticket.assignment.php

    Mover un ticket de departamento, asignarlo, combinarlo con otro,
    derivarlo, cambiarle el estado y bloquearlo mientras alguien lo edita.

    Es un rasgo de Ticket y no una clase aparte: todas estas operaciones
    cambian el propio ticket y avisan con las se�ales del modelo. Se sacaron
    de include/class.ticket.php (frente 6 de docs/CODE_QUALITY.md) moviendo
    los metodos enteros, sin tocar su codigo.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

trait TicketAssignment {

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
}
