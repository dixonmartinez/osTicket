<?php
/*********************************************************************
    class.ticket.lifecycle.php

    La vida del ticket desde el modelo: crearlo, editarlo, cerrarlo, borrarlo
    y guardar sus campos. `create()` es el guion de nueve fases que vive en
    TicketCreate; `open()` es la validacion de la pantalla de alta.

    Es un rasgo de Ticket y no una clase aparte porque estos metodos
    guardan con `$this->save()`, emiten sus señales y devuelven los errores en
    el array que reciben. Se sacaron de include/class.ticket.php (frente 6 de
    docs/CODE_QUALITY.md) moviendo los metodos enteros, sin tocar su codigo.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

trait TicketLifecycle {

function delete($comments='') {
        global $ost, $thisstaff;

        //delete just orphaned ticket thread & associated attachments.
        // Fetch thread prior to removing ticket entry
        $t = $this->getThread();

        if (!parent::delete())
            return false;

        //deleting parent ticket
        if ($children = $this->getChildren()) {
            foreach ($children as $childId) {
                if (!($child = Ticket::lookup($childId[0])))
                    continue;

                $child->setPid(NULL);
                $child->setMergeType(3);
                $child->save();
                $childThread = $child->getThread();
                $childThread->object_type = 'T';
                $childThread->save();
            }
        }

        //deleting child ticket
        if ($this->isChild()) {
            $parent = Ticket::lookup($this->ticket_pid);
            if ($parent->isParent() && count($parent->getChildren()) == 0) {
                $parent->setMergeType(3);
                $parent->save();
            }
        } else
            $t->delete();

        $this->logEvent('deleted');

        foreach (DynamicFormEntry::forTicket($this->getId()) as $form)
            $form->delete();

        $this->deleteDrafts();

        if ($this->cdata)
            $this->cdata->delete();

        // Log delete
        $log = sprintf(__('Ticket #%1$s deleted by %2$s'),
            $this->getNumber(),
            $thisstaff ? $thisstaff->getName() : __('SYSTEM')
        );
        if ($comments)
            $log .= sprintf('<hr>%s', $comments);

        $ost->logDebug(
            sprintf( __('Ticket #%s deleted'), $this->getNumber()),
            $log
        );
        return true;
    }

function update($vars, &$errors) {
        global $cfg, $thisstaff;

        if (!$cfg
            || !($this->checkStaffPerm($thisstaff,
                Ticket::PERM_EDIT))
        ) {
            return false;
        }

        if (!Validator::process(TicketEdit::fields(), $vars, $errors)
                && !$errors['err'])
            $errors['err'] = sprintf('%s — %s',
                __('Missing or invalid data'),
                __('Correct any errors below and try again'));

        $vars['note'] = ThreadEntryBody::clean($vars['note']);

        $due = function($date) {
            return strtotime($date) === false ? false : Misc::user2gmtime($date);
        };
        if ($error = TicketEdit::dueDateError($vars['duedate'],
                $this->isClosed(), $due, Misc::user2gmtime()))
            $errors['duedate'] = $error;

        // Check ticket source if provided
        if ($error = TicketEdit::sourceError(
                isset($vars['source']) ? $vars['source'] : null,
                Ticket::getSources()))
            $errors['source'] = $error;

        $topic = Topic::lookup($vars['topicId']);
        if($topic && !$topic->isActive())
          $errors['topicId']= sprintf(__('%s selected must be active'), __('Help Topic'));

        // Validate dynamic meta-data
        $forms = DynamicFormEntry::forTicket($this->getId());
        foreach ($forms as $form) {
            // Don't validate deleted forms
            if (!in_array($form->getId(), $vars['forms']))
                continue;
            $form->filterFields(function($f) { return !$f->isStorable(); });
            $form->setSource($_POST);
            if (!$form->isValid(function($f) {
                return $f->isVisibleToStaff() && $f->isEditableToStaff();
            })) {
                $errors = array_merge($errors, $form->errors());
            }
        }

        if ($errors)
            return false;

        // Decide if we need to keep the just selected SLA
        $keepSLA = ($this->getSLAId() != $vars['slaId']);

        $this->topic_id = $vars['topicId'];
        $this->sla_id = $vars['slaId'];
        $this->source = $vars['source'];
        $this->duedate = $vars['duedate']
            ? date('Y-m-d H:i:s',Misc::dbtime($vars['duedate']))
            : null;

        if ($vars['user_id'])
            $this->user_id = $vars['user_id'];
        if ($vars['duedate'])
            // We are setting new duedate...
            $this->isoverdue = 0;

        $changes = TicketEdit::trackedChanges($this->dirty,
            function($field) { return $this->{$field}; });

        if (!$this->save())
            return false;

        // `$vars['note']` ya viene limpio de arriba
        if ($vars['note'])
            $this->logNote(_S('Ticket Updated'), $vars['note'], $thisstaff);

        // Update dynamic meta-data
        foreach ($forms as $form) {
            if ($C = $form->getChanges())
                $changes['fields'] = ($changes['fields'] ?: array()) + $C;
            // Drop deleted forms
            $idx = array_search($form->getId(), $vars['forms']);
            if ($idx === false) {
                $form->delete();
            }
            else {
                $form->set('sort', $idx);
                $form->saveAnswers(function($f) {
                        return $f->isVisibleToStaff()
                        && $f->isEditableToStaff(); }
                        );
            }
        }

        if ($changes) {
          $this->logEvent('edited', $changes);
        }


        // Reselect SLA if transient
        if (!$keepSLA
            && (!$this->getSLA() || $this->getSLA()->isTransient())
        ) {
            $this->selectSLAId();
        }

        if (!$this->save())
            return false;

        $this->updateEstDueDate();
        Signal::send('model.updated', $this);

        return true;
   }

function updateField($form, &$errors) {
       global $thisstaff, $cfg;

       if (!($field = $form->getField('field')))
           return null;

       $updateDuedate = false;
       if (!($changes = $field->getChanges()))
           $errors['field'] = sprintf(__('%s is already assigned this value'),
                   __($field->getLabel()));
       else {
           if ($field->answer) {
               if (!$field->isEditableToStaff())
                   $errors['field'] = sprintf(__('%s can not be edited'),
                           __($field->getLabel()));
               elseif (!$field->save(true))
                   $errors['field'] =  __('Unable to update field');

               // Strip tags from TextareaFields to ensure event data is not
               // truncated
               if ($field instanceof TextareaField)
                   foreach ($changes as $k=>$v)
                       $changes[$k] = Format::truncate(Format::striptags($v), 200);

               $changes['fields'] = array($field->getId() => $changes);
           } else {
               $val =  $field->getClean();
               $fid = $field->get('name');

               // Convert duedate to DB timezone.
               if ($fid == 'duedate') {
                   if (empty($val))
                       $val = null;
                   elseif ($dt = Format::parseDateTime($val)) {
                     // Make sure the due date is valid
                     if (Misc::user2gmtime($val) <= Misc::user2gmtime())
                         $errors['field']=__('Due date must be in the future');
                     else {
                         $dt->setTimezone(new DateTimeZone($cfg->getDbTimezone()));
                         $val = $dt->format('Y-m-d H:i:s');
                     }
                  }
               } elseif (is_object($val))
                   $val = $val->getId();

               $changes = array();
               $this->{$fid} = $val;
               foreach ($this->dirty as $F=>$old) {
                   switch ($F) {
                   case 'sla_id':
                   case 'duedate':
                        $updateDuedate = true;
                   case 'topic_id':
                   case 'user_id':
                   case 'source':
                       $changes[$F] = array($old, $this->{$F});
                   }
               }

               if (!$errors && !$this->save())
                   $errors['field'] =  __('Unable to update field');
           }
       }

       if ($errors)
           return false;

       // Record the changes
       $this->logEvent('edited', $changes);

       // Log comments (if any)
       if (($comments = $form->getField('comments')->getClean())) {
           $title = sprintf(__('%s updated'), __($field->getLabel()));
           $_errors = array();
           $this->postNote(
                   array('note' => $comments, 'title' => $title),
                   $_errors, $thisstaff, false);
       }

       $this->lastupdate = SqlFunction::NOW();

       if ($updateDuedate)
           $this->updateEstDueDate();

       $this->save();

       Signal::send('model.updated', $this);

       return true;
   }

protected static function filterTicketData($origin, $vars, $forms, $user=false, $postCreate=false) {
        global $cfg;

        // Unset all the filter data field data in case things change
        // during recursive calls
        foreach ($vars as $k=>$v)
            if (strpos($k, 'field.') === 0)
                unset($vars[$k]);

        foreach ($forms as $F) {
            if ($F) {
                $vars += $F->getFilterData();
            }
        }

        if (!$user) {
            $interesting = array('name', 'email');
            $user_form = UserForm::getUserForm()->getForm($vars);
            // Add all the user-entered info for filtering
            foreach ($interesting as $F) {
                if ($field = $user_form->getField($F))
                    $vars[$F] = $field->toString($field->getClean());
            }
            // Attempt to lookup the user and associated data
            $user = User::lookupByEmail($vars['email']);
        }

        // Add in user and organization data for filtering
        if ($user) {
            $vars += $user->getFilterData();
            $vars['email'] = $user->getEmail();
            $vars['name'] = $user->getName()->getOriginal();
            if ($org = $user->getOrganization()) {
                $vars += $org->getFilterData();
            }
        }
        // Don't include org information based solely on email domain
        // for existing user instances
        else {
            // Unpack all known user info from the request
            foreach ($user_form->getFields() as $f) {
                $vars['field.'.$f->get('id')] = $f->toString($f->getClean());
            }
            // Add in organization data if one exists for this email domain
            list($mailbox, $domain) = explode('@', $vars['email'], 2);
            if ($org = Organization::forDomain($domain)) {
                $vars += $org->getFilterData();
            }
        }

        try {
            // Make sure the email address is not banned
            if (($filter=Banlist::isBanned($vars['email']))) {
                throw new RejectedException($filter, $vars);
            }

            // Init ticket filters...
            $ticket_filter = new TicketFilter($origin, $vars);
            $ticket_filter->apply($vars, $postCreate);

            if ($postCreate && $filterMatches = $ticket_filter->getMatchingFilterList()) {
                $username = __('Ticket Filter');
                foreach ($filterMatches as $f) {
                    $actions = $f->getActions();
                    foreach ($actions as $key => $value) {
                        $filterName = $f->getName();
                        if (!$coreClass = $value->lookupByType($value->type))
                            continue;

                        if ($description = $coreClass->getEventDescription($value, $filterName))
                            $postCreate->logEvent($description['type'], $description['desc'], $username);

                    }
                    if ($f->stopOnMatch()) break;
                }
            }
        }
        catch (FilterDataChanged $ex) {
            // Don't pass user recursively, assume the user has changed
            return self::filterTicketData($origin, $ex->getData(), $forms);
        }
        return $vars;
    }

/*
     * The mother of all functions...You break it you fix it!
     *
     *  $autorespond and $alertstaff overrides config settings...
     */
    static function create($vars, &$errors, $origin, $autorespond=true,
            $alertstaff=true) {
        global $ost, $cfg, $thisstaff;

        // Don't enforce form validation for email. Ver TicketCreate
        $field_filter = TicketCreate::fieldFilter($origin);

        $reject_ticket = function($message) use (&$errors) {
            global $ost;
            $errors = array(
                'errno' => 403,
                'err' => __('This help desk is for use by authorized users only'));
            $ost->logWarning(_S('Ticket denied'), $message, false);
            return 0;
        };

        Signal::send('ticket.create.before', null, $vars);

        // Create and verify the dynamic form entry for the new ticket
        $form = TicketForm::getNewInstance();
        $form->setSource($vars);

        // If submitting via email or api, ensure we have a subject and such
        if (!in_array(strtolower($origin), array('web', 'staff'))) {
            foreach ($form->getFields() as $field) {
                $fname = $field->get('name');
                if ($fname && isset($vars[$fname]) && !$field->value)
                    $field->value = $field->parse($vars[$fname]);
            }
        }

        if ($vars['uid'])
            $user = User::lookup($vars['uid']);

        // Campos que exige el origen y comprobación de la fecha límite
        TicketCreate::requestErrors($origin, $vars, $errors);

        $topic_forms = array();
        if (!$errors) {

            // Formularios del tema, instanciados, con los campos que el
            // tema desactiva ya marcados. Ver TicketCreate::topicForms()
            $topic_forms = TicketCreate::topicForms($form, $vars);

            try {
                $vars = self::filterTicketData($origin, $vars,
                    array_merge(array($form), $topic_forms), $user, false);
            }
            catch (RejectedException $ex) {
                return $reject_ticket(
                    sprintf(_S('Ticket rejected (%s) by filter "%s"'),
                    $ex->vars['email'], $ex->getRejectingFilter()->getName())
                );
            }

            //Make sure the open ticket limit hasn't been reached. (LOOP CONTROL)
            if ($cfg->getMaxOpenTickets() > 0
                    && strcasecmp($origin, 'staff')
                    && ($_user=TicketUser::lookupByEmail($vars['email']))
                    && ($openTickets=$_user->getNumOpenTickets())
                    && ($openTickets>=$cfg->getMaxOpenTickets()) ) {

                $errors = array('err' => __("You've reached the maximum open tickets allowed."));
                $ost->logWarning(sprintf(_S('Ticket denied - %s'), $vars['email']),
                        sprintf(_S('Max open tickets (%1$d) reached for %2$s'),
                            $cfg->getMaxOpenTickets(), $vars['email']),
                        false);

                return 0;
            }

            // Allow vars to be changed in ticket filter and applied to the user
            // account created or detected
            if (!$user && $vars['email'])
                $user = User::lookupByEmail($vars['email']);

            if (!$user) {
                // Reject emails if not from registered clients (if
                // configured)
                if (strcasecmp($origin, 'email') === 0
                        && !$cfg->acceptUnregisteredEmail()) {
                    list($mailbox, $domain) = explode('@', $vars['email'], 2);
                    // Users not yet created but linked to an organization
                    // are still acceptable
                    if (!Organization::forDomain($domain)) {
                        return $reject_ticket(
                            sprintf(_S('Ticket rejected (%s) (unregistered client)'),
                                $vars['email']));
                    }
                }

                $user_form = UserForm::getUserForm()->getForm($vars);
                $can_create = !$thisstaff || $thisstaff->hasPerm(User::PERM_CREATE);
                if (!$user_form->isValid($field_filter('user'))
                    || !($user=User::fromVars($user_form->getClean(), $can_create))
                ) {
                    $errors['user'] = $can_create
                        ? __('Incomplete client information')
                        : __('You do not have permission to create users.');
                }
            }
        }

        // Validación de los formularios: el del ticket y los del tema.
        // De aquí sale también el tema de la petición
        TicketCreate::formErrors($form, $topic_forms, $vars, $field_filter,
            $errors, $topic);

        // Any errors above are fatal.
        if ($errors)
            return 0;

        Signal::send('ticket.create.validated', null, $vars);

        # Some things will need to be unpacked back into the scope of this
        # function
        if (isset($vars['autorespond']))
            $autorespond = $vars['autorespond'];

        # Apply filter-specific priority
        if ($vars['priorityId'])
            $form->setAnswer('priority', null, $vars['priorityId']);

        // If the filter specifies a help topic which has a form associated,
        // and there was previously either no help topic set or the help
        // topic did not have a form, there's no need to add it now as (1)
        // validation is closed, (2) there may be a form already associated
        // and filled out from the original  help topic, and (3) staff
        // members can always add more forms now

        // A dónde va el ticket: correo, tema, organización y valores por
        // omisión. Ver TicketCreate::routing()
        $routing = TicketCreate::routing($vars, $cfg, $form, $topic, $user,
            $autorespond);
        $deptId = $routing['dept_id'];
        $statusId = $routing['status_id'];
        $topic = $routing['topic'];
        $topicId = $routing['topic_id'];
        $source = $routing['source'];
        $ipaddress = $routing['ip'];
        $number = $routing['number'];
        $org = $routing['org'];

        $ticket = new static(array(
            'created' => SqlFunction::NOW(),
            'lastupdate' => SqlFunction::NOW(),
            'number' => $number,
            'user' => $user,
            'dept_id' => $deptId,
            'topic_id' => $topicId,
            'ip_address' => $ipaddress,
            'source' => $source,
        ));

        if (isset($vars['emailId']) && $vars['emailId'])
            $ticket->email_id = $vars['emailId'];

        //Make sure the origin is staff - avoid firebug hack!
        if ($vars['duedate'] && in_array(strtolower($origin), ['staff', 'api']))
            $ticket->duedate = date('Y-m-d G:i',
                Misc::dbtime($vars['duedate']));


        if (!$ticket->save())
            return null;
        if (!($thread = TicketThread::create($ticket->getId())))
            return null;

        /* -------------------- POST CREATE ------------------------ */

        // Formulario del ticket y del tema; datos dinámicos ya cargados
        TicketCreate::saveForms($ticket, $form, $topic_forms, $topic);

        // Start tracking ticket lifecycle events (created should come first!)
        $ticket->logEvent('created', null, $thisstaff ?: $user);

        // Set default ticket status (if none) for Thread::getObject()
        // in addCollaborators()
        if ($ticket->getStatusId() <= 0)
            $ticket->setStatusId($cfg->getDefaultTicketStatusId());

        // Colaboradores de la petición y de la organización
        TicketCreate::addCollaborators($ticket, $vars, $org, $errors);

        //post the message.
        $vars['title'] = $vars['subject']; //Use the initial subject as title of the post.
        $vars['userId'] = $ticket->getUserId();
        $message = $ticket->postMessage($vars , $origin, false);

        $vars['ticket'] = $ticket;
        self::filterTicketData($origin, $vars,
            array_merge(array($form), $topic_forms), $user, $ticket);

        // If a message was posted, flag it as the orignal message. This
        // needs to be done on new ticket, so as to otherwise separate the
        // concept from the first message entry in a thread.
        if ($message instanceof ThreadEntry) {
            $message->setFlag(ThreadEntry::FLAG_ORIGINAL_MESSAGE);
            $message->save();
        }

        // Aviso interno cruzado cuando el ticket nace de otra entrada
        TicketCreate::linkOriginThread($ticket, $thisstaff, $errors);

        // SLA, estado, asignación y fecha límite estimada
        TicketCreate::applyStatusAndAssignment($ticket, $vars, $statusId,
            $origin, $cfg, $errors);

        // Respuesta automática, avisos y control del límite de abiertos
        TicketCreate::notify($ticket, $message, $vars, $autorespond,
            $alertstaff, $origin, $cfg);

        // Fire post-create signal (for extra email sending, searching)
        Signal::send('ticket.created', $ticket);

        /* Phew! ... time for tea (KETEPA) */

        return $ticket;
    }

/* routine used by staff to open a new ticket */
    static function open($vars, &$errors) {
        global $thisstaff, $cfg;

        if (!$thisstaff)
            return false;

        if ($vars['deptId']
            && ($dept=Dept::lookup($vars['deptId']))
            && ($role = $thisstaff->getRole($dept))
            && !$role->hasPerm(Ticket::PERM_CREATE)
        ) {
            $errors['err'] = sprintf(__('You do not have permission to create a ticket in %s'), __('this department'));
            return false;
        }

        // Origen conocido y, si no se eligió usuario, correo con forma de
        // correo y un nombre. Vive en `TicketCreate` con el resto de reglas
        // de apertura.
        $errors += TicketCreate::openErrors($vars, Ticket::getSources());

        // Ensure agent has rights to make assignment in the cited
        // department
        if ($vars['assignId'] && !(
            $role
            ? ($role->hasPerm(Ticket::PERM_ASSIGN) || $role->__new__)
            : $thisstaff->hasPerm(Ticket::PERM_ASSIGN, false)
        )) {
            $errors['assignId'] = __('Action Denied. You are not allowed to assign/reassign tickets.');
        }

        // TODO: Deny action based on selected department.
        $vars['response'] = ThreadEntryBody::clean($vars['response']);
        $vars['note'] = ThreadEntryBody::clean($vars['note']);
        $create_vars = $vars;
        $tform = TicketForm::objects()->one()->getForm($create_vars);
        $mfield = $tform->getField('message');
        $create_vars['message'] = $mfield->getClean();
        $create_vars['files'] = $mfield->getWidget()->getAttachments()->getFiles();

        if (!($ticket=self::create($create_vars, $errors, 'staff', false)))
            return false;

        $vars['msgId']=$ticket->getLastMsgId();

        // Effective role for the department
        $role = $ticket->getRole($thisstaff);

        $alert = strcasecmp('none', $vars['reply-to']);
        // post response - if any
        $response = null;
        if ($vars['response'] && $role->hasPerm(Ticket::PERM_REPLY)) {
            // $vars['cannedatachments'] contains the attachments placed on
            // the response form.
            $response = $ticket->postReply($vars, $errors, ($alert &&
                        !$cfg->notifyONNewStaffTicket()));
        }

        // Not assigned...save optional note if any
        if (!$vars['assignId'] && $vars['note']) {
            if (!$cfg->isRichTextEnabled())
                $vars['note'] = new TextThreadEntryBody($vars['note']);
            $ticket->logNote(_S('New Ticket'), $vars['note'], $thisstaff, false);
        }

        if (!$cfg->notifyONNewStaffTicket()
            || !$alert
            || !($dept=$ticket->getDept())
        ) {
            return $ticket; //No alerts.
        }

        // Notice Recipients
        $recipients = $ticket->getRecipients($vars['reply-to']);

        // Send Notice to user --- if requested AND enabled!!
        if (($tpl=$dept->getTemplate())
            && ($msg=$tpl->getNewTicketNoticeMsgTemplate())
            && ($email=$dept->getEmail())
        ) {
           $attachments = array();
           $message = $ticket->getLastMessage();
           if ($cfg->emailAttachments()) {
               if ($message && $message->getNumAttachments()) {
                 foreach ($message->getAttachments() as $attachment)
                     $attachments[] = $attachment;
               }
               if ($response && $response->getNumAttachments()) {
                 foreach ($response->getAttachments() as $attachment)
                     $attachments[] = $attachment;
               }
           }

            if ($vars['signature']=='mine')
                $signature=$thisstaff->getSignature();
            elseif ($vars['signature']=='dept' && $dept && $dept->isPublic())
                $signature=$dept->getSignature();
            else
                $signature='';

            $msg = $ticket->replaceVars($msg->asArray(),
                array(
                    'message'   => $message ?: '',
                    'response'  => $response ?: '',
                    'signature' => $signature,
                    'recipient' => $ticket->getOwner(), //End user
                    'staff'     => $thisstaff,
                )
            );
            $message = $ticket->getLastMessage();
            $options = array(
                'thread' => $message ?: $ticket->getThread(),
            );

            //ticket created on user's behalf
            $email->send($recipients, $msg['subj'], $msg['body'], $attachments,
                $options);
        }
        return $ticket;
    }

static function registerCustomData(DynamicForm $form) {
        if (!isset(static::$meta['joins']['cdata+'.$form->id])) {
            $cdata_class = <<<EOF
class DynamicForm{$form->id} extends DynamicForm {
    static function getInstance() {
        static \$instance;
        if (!isset(\$instance))
            \$instance = static::lookup({$form->id});
        return \$instance;
    }
}
class TicketCdataForm{$form->id}
extends VerySimpleModel {
    static \$meta = array(
        'view' => true,
        'pk' => array('ticket_id'),
        'joins' => array(
            'ticket' => array(
                'constraint' => array('ticket_id' => 'Ticket.ticket_id'),
            ),
        )
    );
    static function getQuery(\$compiler) {
        return '('.DynamicForm{$form->id}::getCrossTabQuery('T', 'ticket_id').')';
    }
}
EOF;
            eval($cdata_class);
            $join = array(
                'constraint' => array('ticket_id' => 'TicketCdataForm'.$form->id.'.ticket_id'),
                'list' => true,
            );
            // This may be necessary if the model has already been inspected
            if (static::$meta instanceof ModelMeta)
                static::$meta->addJoin('cdata+'.$form->id, $join);
            else {
                static::$meta['joins']['cdata+'.$form->id] = array(
                    'constraint' => array('ticket_id' => 'TicketCdataForm'.$form->id.'.ticket_id'),
                    'list' => true,
                );
            }
        }
    }
}
