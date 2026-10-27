<?php
/*********************************************************************
    class.ticket.forms.php

    Los formularios que el panel pide para un ticket: el campo dinamico, el de
    asignacion y el de derivacion.

    Es un rasgo de Ticket y no una clase aparte porque los tres arman el
    formulario con los datos del propio ticket (departamento, estado,
    asignados) y devuelven el objeto que el panel pinta. Se sacaron de
    include/class.ticket.php (frente 6 de docs/CODE_QUALITY.md) moviendo los
    metodos enteros, sin tocar su codigo.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

trait TicketForms {

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
}
