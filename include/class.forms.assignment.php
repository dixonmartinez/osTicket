<?php
/*********************************************************************
    class.forms.assignment.php

    Formularios de asignación, reclamo, liberación, marcado y referencia de
    tickets: los que usa el agente para mover un ticket de mano.

    Extraído de class.forms.php (frente 6 de docs/CODE_QUALITY.md). Se
    incluye desde class.forms.php, que ya ha declarado Form.

    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/


class AssignmentForm extends Form {

    static $id = 'assign';
    var $_assignee = null;
    var $_assignees = null;


    function getFields() {

        if ($this->fields)
            return $this->fields;

        $fields = array(
            'assignee' => new AssigneeField(array(
                    'id'=>1, 'label' => __('Assignee'),
                    'flags' => hexdec(0X450F3), 'required' => true,
                    'validator-error' => __('Assignee selection required'),
                    'configuration' => array(
                        'criteria' => array(
                            'available' => true,
                            ),
                       ),
                    )
                ),
            'refer' => new BooleanField(array(
                    'id'=>2, 'label'=>'', 'required'=>false,
                    'default'=>false,
                    'configuration'=>array(
                        'desc' => __('Maintain referral access to current assignees'))
                    )
                ),
            'comments' => new TextareaField(array(
                    'id' => 3, 'label'=> '', 'required'=>false, 'default'=>'',
                    'configuration' => array(
                        'html' => true,
                        'size' => 'small',
                        'placeholder' => __('Optional reason for the assignment'),
                        ),
                    )
                ),
            );


        if (isset($this->_assignees))
            $fields['assignee']->setChoices($this->_assignees);

        $this->setFields($fields);

        return $this->fields;
    }

    function getField($name) {

        if (($fields = $this->getFields())
                && isset($fields[$name]))
            return $fields[$name];
    }

    function isValid($include=false) {

        if (!parent::isValid($include) || !($f=$this->getField('assignee')))
            return false;

        // Do additional assignment validation
        if (!($assignee = $this->getAssignee())) {
            $f->addError(__('Unknown assignee'));
        } elseif ($assignee instanceof Staff) {
            // Make sure the agent is available
            if (!$assignee->isAvailable())
                $f->addError(__('Agent is unavailable for assignment'));
        } elseif ($assignee instanceof Team) {
            // Make sure the team is active and has members
            if (!$assignee->isActive())
                $f->addError(__('Team is disabled'));
            elseif (!$assignee->getNumMembers())
                $f->addError(__('Team does not have members'));
        }

        return !$this->errors();
    }

    function render($options=array()) {

        switch(strtolower($options['template'])) {
        case 'simple':
            $inc = STAFFINC_DIR . 'templates/dynamic-form-simple.tmpl.php';
            break;
        default:
            throw new Exception(sprintf(__('%s: Unknown template style %s'),
                        'FormUtils', $options['template']));
        }

        $form = $this;
        include $inc;
    }

    function setAssignees($assignees) {
        $this->_assignees = $assignees;
        $this->_fields = array();
    }

    function getAssignees() {
        return $this->_assignees;
    }

    function getAssignee() {

        if (!isset($this->_assignee))
            $this->_assignee = $this->getField('assignee')->getClean();

        return $this->_assignee;
    }

    function getComments() {
        return $this->getField('comments')->getClean();
    }

    function refer() {
        return $this->getField('refer')->getClean();
    }
}

class ClaimForm extends AssignmentForm {

    var $_fields;

    function setFields($fields) {
        $this->_fields = $fields;
        parent::setFields($fields);
    }

    function getFields() {

        if ($this->_fields)
            return $this->_fields;

        $fields = parent::getFields();

        // Disable && hide assignee field selection
        if (isset($fields['assignee'])) {
            $visibility = new VisibilityConstraint(
                    new Q(array()), VisibilityConstraint::HIDDEN);

            $fields['assignee']->set('visibility', $visibility);
        }

        // Change coments placeholder to reflect claim
        if (isset($fields['comments'])) {
            $fields['comments']->configure('placeholder',
                    __('Optional reason for the claim'));
        }


        $this->setFields($fields);

        return $this->fields;
    }

}

class ReleaseForm extends Form {
    static $id = 'unassign';

    function getFields() {
        if ($this->fields)
            return $this->fields;

        $fields = array(
            'comments' => new TextareaField(array(
                    'id' => 1, 'label'=> '', 'required'=>false, 'default'=>'',
                    'configuration' => array(
                        'html' => true,
                        'size' => 'small',
                        'placeholder' => __('Optional reason for releasing assignment'),
                        ),
                    )
                ),
            );


        $this->setFields($fields);

        return $this->fields;
    }

    function getField($name) {
        if (($fields = $this->getFields())
                && isset($fields[$name]))
            return $fields[$name];
    }

    function isValid($include=false) {
        if (!parent::isValid($include))
            return false;

        return !$this->errors();
    }

    function getComments() {
        return $this->getField('comments')->getClean();
    }
}

class MarkAsForm extends Form {
    static $id = 'markAs';

    function getFields() {
        if ($this->fields)
            return $this->fields;

        $fields = array(
            'comments' => new TextareaField(array(
                    'id' => 1, 'label'=> '', 'required'=>false, 'default'=>'',
                    'configuration' => array(
                        'html' => true,
                        'size' => 'small',
                        'placeholder' => __('Optional reason for marking ticket as (un)answered'),
                        ),
                    )
                ),
            );


        $this->setFields($fields);

        return $this->fields;
    }

    function getField($name) {
        if (($fields = $this->getFields())
                && isset($fields[$name]))
            return $fields[$name];
    }

    function isValid($include=false) {
        if (!parent::isValid($include))
            return false;

        return !$this->errors();
    }

    function getComments() {
        return $this->getField('comments')->getClean();
    }
}

class ReferralForm extends Form {

    static $id = 'refer';
    var $_target = null;
    var $_choices = null;
    var $_prompt = '';

    function getFields() {

        if ($this->fields)
            return $this->fields;

        $fields = array(
            'target' => new ChoiceField(array(
                    'id'=>1,
                    'label' => __('Referee'),
                    'flags' => hexdec(0X450F3),
                    'required' => true,
                    'validator-error' => __('Selection required'),
                    'choices' => array(
                    'agent' => __('Agent'),
                    'team'  => __('Team'),
                                'dept'  => __('Department'),
                               ),
                            )
                ),
            'agent' => new AgentSelectionField(array(
                    'id'=>2,
                    'label' => '',
                    'flags' => hexdec(0X450F3),
                    'required' => true,
                    'validator-error' => __('Agent selection required'),
                    'configuration'=>array('prompt'=>__('Select Agent')),
                            'visibility' => new VisibilityConstraint(
                                    new Q(array('target__eq'=>'agent')),
                                    VisibilityConstraint::HIDDEN
                              ),
                            )
                ),
            'team' => new ChoiceField(array(
                    'id'=>3,
                    'label' => '',
                    'flags' => hexdec(0X450F3),
                    'required' => true,
                    'validator-error' => __('Team selection required'),
                    'configuration'=>array('prompt'=>__('Select Team')),
                            'visibility' => new VisibilityConstraint(
                                    new Q(array('target__eq'=>'team')),
                                    VisibilityConstraint::HIDDEN
                              ),
                            )
                ),
            'dept' => new DepartmentField(array(
                    'id'=>4,
                    'label' => '',
                    'flags' => hexdec(0X450F3),
                    'required' => true,
                    'validator-error' => __('Dept. selection required'),
                    'configuration'=>array('prompt'=>__('Select Department')),
                            'visibility' => new VisibilityConstraint(
                                    new Q(array('target__eq'=>'dept')),
                                    VisibilityConstraint::HIDDEN
                              ),
                            )
                ),
            'comments' => new TextareaField(array(
                    'id' => 5,
                    'label'=> '',
                    'required'=>false,
                    'default'=>'',
                    'configuration' => array(
                        'html' => true,
                        'size' => 'small',
                        'placeholder' => __('Optional reason for the referral'),
                        ),
                    )
                ),
            );

        $this->setFields($fields);

        return $this->fields;
    }

    function getField($name) {

        if (($fields = $this->getFields())
                && isset($fields[$name]))
            return $fields[$name];
    }



    function isValid($include=false) {

        if (!parent::isValid($include) || !($f=$this->getField('target')))
            return false;

        // Do additional assignment validation
        $referee = $this->getReferee();
        switch (true) {
        case $referee instanceof Staff:
            // Make sure the agent is available
            if (!$referee->isAvailable())
                $f->addError(__('Agent is unavailable for assignment'));
        break;
        case $referee instanceof Team:
            // Make sure the team is active and has members
            if (!$referee->isActive())
                $f->addError(__('Team is disabled'));
            elseif (!$referee->getNumMembers())
                $f->addError(__('Team does not have members'));
        break;
        case $referee instanceof Dept:
        break;
        default:
            $f->addError(__('Unknown selection'));
        }

        return !$this->errors();
    }

    function render($options=array()) {

        switch(strtolower($options['template'])) {
        case 'simple':
            $inc = STAFFINC_DIR . 'templates/dynamic-form-simple.tmpl.php';
            break;
        default:
            throw new Exception(sprintf(__('%s: Unknown template style %s'),
                        'FormUtils', $options['template']));
        }

        $form = $this;
        include $inc;
    }

    function setChoices($field, $choices, $prompt='') {

        if (!($f= $this->getField($field)))
           return;

        $f->set('choices', $choices);

        return $f;
    }

    function getReferee() {

        $target = $this->getField('target')->getClean();
        if (!$target || !($f=$this->getField($target)))
            return null;

        $id = $f->getClean();
        switch($target) {
        case 'agent':
            return Staff::lookup($id);
        case 'team':
            return Team::lookup($id);
        case 'dept':
            return Dept::lookup($id);
        }
    }

    function getComments() {
        return $this->getField('comments')->getClean();
    }
}


class TransferForm extends Form {

    static $id = 'transfer';
    var $_dept = null;

    function __construct($source=null, $options=array()) {
        parent::__construct($source, $options);
    }

    function getFields() {

        if ($this->fields)
            return $this->fields;

        $fields = array(
            'dept' => new DepartmentField(array(
                    'id'=>1,
                    'label' => __('Department'),
                    'flags' => hexdec(0X450F3),
                    'required' => true,
                    'validator-error' => __('Department selection is required'),
                    )
                ),
            'refer' => new BooleanField(array(
                'id'=>2, 'label'=>'', 'required'=>false, 'default'=>false,
                'configuration'=>array(
                    'desc' => __('Maintain referral access to current department'))
            )),
            'comments' => new TextareaField(array(
                    'id' => 3,
                    'label'=> '',
                    'required'=>false,
                    'default'=>'',
                    'configuration' => array(
                        'html' => true,
                        'size' => 'small',
                        'placeholder' => __('Optional reason for the transfer'),
                        ),
                    )
                ),
            );

        $this->setFields($fields);

        return $this->fields;
    }

    function isValid($include=false) {

        if (!parent::isValid($include))
            return false;

        // Do additional validations
        if (!($dept = $this->getDept()))
            $this->getField('dept')->addError(
                    __('Unknown department'));

        return !$this->errors();
    }

    function render($options=array()) {

        switch(strtolower($options['template'])) {
        case 'simple':
            $inc = STAFFINC_DIR . 'templates/dynamic-form-simple.tmpl.php';
            break;
        default:
            throw new Exception(sprintf(__('%s: Unknown template style %s'),
                        get_class($this), $options['template']));
        }

        $form = $this;
        include $inc;

    }

    function refer() {
        return $this->getField('refer')->getClean();
    }

    function getDept() {

        if (!isset($this->_dept)) {
            if (($id = $this->getField('dept')->getClean()))
                $this->_dept = Dept::lookup($id);
        }

        return $this->_dept;
    }

    function hideDisabled() {
        global $thisstaff;

        if ($f = $this->getField('dept')) {
            $f->configure('staff', $thisstaff);
            $f->configure('hideDisabled', true);
        }
    }
}
