<?php
/*********************************************************************
    class.forms.ticket.php

    Formularios: campos del ticket.

    Parte de include/class.forms.php (frente 6 de docs/CODE_QUALITY.md):
    el archivo tenia 4.515 lineas y 37 clases. Se movieron las clases
    enteras, sin tocar su codigo.

    Los que muestran y eligen cosas del ticket: tema, SLA, prioridad, zona
 * horaria, departamento, responsable, estado y banderas.

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/


/**
 * This is kind-of a special field that doesn't have any data. It's used as
 * a field to provide a horizontal section break in the display of a form
 */
class SectionBreakField extends FormField {
    static $widget = 'SectionBreakWidget';

    function hasData() {
        return false;
    }

    function isBlockLevel() {
        return true;
    }

    function isEditableToStaff() {
        return $this->isVisibleToStaff();
    }

    function isEditableToUsers() {
        return $this->isVisibleToUsers();
    }
}


class ThreadEntryField extends FormField {
    static $widget = 'ThreadEntryWidget';

    function isChangeable() {
        return false;
    }
    function isBlockLevel() {
        return true;
    }
    function isPresentationOnly() {
        return true;
    }
    function getMedia() {
        $config = $this->getConfiguration();
        $media = parent::getMedia() ?: array();
        if ($config['attachments'])
            $media = array_merge_recursive($media, FileUploadWidget::$media);
        return $media;
    }

    function getConfiguration() {
        global $cfg;
        $config = parent::getConfiguration();
        $config['html'] = (bool) ($cfg && $cfg->isRichTextEnabled());
        return $config;
    }

    function getConfigurationOptions() {
        global $cfg;

        $attachments = new FileUploadField();
        $fileupload_config = $attachments->getConfigurationOptions();
        if ($cfg->getAllowedFileTypes())
            $fileupload_config['extensions']->set('default', $cfg->getAllowedFileTypes());

        foreach ($fileupload_config as $C) {
            $C->set('visibility', new VisibilityConstraint(new Q(array(
                'attachments__eq'=>true,
            )), VisibilityConstraint::HIDDEN));
        }
        return array(
            'attachments' => new BooleanField(array(
                'label'=>__('Enable Attachments'),
                'default'=>$cfg->allowAttachments(),
                'configuration'=>array(
                    'desc'=>__('Enables attachments, regardless of channel'),
                ),
                'validators' => function($self, $value) {
                    if (!ini_get('file_uploads'))
                        $self->addError(__('The "file_uploads" directive is disabled in php.ini'));
                }
            )),
        )
        + $fileupload_config;
    }

    function isAttachmentsEnabled() {
        $config = $this->getConfiguration();
        return $config['attachments'];
    }

    function getWidget($widgetClass=false) {
        if ($hint = $this->getLocal('hint'))
            $this->set('placeholder', Format::striptags($hint));
        $this->set('hint', null);
        $widget = parent::getWidget($widgetClass);
        return $widget;
    }
}


class TopicField extends ChoiceField {
    var $topics;
    var $_choices;

    function getTopics() {
        global $thisstaff;

        if (!isset($this->topics))
            $this->topics = $thisstaff->getTopicNames();

        return $this->topics;
    }

    function getTopic($id) {
        if ($this->getTopics() &&
                isset($this->topics[$id]))
            return Topic::lookup($id);
    }

    function getWidget($widgetClass=false) {
        $default = $this->get('default');
        $widget = parent::getWidget($widgetClass);
        if ($widget->value instanceof Topic)
            $widget->value = $widget->value->getId();
        elseif (!isset($widget->value) && $default)
            $widget->value = $default;
        return $widget;
    }

    function hasIdValue() {
        return true;
    }

    function getChoices($verbose=false, $options=array()) {
        if (!isset($this->_choices)) {
            $this->_choices = $this->getTopics();
        }

        return $this->_choices;
    }

    function parse($id) {
        return $this->to_php(null, $id);
    }

    function to_php($value, $id=false) {
        if ($value instanceof Topic)
            return $value;
        if (is_array($id)) {
            reset($id);
            $id = key($id);
        }
        elseif (is_array($value))
            list($value, $id) = $value;
        elseif ($id === false)
            $id = $value;

        return $this->getTopic($id);
    }

    function to_database($topic) {
        return ($topic instanceof Topic)
            ? array($topic->getName(), $topic->getId())
            : $topic;
    }

    function display($topic, &$styles=null) {
        if (!$topic instanceof Topic)
            return parent::display($topic);

        return Format::htmlchars($topic->getName());
    }

    function toString($value) {
        if (!($value instanceof Topic) && is_numeric($value))
            $value = $this->getTopic($value);

        return ($value instanceof Topic) ? $value->getName() : $value;
    }

    function whatChanged($before, $after) {
        return parent::whatChanged($before, $after);
    }

    function searchable($value) {
        return null;
    }

    function getKeys($value) {
        return ($value instanceof Topic) ? array($value->getId()) : null;
    }

    function asVar($value, $id=false) {
        return $this->to_php($value, $id);
    }

    function getConfiguration() {
        global $cfg;

        $config = parent::getConfiguration();
        if (!isset($config['default']))
            $config['default'] = $cfg->getDefaultTopicId();
        return $config;
    }
}


class SLAField extends ChoiceField {
    var $slas;
    var $_choices;

    function getSLAs() {
        if (!isset($this->slas))
            $this->slas = SLA::objects();

        return $this->slas;
    }

    function getSLA($id) {
        if ($this->getSLAs() &&
                ($s=$this->slas->findFirst(array('id' => $id))))
            return $s;

        return SLA::lookup($id);
    }

    function getWidget($widgetClass=false) {
        $default = $this->get('default');
        $widget = parent::getWidget($widgetClass);
        if ($widget->value instanceof SLA)
            $widget->value = $widget->value->getId();
        elseif (!isset($widget->value) && $default)
            $widget->value = $default;
        return $widget;
    }

    function hasIdValue() {
        return true;
    }

    function getChoices($verbose=false, $options=array()) {
        if (!isset($this->_choices)) {
            $choices = array();
            foreach ($this->getSLAs() as $s)
                $choices[$s->getId()] = $s->getName();
            $this->_choices = $choices;
        }

        return $this->_choices;
    }

    function parse($id) {
        return $this->to_php(null, $id);
    }

    function to_php($value, $id=false) {
        if ($value instanceof SLA)
            return $value;
        if (is_array($id)) {
            reset($id);
            $id = key($id);
        }
        elseif (is_array($value))
            list($value, $id) = $value;
        elseif ($id === false)
            $id = $value;

        return $this->getSLA($id);
    }

    function to_database($sla) {
        return ($sla instanceof SLA)
            ? array($sla->getName(), $sla->getId())
            : $sla;
    }

    function display($sla, &$styles=null) {
        if (!$sla instanceof SLA)
            return parent::display($sla);

        return Format::htmlchars($sla->getName());
    }

    function toString($value) {
        if (!($value instanceof SLA) && is_numeric($value))
            $value = $this->getSLA($value);
        return ($value instanceof SLA) ? $value->getName() : $value;
    }

    function whatChanged($before, $after) {
        return parent::whatChanged($before, $after);
    }

    function searchable($value) {
        return null;
    }

    function getKeys($value) {
        return ($value instanceof SLA) ? array($value->getId()) : null;
    }

    function asVar($value, $id=false) {
        return $this->to_php($value, $id);
    }

    function getConfiguration() {
        global $cfg;

        $config = parent::getConfiguration();
        if (!isset($config['default']))
            $config['default'] = $cfg->getDefaultSLAId();
        return $config;
    }
}


class PriorityField extends ChoiceField {

    var $priorities;
    var $_choices;

    function getPriorities() {
        if (!isset($this->priorities))
            $this->priorities = Priority::objects();

        return $this->priorities;
    }

    function getPriority($id) {

        if ($this->getPriorities() &&
                ($p=$this->priorities->findFirst(array('priority_id' =>
                                                       $id))))
            return $p;

        return Priority::lookup($id);
    }

    function getWidget($widgetClass=false) {
        $widget = parent::getWidget($widgetClass);
        if ($widget->value instanceof Priority)
            $widget->value = $widget->value->getId();
        return $widget;
    }

    function hasIdValue() {
        return true;
    }

    function getChoices($verbose=false, $options=array()) {
        if (!isset($this->_choices)) {
            $choices = array();
            foreach ($this->getPriorities() as $p)
                $choices[$p->getId()] = $p->getDesc();
            $this->_choices = $choices;
        }

        return $this->_choices;
    }

    function parse($id) {
        return $this->to_php(null, $id);
    }

    function to_php($value, $id=false) {
        if ($value instanceof Priority)
            return $value;

        if (is_array($id)) {
            reset($id);
            $id = key($id);
        } elseif (is_array($value)) {
            list($value, $id) = $value;
        } elseif ($id === false && is_numeric($value))
            $id = $value;

        if (is_numeric($id))
            return $this->getPriority($id);

        return $value;
    }

    function to_database($value) {
        if ($value instanceof Priority)
            return array($value->getDesc(), $value->getId());

        if (is_array($value))
            return array(current($value), key($value));

        return $value;
    }

    function display($prio, &$styles=null) {
        if (!$prio instanceof Priority)
            return parent::display($prio);
        if (is_array($styles))
            $styles += array(
                'background-color' => $prio->getColor()
            );
        return Format::htmlchars($prio->getDesc());
    }

    function toString($value) {
        return ($value instanceof Priority) ? $value->getDesc() : $value;
    }

    function whatChanged($before, $after) {
        return parent::whatChanged($before, $after);
    }

    function searchable($value) {
        // Priority isn't searchable this way
        return null;
    }

    function getKeys($value) {
        return ($value instanceof Priority) ? array($value->getId()) : null;
    }

    function asVar($value, $id=false) {
        return $this->to_php($value, $id);
    }

    function getConfigurationOptions() {
        $choices = $this->getChoices();
        return array(
            'prompt' => new TextboxField(array(
                'id'=>2, 'label'=>__('Prompt'), 'required'=>false, 'default'=>'',
                'hint'=>__('Leading text shown before a value is selected'),
                'configuration'=>array('size'=>40, 'length'=>40),
            )),
            'default' => new ChoiceField(array(
                'id'=>3, 'label'=>__('Default'), 'required'=>false, 'default'=>'',
                'choices' => $choices,
                'hint'=>__('Default selection for this field'),
                'configuration'=>array('size'=>20, 'length'=>40),
            )),
        );
    }

    function getConfiguration() {
        global $cfg;

        $config = parent::getConfiguration();
        if (!isset($config['default']))
            $config['default'] = $cfg->getDefaultPriorityId();
        return $config;
    }

    function applyOrderBy($query, $reverse=false, $name=false) {
        if ($query->model == 'Ticket' && $name == 'cdata__priority') {
            // Order by the priority urgency field
            $col = 'cdata__:priority__priority_urgency';
            $reverse = !$reverse;
        }
        else {
            $col = $name ?: CustomQueue::getOrmPath($this->get('name'), $query);
        }
        if ($reverse)
            $col = "-$col";
        return $query->order_by($col);
    }
}



class TimezoneField extends ChoiceField {
    static $widget = 'TimezoneWidget';

    function hasIdValue() {
        return false;
    }

    function getChoices($verbose=false, $options=array()) {
        global $cfg;

        $choices = array();
        foreach (DateTimeZone::listIdentifiers() as $zone)
            $choices[$zone] =  str_replace('/',' / ',$zone);

        return $choices;
    }

    function whatChanged($before, $after) {
        return parent::whatChanged($before, $after);
    }

    function searchable($value) {
        return null;
    }

    function getConfigurationOptions() {
        return array(
            'autodetect' => new BooleanField(array(
                'id'=>1, 'label'=>__('Auto Detect'), 'required'=>false, 'default'=>true,
                'configuration'=>array(
                    'desc'=>__('Add Auto Detect Button'))
            )),
            'prompt' => new TextboxField(array(
                'id'=>2, 'label'=>__('Prompt'), 'required'=>false, 'default'=>'',
                'hint'=>__('Leading text shown before a value is selected'),
                'configuration'=>array('size'=>40, 'length'=>40),
            )),
        );
    }
}



class DepartmentField extends ChoiceField {
    function getWidget($widgetClass=false) {
        $widget = $this->_widget ?: parent::getWidget($widgetClass);
        if (is_object($widget->value))
            $widget->value = $widget->value->getId();
        return $widget;
    }

    function hasIdValue() {
        return true;
    }

    function getChoices($verbose=false, $options=array()) {
        global $cfg, $thisstaff;

        $config = $this->getConfiguration();
        $staff = $config['staff'] ?: $thisstaff;
        $selected = $this->getWidget();
        if($selected && $selected->value) {
          if(is_array($selected->value)) {
            foreach ($selected->value as $k => $v) {
              $current_id = $k;
              $current_name = $v;
            }
          }
          else {
            $current_id = $selected->value;
            $current_name = Dept::getNameById($current_id);
          }
        }

        $choices = array();

        //get all depts unfiltered
        $depts = $config['hideDisabled'] ? Dept::getDepartments(array('activeonly' => true)) :
            Dept::getDepartments(null, true, Dept::DISPLAY_DISABLED);

        //get staff depts based on permissions
        if ($staff) {
            $active = $staff->getDepartmentNames(true);

            if ($staff->hasPerm(Dept::PERM_DEPT))
                return $depts;
        }
        //filter custom department fields when there is no staff
        else {
            $userDepts = Dept::getDepartments(array('publiconly' => true, 'activeonly' => true));

            return $userDepts;
        }

         //add selected dept to list
         if($current_id)
            $active[$current_id] = $current_name;
         else
            return $active;

         foreach ($depts as $id => $name) {
            $choices[$id] = $name;
            if(!array_key_exists($id, $active) && $current_id)
                unset($choices[$id]);
         }

        return $choices;
    }

    function display($dept, &$styles=null) {
        if (!is_numeric($dept) && is_string($dept))
            return Format::htmlchars($dept);
        elseif ($dept instanceof Dept)
            return Format::htmlchars($dept->getName());

        return parent::display($dept);
    }

    function parse($id) {
        return $this->to_php(null, $id);
    }

    function getValue() {
         if (($value = parent::getValue()) && ($id=$this->getClean()))
            return is_array($value) ? $value[$id] : $value;
     }

    function to_php($value, $id=false) {
        if ($id) {
            if (is_array($id)) {
                reset($id);
                $id = key($id);
            }
            return $id;
        } else {
            return $value;
        }
    }

    function to_database($dept) {
        if ($dept instanceof Dept)
            return array($dept->getName(), $dept->getId());

        if (!is_array($dept)) {
            $choices = $this->getChoices();
            if (in_array($dept, $choices)) {
                $deptId = array_search($dept, $choices);
                $dept = array($dept, $deptId);
            }
         }

        return $dept ?: array();
    }

    function toString($value) {
        if (!is_array($value))
            $value = $this->getChoice($value);
        if (is_array($value))
            return implode(', ', $value);
        return (string) $value;
    }

    function getChoice($value) {
        $choices = $this->getChoices();
        $selection = array();
        if ($value && is_array($value)) {
            $selection = $value;
        } elseif (isset($choices[$value])) {
            $selection[] = $choices[$value];
        }

        return $selection;
    }

    function searchable($value) {
        return null;
    }

    function getConfigurationOptions() {
        return array(
            'prompt' => new TextboxField(array(
                'id'=>2, 'label'=>__('Prompt'), 'required'=>false, 'default'=>'',
                'hint'=>__('Leading text shown before a value is selected'),
                'configuration'=>array('size'=>40, 'length'=>40),
            )),
        );
    }
}


class AssigneeField extends ChoiceField {
    var $_choices = null;
    var $_criteria = null;

    function getWidget($widgetClass=false) {
        $widget = parent::getWidget($widgetClass);
        $value = $widget->value;
        if (is_object($value)) {
            $id = $value->getId();
            if ($value instanceof Staff)
                $widget->value = 's'.$id;
            elseif ($value instanceof Team)
                $widget->value = 't'.$id;
        }
        return $widget;
    }

    function getCriteria() {
        if (!isset($this->_criteria)) {
            $this->_criteria = array('available' => true, 'namesOnly' => true);
            if (($c=parent::getCriteria()))
                $this->_criteria = array_merge($this->_criteria, $c);
        }

        return $this->_criteria;
    }

    function hasIdValue() {
        return true;
    }

    function setChoices($choices) {
        $this->_choices = $choices;
    }

    function display($value) {
        if ($this->getAnswer() && is_string($this->getAnswer()->value)) {
            $v = JsonDataParser::parse($this->getAnswer()->value);
            if (is_array($v))
                $value = $v[key($v)];
        }
        return $value;
    }

    function getAssignees($options=array()) {
        global $thisstaff;

        $config = $this->getConfiguration();
        $criteria = $this->getCriteria();
        $dept = $config['dept'] ?: null;
        $staff = $config['staff'] ?: $thisstaff;
        $assignees = array();
        switch (strtolower($config['target'])) {
        case 'agents':
            if ($dept)
                foreach ($dept->getAssignees(array('staff' => $staff)) as $a)
                    $assignees['s'.$a->getId()] = $a;
            else
                foreach (Staff::getStaffMembers(array('staff' => $staff)) as $id => $name)
                    $assignees['s'.$id] = $name;
            break;
        case 'teams':
            foreach (Team::getActiveTeams() ?: array() as $id => $name)
                $assignees['t'.$id] = $name;
            break;
        default:
            // both agents and teams
            $assignees = array(
                    __('Agents') => new ArrayObject(),
                    __('Teams') => new ArrayObject());
            $A = current($assignees);
            $criteria = $this->getCriteria();
            $agents = array();
            if ($dept)
                foreach ($dept->getAssignees(array('staff' => $staff)) as $a)
                    $A['s'.$a->getId()] = $a;
            else
                foreach (Staff::getStaffMembers(array('staff' => $staff)) as $a => $name)
                    $A['s'.$a] = $name;

            next($assignees);
            $T = current($assignees);
            if (($teams = Team::getActiveTeams()))
                foreach ($teams as $id => $name)
                    $T['t'.$id] = $name;
            break;
        }

        return $assignees;
    }

    function getChoices($verbose=false, $options=array()) {
        if (!isset($this->_choices))
            $this->_choices = $this->getAssignees($options);

        return $this->_choices;
    }

    function getChoice($value) {
        $choices = $this->getChoices();
        $selection = array();
        if ($value && is_object($value)) {
            $keys = null;
            if ($value instanceof Staff)
                $keys = array('Agents', 's'.$value->getId());
            elseif ($value instanceof Team)
                $keys = array('Teams', 't'.$value->getId());
            if ($keys && isset($choices[$keys[0]]))
                $selection = $choices[$keys[0]][$keys[1]];

            if (!empty($selection))
                return $selection;
        }

        return parent::getChoice($value);
    }

    function getQuickFilterChoices() {
        $choices = $this->getChoices();
        $names = array();
        foreach ($choices as $value) {
            foreach ($value as $key => $value)
                $names[$key] = is_object($value) ? $value->name : $value;
        }

        return $names;
    }

    function getValue() {
        if (($value = parent::getValue()) && ($id=$this->getClean())) {
            $name = (is_object($value[key($value)]) && get_class($value[key($value)]) == 'AgentsName') ?
                $value[key($value)]->name : $value[key($value)];
            $key = (($value[key($value)] instanceof AgentsName) ? 's' : 't').substr(key($value), 1);
            return array(array($key => $name), substr(key($value), 1));
        } else
            return null;
    }

    function parse($id) {
        return $this->to_php(null, $id);
    }

    function to_php($value, $id=false) {
        if (is_string($value))
            $value = JsonDataParser::parse($value) ?: $value;

        if (is_string($value) && strpos($value, ',')) {
            $values = array();
            list($key, $V) = array_map('trim', explode(',', $value));

            $values[$key] = $V;
            $value = $values;
        }

        $type = '';
        if (is_array($id)) {
            reset($id);
            $id = key($id);
            $type = $id[0];
            $id = substr($id, 1);
        }
        if (is_array($value)) {
            $type = key($value)[0];
            if (!$id)
                $id = substr(key($value), 1);
        }

        if (!$type && is_numeric($value))
            return Staff::lookup($value);

        switch ($type) {
        case 's':
            return Staff::lookup($id);
        case 't':
            return Team::lookup($id);
        case 'd':
            return Dept::lookup($id);
        default:
            return $id;
        }
    }

    function to_database($value) {
        if (is_object($value)) {
            $id = $value->getId();
            if ($value instanceof Staff) {
                $key = 's'.$id;
                $name = $value->getName()->name;
            } elseif ($value instanceof Team) {
                $key = 't'.$id;
                $name = $value->getName();
            }

            return JsonDataEncoder::encode(array($key => $name));
        }
        if (is_array($value)) {
            return JsonDataEncoder::encode($value[0]);
        }
        return $value;
    }

    function toString($value) {
        return (string) $value;
    }

    function searchable($value) {
        return null;
    }

    function getKeys($value) {
        $value = $this->to_database($value);
        if (is_array($value))
            return $value[0];

        return (string) $value;
    }

    function asVar($value, $id=false) {
        $v = $this->to_php($value, $id);
        return $v ? $v->getName() : null;
    }

    function getChanges() {
        $new = $this->to_database($this->getValue());
        $old = $this->to_database($this->answer ? $this->answer->getValue()
                : $this->get('default'));
        // Compare old and new
        return ($old == $new)
            ? false
            : array($old, $new);
    }

    function whatChanged($before, $after) {
        if ($before)
            $before = array($before->getName());
        if ($after)
            $after = array($after->getName());

        return parent::whatChanged($before, $after);
    }

    function getConfigurationOptions() {
        return array(
            'prompt' => new TextboxField(array(
                'id'=>2, 'label'=>__('Prompt'), 'required'=>false, 'default'=>'',
                'hint'=>__('Leading text shown before a value is selected'),
                'configuration'=>array('size'=>40, 'length'=>40),
            )),
        );
    }
}



class TicketStateField extends ChoiceField {

    static $_states = array(
            'open' => array(
                'name' => /* @trans, @context "ticket state name" */ 'Open',
                'verb' => /* @trans, @context "ticket state action" */ 'Open'
                ),
            'closed' => array(
                'name' => /* @trans, @context "ticket state name" */ 'Closed',
                'verb' => /* @trans, @context "ticket state action" */ 'Close'
                )
            );
    // Private states
    static $_privatestates = array(
            'archived' => array(
                'name' => /* @trans, @context "ticket state name" */ 'Archived',
                'verb' => /* @trans, @context "ticket state action" */ 'Archive'
                ),
            'deleted'  => array(
                'name' => /* @trans, @context "ticket state name" */ 'Deleted',
                'verb' => /* @trans, @context "ticket state action" */ 'Delete'
                )
            );

    function hasIdValue() {
        return true;
    }

    function isChangeable() {
        return false;
    }

    function getChoices($verbose=false, $options=array()) {
        static $_choices;

        $states = static::$_states;
        if ($this->options['private_too'])
            $states += static::$_privatestates;

        if (!isset($_choices)) {
            // Translate and cache the choices
            foreach ($states as $k => $v)
                $_choices[$k] =  _P('ticket state name', $v['name']);

            $this->ht['default'] =  '';
        }

        return $_choices;
    }

    function getChoice($state) {

        if ($state && is_array($state))
            $state = key($state);

        if (isset(static::$_states[$state]))
            return _P('ticket state name', static::$_states[$state]['name']);

        if (isset(static::$_privatestates[$state]))
            return _P('ticket state name', static::$_privatestates[$state]['name']);

        return $state;
    }

    function getConfigurationOptions() {
        return array(
            'prompt' => new TextboxField(array(
                'id'=>2, 'label'=> __('Prompt'), 'required'=>false, 'default'=>'',
                'hint'=> __('Leading text shown before a value is selected'),
                'configuration'=>array('size'=>40, 'length'=>40),
            )),
        );
    }

    static function getVerb($state) {

        if (isset(static::$_states[$state]))
            return _P('ticket state action', static::$_states[$state]['verb']);

        if (isset(static::$_privatestates[$state]))
            return _P('ticket state action', static::$_privatestates[$state]['verb']);
    }
}


class TicketFlagField extends ChoiceField {

    // Supported flags (TODO: move to configurable custom list)
    static $_flags = array(
            'onhold' => array(
                'flag' => 1,
                'name' => 'Onhold',
                'states' => array('open'),
                ),
            'overdue' => array(
                'flag' => 2,
                'name' => 'Overdue',
                'states' => array('open'),
                ),
            'answered' => array(
                'flag' => 4,
                'name' => 'Answered',
                'states' => array('open'),
                )
            );

    var $_choices;

    function hasIdValue() {
        return true;
    }

    function isChangeable() {
        return true;
    }

    function getChoices($verbose=false, $options=array()) {
        $this->ht['default'] =  '';

        if (!$this->_choices) {
            foreach (static::$_flags as $k => $v)
                $this->_choices[$k] = $v['name'];
        }

        return $this->_choices;
    }

    function getConfigurationOptions() {
        return array(
            'prompt' => new TextboxField(array(
                'id'=>2, 'label'=>'Prompt', 'required'=>false, 'default'=>'',
                'hint'=>'Leading text shown before a value is selected',
                'configuration'=>array('size'=>40, 'length'=>40),
            )),
        );
    }
}
