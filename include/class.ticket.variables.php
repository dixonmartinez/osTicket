<?php
/*********************************************************************
    class.ticket.variables.php

    Lo que un ticket expone hacia fuera: sus variables de plantilla (las que
    se usan en los correos y en las respuestas automaticas) y la lista de
    campos por los que se puede buscar.

    Es un rasgo de Ticket y no una clase aparte porque las dos cosas se arman
    con el estado del ticket; `getSearchableFields()` es ademas la mitad de la
    busqueda por cola. Se sacaron de include/class.ticket.php (frente 6 de
    docs/CODE_QUALITY.md) moviendo los metodos enteros, sin tocar su codigo.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

trait TicketVariables {

function getVar($tag) {
        global $cfg;

        switch(mb_strtolower($tag)) {
        case 'phone':
        case 'phone_number':
            return $this->getPhoneNumber();
            break;
        case 'auth_token':
            return $this->getOldAuthToken();
            break;
        case 'client_link':
            return sprintf('%s/view.php?t=%s',
                    $cfg->getBaseUrl(), $this->getNumber());
            break;
        case 'staff_link':
            return sprintf('%s/scp/tickets.php?id=%d', $cfg->getBaseUrl(), $this->getId());
            break;
        case 'create_date':
            return new FormattedDate($this->getCreateDate());
            break;
         case 'due_date':
            if ($due = $this->getEstDueDate())
                return new FormattedDate($due);
            break;
        case 'close_date':
            if ($this->isClosed())
                return new FormattedDate($this->getCloseDate());
            break;
        case 'last_update':
            return new FormattedDate($this->lastupdate);
        case 'user':
            return $this->getOwner();
        default:
            if ($a = $this->getAnswer($tag))
                // The answer object is retrieved here which will
                // automatically invoke the toString() method when the
                // answer is coerced into text
                return $a;
        }
    }

static function getVarScope() {
        $base = array(
            'assigned' => __('Assigned Agent / Team'),
            'close_date' => array(
                'class' => 'FormattedDate', 'desc' => __('Date Closed'),
            ),
            'create_date' => array(
                'class' => 'FormattedDate', 'desc' => __('Date Created'),
            ),
            'dept' => array(
                'class' => 'Dept', 'desc' => __('Department'),
            ),
            'due_date' => array(
                'class' => 'FormattedDate', 'desc' => __('Due Date'),
            ),
            'email' => __('Default email address of ticket owner'),
            'id' => __('Ticket ID (internal ID)'),
            'name' => array(
                'class' => 'PersonsName', 'desc' => __('Name of ticket owner'),
            ),
            'number' => __('Ticket Number'),
            'phone' => __('Phone number of ticket owner'),
            'priority' => array(
                'class' => 'Priority', 'desc' => __('Priority'),
            ),
            'recipients' => array(
                'class' => 'UserList', 'desc' => __('List of all recipient names'),
            ),
            'source' => __('Source'),
            'status' => array(
                'class' => 'TicketStatus', 'desc' => __('Status'),
            ),
            'staff' => array(
                'class' => 'Staff', 'desc' => __('Assigned/closing agent'),
            ),
            'subject' => 'Subject',
            'team' => array(
                'class' => 'Team', 'desc' => __('Assigned/closing team'),
            ),
            'thread' => array(
                'class' => 'TicketThread', 'desc' => __('Ticket Thread'),
            ),
            'topic' => array(
                'class' => 'Topic', 'desc' => __('Help Topic'),
            ),
            // XXX: Isn't lastreponse and lastmessage more useful
            'last_update' => array(
                'class' => 'FormattedDate', 'desc' => __('Time of last update'),
            ),
            'user' => array(
                'class' => 'User', 'desc' => __('Ticket Owner'),
            ),
        );

        $extra = VariableReplacer::compileFormScope(TicketForm::getInstance());
        return $base + $extra;
    }

// Searchable interface
    static function getSearchableFields() {
        global $thisstaff;

        $base = array(
            'number' => new TextboxField(array(
                'label' => __('Ticket Number')
            )),
            'created' => new DatetimeField(array(
                'label' => __('Create Date'),
                'configuration' => array(
                    'fromdb' => true, 'time' => true,
                    'format' => 'y-MM-dd HH:mm:ss'),
            )),
            'duedate' => new DatetimeField(array(
                'label' => __('Due Date'),
                'configuration' => array(
                    'fromdb' => true, 'time' => true,
                    'format' => 'y-MM-dd HH:mm:ss'),
            )),
            'est_duedate' => new DatetimeField(array(
                'label' => __('SLA Due Date'),
                'configuration' => array(
                    'fromdb' => true, 'time' => true,
                    'format' => 'y-MM-dd HH:mm:ss'),
            )),
            'reopened' => new DatetimeField(array(
                'label' => __('Reopen Date'),
                'configuration' => array(
                    'fromdb' => true, 'time' => true,
                    'format' => 'y-MM-dd HH:mm:ss'),
            )),
            'closed' => new DatetimeField(array(
                'label' => __('Close Date'),
                'configuration' => array(
                    'fromdb' => true, 'time' => true,
                    'format' => 'y-MM-dd HH:mm:ss'),
            )),
            'lastupdate' => new DatetimeField(array(
                'label' => __('Last Update'),
                'configuration' => array(
                    'fromdb' => true, 'time' => true,
                    'format' => 'y-MM-dd HH:mm:ss'),
            )),
            'assignee' => new AssigneeChoiceField(array(
                'label' => __('Assignee'),
            )),
            'staff_id' => new AgentSelectionField(array(
                'label' => __('Assigned Staff'),
                'configuration' => array('staff' => $thisstaff),
            )),
            'team_id' => new TeamSelectionField(array(
                'label' => __('Assigned Team'),
            )),
            'dept_id' => new DepartmentChoiceField(array(
                'label' => __('Department'),
            )),
            'sla_id' => new SLAChoiceField(array(
                'label' => __('SLA Plan'),
            )),
            'topic_id' => new HelpTopicChoiceField(array(
                'label' => __('Help Topic'),
            )),
            'source' => new TicketSourceChoiceField(array(
                'label' => __('Ticket Source'),
            )),
            'isoverdue' => new BooleanField(array(
                'label' => __('Overdue'),
                'descsearchmethods' => array(
                    'set' => '%s',
                    'nset' => 'Not %s'
                    ),
            )),
            'isanswered' => new BooleanField(array(
                'label' => __('Answered'),
                'descsearchmethods' => array(
                    'set' => '%s',
                    'nset' => 'Not %s'
                    ),
            )),
            'isassigned' => new AssignedField(array(
                        'label' => __('Assigned'),
            )),
            'merged' => new MergedField(array(
                'label' => __('Merged'),
            )),
            'linked' => new LinkedField(array(
                'label' => __('Linked'),
            )),
            'thread_count' => new TicketThreadCountField(array(
                        'label' => __('Thread Count'),
            )),
            'attachment_count' => new ThreadAttachmentCountField(array(
                        'label' => __('Attachment Count'),
            )),
            'collaborator_count' => new ThreadCollaboratorCountField(array(
                        'label' => __('Collaborator Count'),
            )),
            'task_count' => new TicketTasksCountField(array(
                        'label' => __('Task Count'),
            )),
            'reopen_count' => new TicketReopenCountField(array(
                        'label' => __('Reopen Count'),
            )),
            'ip_address' => new TextboxField(array(
                'label' => __('IP Address'),
                'configuration' => array('validator' => 'ip'),
            )),
        );
        $tform = TicketForm::getInstance();
        foreach ($tform->getFields() as $F) {
            $fname = $F->get('name') ?: ('field_'.$F->get('id'));
            if (!$F->hasData() || $F->isPresentationOnly() || !$F->isEnabled())
                continue;
            if (!$F->isStorable())
                $base[$fname] = $F;
            else
                $base["cdata__{$fname}"] = $F;
        }
        return $base;
    }
}
