<?php
/*********************************************************************
    class.ticket.replies.php

    Responder a un ticket: mensaje al cliente, nota interna, respuesta
    predefinida y aviso a los colaboradores.

    Es un rasgo de Ticket y no una clase aparte porque cada respuesta pasa por
    `$this->getThread()`, el estado y los permisos del propio ticket. Lo que no
    necesita saber del ticket ya se sacó a TicketPost y ThreadEmailInfo en el
    frente 2. Los metodos se movieron enteros, sin tocar su codigo
    (frente 6 de docs/CODE_QUALITY.md).

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

trait TicketReplies {

//XXX: Ugly for now
    function updateCollaborators($vars, &$errors) {
        global $thisstaff;

        if (!$thisstaff) return;

        //Deletes
        if($vars['del'] && ($ids=array_filter($vars['del']))) {
            $collabs = array();
            foreach ($ids as $k => $cid) {
                if (($c=Collaborator::lookup($cid))
                        && $c->getTicketId() == $this->getId()
                        && $c->delete())
                     $collabs[] = (string) $c;
            }
            $this->logEvent('collab', array('del' => $collabs));
        }

        //statuses
        $cids = null;
        if($vars['cid'] && ($cids=array_filter($vars['cid']))) {
            $this->getThread()->collaborators->filter(array(
                'thread_id' => $this->getThreadId(),
                'id__in' => $cids
            ))->update(array(
                'updated' => SqlFunction::NOW(),
            ));
        }

        if ($cids) {
            $this->getThread()->collaborators->filter(array(
                'thread_id' => $this->getThreadId(),
                Q::not(array('id__in' => $cids))
            ))->update(array(
                'updated' => SqlFunction::NOW(),
            ));
        }

        unset($this->active_collaborators);
        $this->collaborators = null;

        return true;
    }

/*
     * Notify collaborators on response or new message
     *
     */
    function notifyCollaborators($entry, $vars = array()) {
        global $cfg;

        if (!$entry instanceof ThreadEntry
            || !($recipients=$this->getRecipients())
            || !($dept=$this->getDept())
            || !($tpl=$dept->getTemplate())
            || !($msg=$tpl->getActivityNoticeMsgTemplate())
            || !($email=$dept->getEmail())
        ) {
            return;
        }

        $poster = User::lookup($entry->user_id);
        $posterEmail = $poster->getEmail()->address;

        $vars = array_merge($vars, array(
            'message' => (string) $entry,
            'poster' => $poster ?: _S('A collaborator'),
            )
        );

        $msg = $this->replaceVars($msg->asArray(), $vars);

        $attachments = $cfg->emailAttachments()?$entry->getAttachments():array();
        $options = array('thread' => $entry);

        if ($vars['from_name'])
            $options += array('from_name' => $vars['from_name']);

        $skip = array();
        if ($entry instanceof MessageThreadEntry) {
          foreach ($entry->getAllEmailRecipients() as $R) {
                $skip[] = $R->mailbox.'@'.$R->host;
            }
        }

        foreach ($recipients as $key => $recipient) {
            $recipient = $recipient->getContact();

            if(get_class($recipient) == 'TicketOwner')
                $owner = $recipient;

            if ((get_class($recipient) == 'Collaborator' ? $recipient->getUserId() : $recipient->getId()) == $entry->user_id)
                unset($recipients[$key]);
         }

        if (!count($recipients))
            return true;

        //see if the ticket user is a recipient
        if ($owner->getEmail()->address != $poster->getEmail()->address && !in_array($owner->getEmail()->address, $skip))
          $owner_recip = $owner->getEmail()->address;

        //say dear collaborator if the ticket user is not a recipient
        if (!$owner_recip) {
            $nameFormats = array_keys(PersonsName::allFormats());
            $names = array();
            foreach ($nameFormats as $key => $value) {
              $names['recipient.name.' . $value] = __('Collaborator');
            }
            $names = array_merge($names, array('recipient' => $recipient));
            $cnotice = $this->replaceVars($msg, $names);
        }
        //otherwise address email to ticket user
        else
            $cnotice = $this->replaceVars($msg, array('recipient' => $owner));

        $email->send($recipients, $cnotice['subj'], $cnotice['body'], $attachments,
            $options);
    }

// Insert message from client
    function postMessage($vars, $origin='', $alerts=true) {
        global $cfg;

        // Origen e IP del mensaje: ver TicketPost::normalizeVars()
        $vars = TicketPost::normalizeVars($vars, $origin,
            $_SERVER['REMOTE_ADDR'] ?? null);

        //see if message should go to a parent ticket
        if ($this->isChild() && $this->getMergeType() != 'visual')
            $parent = self::lookup($this->getPid());

        $ticket = $parent ?: $this;
        $errors = array();
        if ($vars['userId'] != $ticket->user_id) {
            if ($vars['userId']) {
                $user = User::lookup($vars['userId']);
             } elseif ($vars['header']
                    && ($hdr= Mail_Parse::splitHeaders($vars['header'], true))
                    && $hdr['From']
                    && ($addr= Mail_Parse::parseAddressList($hdr['From']))) {
                $info = array(
                        'name' => $addr[0]->personal,
                        'email' => $addr[0]->mailbox.'@'.$addr[0]->host);
                if ($user=User::fromVars($info))
                    $vars['userId'] = $user->getId();
            }

            if ($user) {
                $v = array();
                $c = $ticket->getThread()->addCollaborator($user, $v,
                        $errors);
            }
       }

      // Get active recipients of the response
      // Initial Message from Tickets created by Agent
      if ($vars['reply-to'])
          $recipients = $ticket->getRecipients($vars['reply-to'], $vars['ccs']);
      // Messages from Web Portal
      elseif (strcasecmp($origin, 'email')) {
          // Se escribe a todos y se descarta al autor: ver TicketPost
          $recipients = TicketPost::withoutPoster($ticket->getRecipients('all'),
              $vars['userId']);
      }
      if ($recipients && $recipients instanceof MailingList)
          $vars['thread_entry_recipients'] = $recipients->getEmailAddresses();

        if (!($message = $ticket->getThread()->addMessage($vars, $errors)))
            return null;

        $ticket->setLastMessage($message);

        // Add email recipients as collaborators...
        if ($vars['recipients']
            && (strtolower($origin) != 'email' || ($cfg && $cfg->addCollabsViaEmail()))
            //Only add if we have a matched local address
            && $vars['to-email-id']
        ) {
            //New collaborators added by other collaborators are disable --
            // requires staff approval.
            $info = array(
                'isactive' => ($message->getUserId() == $ticket->getUserId())? 1: 0);
            $collabs = array();
            foreach ($vars['recipients'] as $recipient) {
                // Skip virtual delivered-to addresses
                if (strcasecmp($recipient['source'], 'delivered-to') === 0)
                    continue;

                if (($cuser=User::fromVars($recipient))) {
                  if (!$existing = Collaborator::getIdByUserId($cuser->getId(), $ticket->getThreadId())) {
                    $_errors = array();
                    if ($c=$ticket->addCollaborator($cuser, $info, $_errors, false)) {
                      $c->setCc($c->active);

                      // FIXME: This feels very unwise — should be a
                      // string indexed array for future
                      $collabs[$c->user_id] = array(
                          'name' => $c->getName()->getOriginal(),
                          'src' => $recipient['source'],
                      );
                    }
                  }

                }

            }
            // TODO: Can collaborators add others?
            if ($collabs) {
                $ticket->logEvent('collab', array('add' => $collabs), $message->user);
                $type = array('type' => 'collab', 'add' => $collabs);
                Signal::send('object.created', $ticket, $type);
            }
        }

        // Do not auto-respond to bounces and other auto-replies
        $autorespond = TicketPost::shouldAutoRespond($vars);
        $reopen = $autorespond; // Do not reopen bounces
        if ($autorespond && $message->isBounceOrAutoReply())
            $autorespond = $reopen= false;
        elseif ($autorespond && isset($vars['autorespond']))
            $autorespond = $vars['autorespond'];

        $ticket->onMessage($message, ($autorespond && $alerts), $reopen); //must be called b4 sending alerts to staff.

        if ($autorespond && $alerts
            && $cfg && $cfg->notifyCollabsONNewMessage()
            && strcasecmp($origin, 'email')) {
          //when user replies, this is where collabs notified
          $ticket->notifyCollaborators($message, array('signature' => ''));
        }

        if (!($alerts && $autorespond))
            return $message; //Our work is done...

        $dept = $ticket->getDept();
        $variables = array(
            'message' => $message,
            'poster' => ($vars['poster'] ? $vars['poster'] : $ticket->getName())
        );

        $options = array('thread'=>$message);
        // If enabled...send alert to staff (New Message Alert)
        if ($cfg->alertONNewMessage()
            && ($email = $dept->getAlertEmail())
            && ($tpl = $dept->getTemplate())
            && ($msg = $tpl->getNewMessageAlertMsgTemplate())
        ) {
            $msg = $ticket->replaceVars($msg->asArray(), $variables);
            // A quién le toca y quién puede recibirlo: ver
            // TicketPost::alertRecipients() y alertableRecipients()
            foreach (TicketPost::alertableRecipients(
                    TicketPost::alertRecipients($cfg, $ticket, $dept, $this)) as $staff) {
                $alert = $this->replaceVars($msg, array('recipient' => $staff));
                $email->sendAlert($staff, $alert['subj'], $alert['body'], null, $options);
            }
        }
        $type = array('type' => 'message', 'uid' => $vars['userId']);
        Signal::send('object.created', $this, $type);

        return $message;
    }

function postCannedReply($canned, $message, $alert=true) {
        global $ost, $cfg;

        if ((!is_object($canned) && !($canned=Canned::lookup($canned)))
            || !$canned->isEnabled()
        ) {
            return false;
        }
        $files = array();
        foreach ($canned->attachments->getAll() as $att) {
            $files[] = array('id' => $att->file_id, 'name' => $att->getName());
            $_SESSION[':cannedFiles'][$att->file_id] = $att->getName();
        }

        if ($cfg->isRichTextEnabled())
            $response = new HtmlThreadEntryBody(
                $this->replaceVars($canned->getHtml()));
        else
            $response = new TextThreadEntryBody(
                $this->replaceVars($canned->getPlainText()));

        $info = array('msgId' => $message instanceof ThreadEntry ? $message->getId() : 0,
                      'poster' => __('SYSTEM (Canned Reply)'),
                      'response' => $response,
                      'files' => $files
        );
        $errors = array();
        if (!($response=$this->postReply($info, $errors, false, false)))
            return null;

        $this->markUnAnswered();

        if (!$alert)
            return $response;

        $dept = $this->getDept();

        if (($email=$dept->getEmail())
            && ($tpl = $dept->getTemplate())
            && ($msg=$tpl->getAutoReplyMsgTemplate())
        ) {
            if ($dept && $dept->isPublic())
                $signature=$dept->getSignature();
            else
                $signature='';

            $msg = $this->replaceVars($msg->asArray(),
                array(
                    'response' => $response,
                    'signature' => $signature,
                    'recipient' => $this->getOwner(),
                )
            );
            $attachments = ($cfg->emailAttachments() && $files)
                ? $response->getAttachments() : array();

            $options = array('thread' => $response);
            if (($message instanceof ThreadEntry)
                    && $message->getUserId() == $this->getUserId()
                    && ($mid=$message->getEmailMessageId())) {
                $options += array(
                        'inreplyto' => $mid,
                        'references' => $message->getEmailReferences()
                        );
            }

            $email->sendAutoReply($this->getOwner(), $msg['subj'], $msg['body'], $attachments,
                $options);
        }
        return $response;
    }

/* public */
    function postReply($vars, &$errors, $alert=true, $claim=true) {
        global $thisstaff, $cfg;

        if (!$vars['poster'] && $thisstaff)
            $vars['poster'] = $thisstaff;

        if (!$vars['staffId'] && $thisstaff)
            $vars['staffId'] = $thisstaff->getId();

        if (!$vars['ip_address'] && $_SERVER['REMOTE_ADDR'])
            $vars['ip_address'] = $_SERVER['REMOTE_ADDR'];

        // clear db cache
        $this->getThread()->_collaborators = null;

        // Get active recipients of the response
        $recipients = $this->getRecipients($vars['reply-to'], $vars['ccs']);
        if ($recipients instanceof MailingList)
            $vars['thread_entry_recipients'] = $recipients->getEmailAddresses();

        if (!($response = $this->getThread()->addResponse($vars, $errors)))
            return null;

        $dept = $this->getDept();
        $assignee = $this->getStaff();
        // Set status if new is selected
        if ($vars['reply_status_id']
                && ($status = TicketStatus::lookup($vars['reply_status_id']))
                && $status->getId() != $this->getStatusId())
            $this->setStatus($status);

        // Claim on response bypasses the department assignment restrictions
        $claim = ($claim
                && $cfg->autoClaimTickets()
                && !$dept->disableAutoClaim());
        if ($claim && $thisstaff && $this->isOpen() && !$this->getStaffId()) {
            $this->setStaffId($thisstaff->getId()); //direct assignment;
        }

        $this->onResponse($response, array('assignee' => $assignee)); //do house cleaning..

        $this->lastrespondent = $response->staff;

        $type = array('type' => 'message');
        Signal::send('object.created', $this, $type);

        /* email the user??  - if disabled - then bail out */
        if (!$alert)
            return $response;

        //allow agent to send from different dept email
        if (!$vars['from_email_id']
                ||  !($email = Email::lookup($vars['from_email_id'])))
            $email = $dept->getEmail();

        $options = array('thread'=>$response);
        $signature = $from_name = '';
        if ($thisstaff && $vars['signature']=='mine')
            $signature=$thisstaff->getSignature();
        elseif ($vars['signature']=='dept' && $dept->isPublic())
            $signature=$dept->getSignature();

        if ($thisstaff && ($type=$thisstaff->getReplyFromNameType())) {
            switch ($type) {
                case 'mine':
                    if (!$cfg->hideStaffName())
                        $from_name = (string) $thisstaff->getName();
                    break;
                case 'dept':
                    if ($dept->isPublic())
                        $from_name = $dept->getName();
                    break;
                case 'email':
                default:
                    $from_name =  $email->getName();
            }
            if ($from_name)
                $options += array('from_name' => $from_name);
        }

        $variables = array(
            'response' => $response,
            'signature' => $signature,
            'staff' => $thisstaff,
            'poster' => $thisstaff
        );

        if ($email
                && $recipients
                && ($tpl = $dept->getTemplate())
                && ($msg=$tpl->getReplyMsgTemplate())) {

            // Add ticket link (possibly with authtoken) if the ticket owner
            // is the only recipient on a ticket with collabs
            if (count($recipients) == 1
                    && $this->getNumCollaborators()
                    && ($contact = $recipients->offsetGet(0)->getContact())
                    && ($contact instanceof TicketOwner))
                $variables['recipient.ticket_link'] =
                    $contact->getTicketLink();

            $msg = $this->replaceVars($msg->asArray(),
                $variables + array('recipient' => $this->getOwner())
            );

            // Attachments
            $attachments = $cfg->emailAttachments() ?
                $response->getAttachments() : array();

            //Send email to recepients
            $email->send($recipients, $msg['subj'], $msg['body'],
                    $attachments, $options);
        }

        return $response;
    }

function postNote($vars, &$errors, $poster=false, $alert=true) {
        global $cfg, $thisstaff;

        //Who is posting the note - staff or system? or user?
        if ($vars['staffId'] && !$poster)
            $poster = Staff::lookup($vars['staffId']);

        $vars['staffId'] = $vars['staffId'] ?: 0;
        if ($poster && is_object($poster) && !$vars['userId']) {
            $vars['staffId'] = $poster->getId();
            $vars['poster'] = $poster->getName();
        }
        elseif ($poster) { //string
            $vars['poster'] = $poster;
        }
        elseif (!isset($vars['poster'])) {
            $vars['poster'] = 'SYSTEM';
        }
        if (!$vars['ip_address'] && $_SERVER['REMOTE_ADDR'])
            $vars['ip_address'] = $_SERVER['REMOTE_ADDR'];

        if (!($note=$this->getThread()->addNote($vars, $errors)))
            return null;

        $alert = $alert && (
            isset($vars['mailflags'])
            // No alerts for bounce and auto-reply emails
            ? !$vars['mailflags']['bounce'] && !$vars['mailflags']['auto-reply']
            : true
        );

        // Get assigned staff just in case the ticket is closed.
        $assignee = $this->getStaff();

        if ($vars['note_status_id']
            && ($status=TicketStatus::lookup($vars['note_status_id']))
        ) {
            $this->setStatus($status);
        }

        $activity = $vars['activity'] ?: _S('New Internal Note');
        $this->onActivity(array(
            'activity' => $activity,
            'threadentry' => $note,
            'assignee' => $assignee
        ), $alert);

        $type = array('type' => 'note');
        Signal::send('object.created', $this, $type);

        return $note;
    }
}
