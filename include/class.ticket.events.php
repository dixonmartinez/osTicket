<?php
/*********************************************************************
    class.ticket.events.php

    Los avisos que salen de un ticket: alta, respuesta, actividad, asignacion
    y vencimiento. Cada uno arma sus destinatarios y llama a `notify()`.

    Es un rasgo de Ticket y no una clase aparte porque son ganchos del modelo:
    usan `$this->getThread()`, `$this->getDept()` y el estado del propio ticket.
    Se sacaron de include/class.ticket.php (frente 6 de docs/CODE_QUALITY.md)
    moviendo los metodos enteros, sin tocar su codigo.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

trait TicketEvents {

function onNewTicket($message, $autorespond=true, $alertstaff=true) {
        global $cfg;

        //Log stuff here...

        if (!$autorespond && !$alertstaff)
            return true; //No alerts to send.

        /* ------ SEND OUT NEW TICKET AUTORESP && ALERTS ----------*/

        if(!$cfg
            || !($dept=$this->getDept())
            || !($tpl = $dept->getTemplate())
            || !($email=$dept->getAutoRespEmail())
        ) {
            return false;  //bail out...missing stuff.
        }

        $options = array();
        if (($message instanceof ThreadEntry)
                && $message->getEmailMessageId()) {
            $options += array(
                'inreplyto'=>$message->getEmailMessageId(),
                'references'=>$message->getEmailReferences(),
                'thread'=>$message
            );
        }
        else {
            $options += array(
                'thread' => $this->getThread(),
            );
        }

        //Send auto response - if enabled.
        if ($autorespond
            && $cfg->autoRespONNewTicket()
            && $dept->autoRespONNewTicket()
            && ($msg = $tpl->getAutoRespMsgTemplate())
        ) {
            $msg = $this->replaceVars(
                $msg->asArray(),
                array('message' => $message,
                      'recipient' => $this->getOwner(),
                      'signature' => ($dept && $dept->isPublic())?$dept->getSignature():''
                )
            );
            $email->sendAutoReply($this->getOwner(), $msg['subj'], $msg['body'],
                null, $options);
        }

        // Send alert to out sleepy & idle staff.
        if ($alertstaff
            && $cfg->alertONNewTicket()
            && ($email=$dept->getAlertEmail())
            && ($msg=$tpl->getNewTicketAlertMsgTemplate())
        ) {
            $msg = $this->replaceVars($msg->asArray(), array('message' => $message));
            $recipients = $sentlist = array();
            // Exclude the auto responding email just incase it's from staff member.
            if ($message instanceof ThreadEntry && $message->isAutoReply())
                $sentlist[] = $this->getEmail();

            if ($dept->getNumMembersForAlerts()) {
                // Only alerts dept members if the ticket is NOT assigned.
                $manager = $dept->getManager();
                if ($cfg->alertDeptMembersONNewTicket() && !$this->isAssigned()
                    && ($members = $dept->getMembersForAlerts())
                ) {
                    foreach ($members as $M)
                        if ($M != $manager)
                            $recipients[] = $M;
                }

                if ($cfg->alertDeptManagerONNewTicket() && $manager) {
                    $recipients[] = $manager;
                }

                // Account manager
                if ($cfg->alertAcctManagerONNewTicket()
                    && ($org = $this->getOwner()->getOrganization())
                    && ($acct_manager = $org->getAccountManager())
                ) {
                    if ($acct_manager instanceof Team)
                        $recipients = array_merge($recipients, $acct_manager->getMembersForAlerts());
                    else
                        $recipients[] = $acct_manager;
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

            // Alert admin ONLY if not already a staff??
            if ($cfg->alertAdminONNewTicket()
                    && !in_array($cfg->getAdminEmail(), $sentlist)
                    && ($dept->isGroupMembershipEnabled() != Dept::ALERTS_DISABLED)) {
                $options += array('utype'=>'A');
                $alert = $this->replaceVars($msg, array('recipient' => 'Admin'));
                $email->sendAlert($cfg->getAdminEmail(), $alert['subj'],
                        $alert['body'], null, $options);
            }

        }
        return true;
    }

function onMessage($message, $autorespond=true, $reopen=true) {
        global $cfg;

        $this->isanswered = 0;
        $this->lastupdate = SqlFunction::NOW();
        $this->save();


        // Reopen if closed AND reopenable
        // We're also checking autorespond flag because we don't want to
        // reopen closed tickets on auto-reply from end user. This is not to
        // confused with autorespond on new message setting
        if ($reopen && $this->isClosed() && $this->isReopenable())
            $this->reopen();

        if (!$autorespond)
            return;

        // Figure out the user
        if ($this->getOwnerId() == $message->getUserId())
            $user = new TicketOwner(
                    User::lookup($message->getUserId()), $this);
        else
            $user = Collaborator::lookup(array(
                    'user_id' => $message->getUserId(),
                    'thread_id' => $this->getThreadId()));

        /**********   double check auto-response  ************/
        if (!$user)
            $autorespond = false;
        elseif ((Email::getIdByEmail($user->getEmail())))
            $autorespond = false;
        elseif (($dept=$this->getDept()))
            $autorespond = $dept->autoRespONNewMessage();

        if (!$autorespond
            || !$cfg->autoRespONNewMessage()
            || !$message
        ) {
            return;  //no autoresp or alerts.
        }

        $dept = $this->getDept();
        $email = $dept->getAutoRespEmail();

        // If enabled...send confirmation to user. ( New Message AutoResponse)
        if ($email
            && ($tpl=$dept->getTemplate())
            && ($msg=$tpl->getNewMessageAutorepMsgTemplate())
        ) {
            $msg = $this->replaceVars($msg->asArray(),
                array(
                    'recipient' => $user,
                    'signature' => ($dept && $dept->isPublic())?$dept->getSignature():''
                )
            );
            $options = array('thread' => $message);
            if ($message->getEmailMessageId()) {
                $options += array(
                        'inreplyto' => $message->getEmailMessageId(),
                        'references' => $message->getEmailReferences()
                        );
            }

            $email->sendAutoReply($user, $msg['subj'], $msg['body'],
                null, $options);
        }
    }

function onActivity($vars, $alert=true) {
        global $cfg, $thisstaff;

        //TODO: do some shit
        if (!$alert // Check if alert is enabled
            || !$cfg->alertONNewActivity()
            || !($dept=$this->getDept())
            || !$dept->getNumMembersForAlerts()
            || !($email=$cfg->getAlertEmail())
            || !($tpl = $dept->getTemplate())
            || !($msg=$tpl->getNoteAlertMsgTemplate())
        ) {
            return;
        }

        // Alert recipients
        $recipients = array();

        //Last respondent.
        if ($cfg->alertLastRespondentONNewActivity())
            $recipients[] = $this->getLastRespondent();

        // Assigned staff / team
        if ($cfg->alertAssignedONNewActivity()) {
            if (isset($vars['assignee'])
                    && $vars['assignee'] instanceof Staff)
                 $recipients[] = $vars['assignee'];
            elseif ($this->isOpen() && ($assignee = $this->getStaff()))
                $recipients[] = $assignee;

            if ($team = $this->getTeam())
                $recipients = array_merge($recipients, $team->getMembersForAlerts());
        }

        // Dept manager
        if ($cfg->alertDeptManagerONNewActivity() && $dept && $dept->getManagerId())
            $recipients[] = $dept->getManager();

        $options = array();
        $staffId = $thisstaff ? $thisstaff->getId() : 0;
        if ($vars['threadentry'] && $vars['threadentry'] instanceof ThreadEntry) {
            $options = array('thread' => $vars['threadentry']);

            // Activity details
            if (!$vars['comments'])
                $vars['comments'] = $vars['threadentry'];

            // Staff doing the activity
            $staffId = $vars['threadentry']->getStaffId() ?: $staffId;
        }

        $msg = $this->replaceVars($msg->asArray(),
                array(
                    'note' => $vars['threadentry'], // For compatibility
                    'activity' => $vars['activity'],
                    'comments' => $vars['comments']));

        $isClosed = $this->isClosed();
        $sentlist=array();
        foreach ($recipients as $k=>$staff) {
            if (!is_object($staff)
                // Don't bother vacationing staff.
                || !$staff->isAvailable()
                // No need to alert the poster!
                || $staffId == $staff->getId()
                // No duplicates.
                || isset($sentlist[$staff->getEmail()])
                // Make sure staff has access to ticket
                || ($isClosed && !$this->checkStaffPerm($staff))
            ) {
                continue;
            }
            $alert = $this->replaceVars($msg, array('recipient' => $staff));
            $email->sendAlert($staff, $alert['subj'], $alert['body'], null, $options);
            $sentlist[$staff->getEmail()] = 1;
        }
    }

function onAssign($assignee, $comments, $alert=true) {
        global $cfg, $thisstaff;

        if ($this->isClosed())
            $this->reopen(); //Assigned tickets must be open - otherwise why assign?

        // Assignee must be an object of type Staff or Team
        if (!$assignee || !is_object($assignee))
            return false;

        $user_comments = (bool) $comments;
        $assigner = $thisstaff ?: _S('SYSTEM (Auto Assignment)');

        //Log an internal note - no alerts on the internal note.
        if ($user_comments) {
            if ($assignee instanceof Staff
                    && $thisstaff
                    // self assignment
                    && $assignee->getId() == $thisstaff->getId())
                $title = sprintf(_S('Ticket claimed by %s'),
                    $thisstaff->getName());
            else
                $title = sprintf(_S('Ticket Assigned to %s'),
                        $assignee->getName());

            $note = $this->logNote($title, $comments, $assigner, false);
        }
        $dept = $this->getDept();
        // See if we need to send alerts
        if (!$alert || !$cfg->alertONAssignment() || !$dept->getNumMembersForAlerts())
            return true; //No alerts!

        if (!$dept
            || !($tpl = $dept->getTemplate())
            || !($email = $dept->getAlertEmail())
        ) {
            return true;
        }

        // Recipients
        $recipients = array();
        if ($assignee instanceof Staff) {
            if ($cfg->alertStaffONAssignment())
                $recipients[] = $assignee;
        } elseif (($assignee instanceof Team) && $assignee->alertsEnabled()) {
            if ($cfg->alertTeamMembersONAssignment() && ($members=$assignee->getMembersForAlerts()))
                $recipients = array_merge($recipients, $members);
            elseif ($cfg->alertTeamLeadONAssignment() && ($lead=$assignee->getTeamLead()))
                $recipients[] = $lead;
        }

        // Get the message template
        if ($recipients
            && ($msg=$tpl->getAssignedAlertMsgTemplate())
        ) {
            $msg = $this->replaceVars($msg->asArray(),
                array('comments' => $comments ?: '',
                      'assignee' => $assignee,
                      'assigner' => $assigner
                )
            );
            // Send the alerts.
            $sentlist = array();
            $options = $note instanceof ThreadEntry
                ? array('thread'=>$note)
                : array();
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

function onOverdue($whine=true, $comments="") {
        global $cfg;

        if ($whine && ($sla = $this->getSLA()) && !$sla->alertOnOverdue())
            $whine = false;

        // Check if we need to send alerts.
        if (!$whine
            || !$cfg->alertONOverdueTicket()
            || !($dept = $this->getDept())
            || !$dept->getNumMembersForAlerts()
        ) {
            return true;
        }
        // Get the message template
        if (($tpl = $dept->getTemplate())
            && ($msg=$tpl->getOverdueAlertMsgTemplate())
            && ($email = $dept->getAlertEmail())
        ) {
            $msg = $this->replaceVars($msg->asArray(),
                array('comments' => $comments)
            );
            // Recipients
            $recipients = array();
            // Assigned staff or team... if any
            if ($this->isAssigned() && $cfg->alertAssignedONOverdueTicket()) {
                if ($this->getStaffId()) {
                    $recipients[]=$this->getStaff();
                }
                elseif ($this->getTeamId()
                    && ($team = $this->getTeam())
                    && ($members = $team->getMembersForAlerts())
                ) {
                    $recipients=array_merge($recipients, $members);
                }
            }
            elseif ($cfg->alertDeptMembersONOverdueTicket() && !$this->isAssigned()) {
                // Only alerts dept members if the ticket is NOT assigned.
                foreach ($dept->getMembersForAlerts() as $M)
                    $recipients[] = $M;
            }
            // Always alert dept manager??
            if ($cfg->alertDeptManagerONOverdueTicket()
                && $dept && ($manager=$dept->getManager())
            ) {
                $recipients[]= $manager;
            }
            $sentlist = array();
            foreach ($recipients as $k=>$staff) {
                if (!is_object($staff)
                    || !$staff->isAvailable()
                    || in_array($staff->getEmail(), $sentlist)
                ) {
                    continue;
                }
                $alert = $this->replaceVars($msg, array('recipient' => $staff));
                $email->sendAlert($staff, $alert['subj'], $alert['body'], null);
                $sentlist[] = $staff->getEmail();
            }
        }
        return true;
    }
}
